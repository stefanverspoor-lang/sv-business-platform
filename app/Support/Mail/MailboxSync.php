<?php

namespace App\Support\Mail;

use App\Models\Mailbox;
use Illuminate\Support\Arr;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Haalt nieuwe berichten op uit een mailbox (Postvak IN en Verzonden).
 *
 * Werkt per map met de IMAP-UID: alleen berichten met een hogere UID dan de
 * vorige keer worden opgehaald. Per run een beperkt aantal, zodat de cron-taak
 * binnen de tijd blijft. Berichten worden niet als gelezen gemarkeerd in de
 * mailbox zelf.
 */
class MailboxSync
{
    public const PER_RUN = 25;

    /** @var array<int, string> berichten die niet konden worden opgeslagen */
    protected array $skipped = [];

    public function __construct(
        protected MailboxConnector $connector,
        protected MessageStore $store,
    ) {}

    /** @return int aantal nieuwe berichten */
    public function sync(Mailbox $mailbox): int
    {
        $count = 0;
        $this->skipped = [];

        try {
            $client = $this->connector->connect($mailbox);

            $folders = ['INBOX' => 'in'];
            $sent = $this->sentFolder($client, $mailbox);
            if ($sent) {
                $folders[$sent] = 'out';
            }

            foreach ($folders as $path => $direction) {
                $count += $this->syncFolder($client, $mailbox, $path, $direction);
            }

            $mailbox->forceFill([
                'last_synced_at' => now(),
                'last_error' => $this->skipped ? mb_substr(__('Overgeslagen berichten: :list', ['list' => implode('; ', $this->skipped)]), 0, 1000) : null,
            ])->save();
            $client->disconnect();
        } catch (Throwable $e) {
            report($e);
            $mailbox->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
        }

        return $count;
    }

    protected function syncFolder(Client $client, Mailbox $mailbox, string $path, string $direction): int
    {
        $folder = $client->getFolderByPath($path, soft_fail: true);
        if (! $folder) {
            return 0;
        }

        $state = $mailbox->sync_state ?? [];
        $last = (int) Arr::get($state, $path.'.last_uid', 0);
        $connection = $client->getConnection();
        $connection->selectFolder($folder->path);

        if ($last === 0) {
            // Eerste keer: vanaf de ingestelde datum (standaard 30 dagen terug).
            $since = ($mailbox->sync_since ?? now()->subDays(30))->format('d-M-Y');
            $uids = $connection->search(['SINCE', $since], IMAP::ST_UID)->validatedData();
        } else {
            $uids = $connection->search(['UID', ($last + 1).':*'], IMAP::ST_UID)->validatedData();
        }

        $uids = collect($uids)->map(fn ($u) => (int) $u)->filter(fn ($u) => $u > $last)->sort()->values();
        $new = 0;

        foreach ($uids->take(self::PER_RUN) as $uid) {
            try {
                $message = $folder->query()->getMessageByUid($uid);
                if ($message instanceof Message && $this->store->storeImapMessage($mailbox, $path, $direction, $uid, $message)) {
                    $new++;
                }
            } catch (Throwable $e) {
                // Eén onleesbaar bericht mag de rest van de mailbox niet blokkeren.
                report($e);
                $this->skipped[] = "{$path} #{$uid}: ".mb_substr($e->getMessage(), 0, 200);
            }
            Arr::set($state, $path.'.last_uid', $uid);
            $mailbox->forceFill(['sync_state' => $state])->save();
        }

        return $new;
    }

    /** Zoekt de map met verzonden berichten (naam verschilt per mailserver). */
    protected function sentFolder(Client $client, Mailbox $mailbox): ?string
    {
        if ($mailbox->sent_folder && $client->getFolderByPath($mailbox->sent_folder, soft_fail: true)) {
            return $mailbox->sent_folder;
        }

        /** @var Folder|null $found */
        $found = $client->getFolders(false, null, true)->first(
            fn (Folder $f) => preg_match('/(^|[.\/])(sent|sent items|sent messages|verzonden|verzonden items)$/i', $f->path)
        );

        if ($found) {
            $mailbox->forceFill(['sent_folder' => $found->path])->save();

            return $found->path;
        }

        return null;
    }
}
