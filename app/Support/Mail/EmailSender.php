<?php

namespace App\Support\Mail;

use App\Models\Client;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Verstuurt e-mail vanuit het CRM via de SMTP-server van de mailbox, legt het
 * bericht vast en zet een kopie in de map Verzonden, zodat Hostinger Webmail
 * hetzelfde laat zien.
 */
class EmailSender
{
    public function __construct(
        protected MessageStore $store,
        protected MailboxConnector $connector,
    ) {}

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $attachmentPaths  paden op de lokale schijf
     * @param  array<int, Client|Contact>  $linkTo
     */
    public function send(
        Mailbox $mailbox,
        array $to,
        string $subject,
        string $body,
        array $cc = [],
        ?EmailMessage $replyTo = null,
        array $attachmentPaths = [],
        array $linkTo = [],
        bool $withSignature = true,
        ?string $quote = null,
    ): EmailMessage {
        $signature = $withSignature ? Signature::for($mailbox) : null;
        $wrap = fn (string $inner) => '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222">'.$inner.'</div>';
        $quoteHtml = $quote !== null && trim($quote) !== ''
            ? '<blockquote style="margin:16px 0 0 0;padding-left:10px;border-left:2px solid #ccc;color:#555">'.nl2br(e($quote)).'</blockquote>' : '';
        $text = rtrim($body).($signature ? "\n\n-- \n".$signature->text : '').($quoteHtml ? "\n\n".$quote : '');
        $html = $wrap(nl2br(e(rtrim($body))).($signature?->toHtml() ?? '').$quoteHtml);
        $storedHtml = $wrap(nl2br(e(rtrim($body))).($signature?->toHtml(forStorage: true) ?? '').$quoteHtml);

        $domain = Str::after($mailbox->email, '@');
        $messageId = Str::uuid()->toString().'@'.$domain;

        $email = (new Email)
            ->from(new Address($mailbox->email, $mailbox->name))
            ->to(...$to)
            ->subject($subject)
            ->text($text)
            ->html($html);
        if ($signature?->logoPath) {
            $email->embed(Storage::disk('local')->get($signature->logoPath), Signature::LOGO_CID, $signature->logoMime);
        }
        if ($cc) {
            $email->cc(...$cc);
        }
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        if ($replyTo?->message_id) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $replyTo->message_id);
            $email->getHeaders()->addIdHeader('References', $replyTo->message_id);
        }
        foreach ($attachmentPaths as $name => $path) {
            $email->attach(Storage::disk('local')->get($path), is_string($name) ? $name : basename($path));
        }

        $transport = new EsmtpTransport(
            $mailbox->smtp_host,
            $mailbox->smtp_port,
            $mailbox->smtp_encryption === 'ssl' ? true : null,
        );
        if ($mailbox->smtp_encryption === 'none') {
            $transport->setAutoTls(false);
            $transport->setRequireTls(false);
        }
        $transport->setUsername($mailbox->username);
        $transport->setPassword($mailbox->password);

        $sent = $transport->send($email);

        $stored = $this->store->storeSent($mailbox, [
            'message_id' => $messageId,
            'in_reply_to' => $replyTo?->message_id,
            'subject' => $subject,
            'from_email' => strtolower($mailbox->email),
            'from_name' => $mailbox->name,
            'to' => array_map(fn ($a) => ['email' => strtolower($a), 'name' => null], $to),
            'cc' => array_map(fn ($a) => ['email' => strtolower($a), 'name' => null], $cc),
            'body_text' => $text,
            'body_html' => HtmlCleaner::clean($storedHtml),
            'sent_by' => auth()->id(),
            'has_attachments' => (bool) $attachmentPaths,
        ]);
        if ($replyTo) {
            $stored->forceFill(['thread_key' => $replyTo->thread_key])->save();
        }

        foreach ($attachmentPaths as $name => $path) {
            $stored->attachments()->create([
                'filename' => is_string($name) ? $name : basename($path),
                'path' => $path,
                'mime_type' => Storage::disk('local')->mimeType($path) ?: null,
                'size' => Storage::disk('local')->size($path),
            ]);
        }

        foreach ($linkTo as $target) {
            $this->store->link($stored, $target);
        }
        $this->store->autoLink($stored, $mailbox);
        Audit::log('email_sent', $stored, ['to' => $to, 'subject' => $subject]);

        $this->appendToSentFolder($mailbox, $sent?->toString());

        return $stored;
    }

    /** Kopie in Verzonden; mislukt dit, dan is de mail wel verstuurd. */
    protected function appendToSentFolder(Mailbox $mailbox, ?string $raw): void
    {
        if (! $raw) {
            return;
        }

        try {
            $client = $this->connector->connect($mailbox);
            $folder = $client->getFolderByPath($mailbox->sent_folder ?: 'Sent', soft_fail: true);
            $folder?->appendMessage($raw, ['\\Seen']);
            $client->disconnect();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
