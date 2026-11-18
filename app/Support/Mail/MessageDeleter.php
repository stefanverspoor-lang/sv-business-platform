<?php

namespace App\Support\Mail;

use App\Models\Document;
use App\Models\EmailMessage;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verwijdert e-mail uit het CRM en (optioneel) ook uit de mailbox op de
 * mailserver: daar gaat het bericht naar de prullenbak, of wordt het
 * verwijderd als er geen prullenbak is.
 */
class MessageDeleter
{
    public function __construct(protected MailboxConnector $connector) {}

    /**
     * @param  iterable<EmailMessage>  $messages
     * @return array{deleted: int, server_failed: int}
     */
    public function delete(iterable $messages, bool $fromServer = true): array
    {
        $deleted = 0;
        $serverFailed = 0;
        $byMailbox = collect($messages)->groupBy('mailbox_id');

        foreach ($byMailbox as $group) {
            if ($fromServer && ! $this->deleteOnServer($group)) {
                $serverFailed += $group->count();
            }
            foreach ($group as $message) {
                $this->deleteLocal($message);
                $deleted++;
            }
        }

        return ['deleted' => $deleted, 'server_failed' => $serverFailed];
    }

    protected function deleteOnServer($group): bool
    {
        $first = $group->first();
        $mailbox = $first->mailbox;
        $withUid = $group->filter(fn (EmailMessage $m) => $m->uid);
        if (! $mailbox || $withUid->isEmpty()) {
            return true;
        }

        try {
            $client = $this->connector->connect($mailbox);
            $trash = MailboxSync::trashFolder($client);

            foreach ($withUid->groupBy('folder') as $path => $messages) {
                $folder = $client->getFolderByPath($path, soft_fail: true);
                // Staat het al in de prullenbak, dan definitief verwijderen.
                foreach ($messages as $message) {
                    $imap = $folder?->query()->getMessageByUid((int) $message->uid);
                    if (! $imap) {
                        continue;
                    }
                    if ($trash && $path !== $trash) {
                        $imap->move($trash);
                    } else {
                        $imap->delete(true);
                    }
                }
            }
            $client->disconnect();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function deleteLocal(EmailMessage $message): void
    {
        foreach ($message->attachments as $attachment) {
            $path = $attachment->path;
            $shared = \App\Models\EmailAttachment::where('path', $path)->whereKeyNot($attachment->id)->exists()
                || Document::where('path', $path)->exists();
            // Alleen bestanden uit de mailmap; facturen en offertes blijven altijd staan.
            if (str_contains($path, '/mail/') && ! $shared) {
                Storage::disk('local')->delete($path);
            }
        }

        Audit::log('email_deleted', $message, ['subject' => $message->subject, 'from' => $message->from_email]);
        $message->delete();
    }
}
