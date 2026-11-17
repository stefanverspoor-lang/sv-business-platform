<?php

namespace App\Support\Mail;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\Lead;
use App\Models\Contact;
use App\Models\EmailLink;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Message;

/**
 * Slaat e-mails op, bewaart bijlagen en koppelt berichten automatisch aan
 * klanten en contactpersonen op basis van e-mailadres.
 */
class MessageStore
{
    public const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    public function storeImapMessage(Mailbox $mailbox, string $folder, string $direction, int $uid, Message $message): ?EmailMessage
    {
        $messageId = $this->clean((string) $message->getMessageId());

        // Zelf verstuurd vanuit het CRM: alleen de UID aanvullen.
        if ($messageId && $existing = EmailMessage::where('mailbox_id', $mailbox->id)->where('message_id', $messageId)->whereNull('uid')->first()) {
            $existing->update(['uid' => $uid, 'folder' => $folder]);

            return null;
        }

        if (EmailMessage::where(['mailbox_id' => $mailbox->id, 'folder' => $folder, 'uid' => $uid])->exists()) {
            return null;
        }

        $from = $message->getFrom()->first();
        $to = $this->addresses($message->getTo());
        $cc = $this->addresses($message->getCc());
        $subject = $this->decodeHeader((string) $message->getSubject());
        $inReplyTo = $this->clean((string) $message->getInReplyTo());

        try {
            $date = Carbon::instance($message->getDate()->toDate())->utc();
        } catch (Throwable) {
            $date = null;
        }
        // Geen of onzinnige datum in de mail (komt voor): dan het moment van ophalen.
        if (! $date || $date->year < 2000 || $date->isAfter(now()->addDay())) {
            $date = now();
        }

        $html = $message->hasHTMLBody() ? HtmlCleaner::clean($message->getHTMLBody()) : null;
        $text = $message->hasTextBody() ? $message->getTextBody() : null;
        if ($text === null && $html !== null) {
            $text = trim(html_entity_decode(strip_tags($html)));
        }

        $email = new EmailMessage([
            'mailbox_id' => $mailbox->id,
            'folder' => $folder,
            'uid' => $uid,
            'direction' => $direction,
            'message_id' => $messageId ?: null,
            'in_reply_to' => $inReplyTo ?: null,
            'subject' => mb_substr($subject, 0, 500),
            'from_email' => $from?->mail ? strtolower($from->mail) : null,
            'from_name' => $from?->personal ? $this->decodeHeader($from->personal) : null,
            'to' => $to,
            'cc' => $cc,
            'sent_at' => $date,
            'body_text' => $text,
            'body_html' => $html,
            'is_read' => $direction === 'out',
        ]);
        $email->thread_key = $this->threadKey($email);
        $email->save();

        $this->storeAttachments($email, $message);
        $this->autoLink($email, $mailbox);

        return $email;
    }

    /** Legt een vanuit het CRM verstuurde e-mail vast. */
    public function storeSent(Mailbox $mailbox, array $data): EmailMessage
    {
        $email = new EmailMessage($data + [
            'mailbox_id' => $mailbox->id,
            'folder' => $mailbox->sent_folder ?: 'Sent',
            'direction' => 'out',
            'is_read' => true,
            'sent_at' => now(),
        ]);
        $email->thread_key = $this->threadKey($email);
        $email->save();

        return $email;
    }

    public function link(EmailMessage $email, Model $target, bool $automatic = false): void
    {
        $link = EmailLink::firstOrCreate([
            'email_message_id' => $email->id,
            'linkable_type' => $target->getMorphClass(),
            'linkable_id' => $target->getKey(),
        ], ['is_automatic' => $automatic, 'linked_by' => $automatic ? null : auth()->id()]);

        if ($link->wasRecentlyCreated && $target instanceof Client) {
            $client = $target;
            Activity::record(
                $client,
                $email->direction === 'in' ? ActivityType::EmailIn : ActivityType::EmailOut,
                $email->subject ?: __('(geen onderwerp)'),
                Str::limit((string) $email->body_text, 300),
                $email,
                $email->sent_by,
                $email->sent_at,
            );
        }
    }

    public function autoLink(EmailMessage $email, Mailbox $mailbox): void
    {
        $addresses = array_diff($email->counterpartyAddresses(), [strtolower($mailbox->email)]);
        if (! $addresses) {
            $this->assignDefault($email, $mailbox, null);

            return;
        }

        $clients = collect();
        foreach (Contact::whereIn('email', $addresses)->with('client')->get() as $contact) {
            $this->link($email, $contact, true);
            if ($contact->client) {
                $clients->put($contact->client->id, $contact->client);
            }
        }
        foreach (Client::whereIn('email', $addresses)->get() as $client) {
            $clients->put($client->id, $client);
        }
        foreach ($clients as $client) {
            $this->link($email, $client, true);
        }
        // Debiteuren en leads met dit e-mailadres (pre-incasso en telemarketing)
        foreach ([CollectionFile::class, Lead::class] as $model) {
            foreach ($model::query()->whereIn('email', $addresses)->limit(20)->get() as $record) {
                $this->link($email, $record, true);
            }
        }

        $this->assignDefault($email, $mailbox, $clients->first());
    }

    protected function assignDefault(EmailMessage $email, Mailbox $mailbox, ?Client $client): void
    {
        if ($email->direction !== 'in' || $email->assigned_to) {
            return;
        }

        $assignee = $client?->account_manager_id ?? $mailbox->default_assignee;
        if ($assignee) {
            $email->forceFill(['assigned_to' => $assignee])->save();
        }
    }

    protected function threadKey(EmailMessage $email): string
    {
        if ($email->in_reply_to) {
            $parent = EmailMessage::where('mailbox_id', $email->mailbox_id)->where('message_id', $email->in_reply_to)->value('thread_key');
            if ($parent) {
                return $parent;
            }
        }

        return EmailMessage::threadKeyFor($email->subject, $email->counterpartyAddresses());
    }

    protected function storeAttachments(EmailMessage $email, Message $message): void
    {
        $slug = app(TenantManager::class)->current()?->slug ?? 'unknown';
        $count = 0;

        foreach ($message->getAttachments() as $attachment) {
            $content = $attachment->getContent();
            if ($content === null || strlen($content) > self::MAX_ATTACHMENT_BYTES) {
                continue;
            }

            $name = $this->safeFilename($this->decodeHeader((string) ($attachment->getName() ?: 'bijlage')));
            $path = "companies/{$slug}/mail/".now()->format('Y/m').'/'.Str::random(16).'_'.$name;
            Storage::disk('local')->put($path, $content);

            $email->attachments()->create([
                'filename' => $name,
                'path' => $path,
                'mime_type' => mb_substr((string) $attachment->getMimeType(), 0, 120) ?: null,
                'size' => strlen($content),
            ]);
            $count++;
        }

        if ($count) {
            $email->forceFill(['has_attachments' => true])->save();
        }
    }

    /** @return array<int, array{email: string, name: ?string}> */
    protected function addresses($attribute): array
    {
        $list = [];
        foreach ($attribute?->all() ?? [] as $address) {
            if (! empty($address->mail)) {
                $list[] = ['email' => strtolower($address->mail), 'name' => $address->personal ? $this->decodeHeader($address->personal) : null];
            }
        }

        return $list;
    }

    protected function decodeHeader(string $value): string
    {
        $decoded = str_contains($value, '=?') ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $value;

        return trim($decoded !== false ? $decoded : $value);
    }

    protected function clean(string $value): string
    {
        return trim($value, " \t\n\r<>");
    }

    protected function safeFilename(string $name): string
    {
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'bijlage';

        return mb_substr($name, -150);
    }
}
