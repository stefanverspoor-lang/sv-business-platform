<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Mailbox;
use App\Support\Mail\MailboxSync;
use App\Support\TaskReminders;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Wordt elke minuut door de cron-taak van Hostinger gestart.
 *
 * Doet het werk van de Laravel-planner, maar zonder andere programma's te
 * starten (Hostinger staat proc_open/exec niet toe):
 *  - herinneringen voor taken versturen (elke minuut);
 *  - nieuwe e-mail ophalen (elke 5 minuten, per bedrijf);
 *  - taken in de wachtrij afhandelen;
 *  - eens per dag (na 02:00 Nederlandse tijd) een back-up maken en opruimen.
 */
class Tick extends Command
{
    protected $signature = 'crm:tick';

    protected $description = 'Periodieke taken uitvoeren (aangeroepen door de cron-taak)';

    public function handle(): int
    {
        $lock = Cache::lock('crm:tick', 55);
        if (! $lock->get()) {
            return self::SUCCESS; // vorige run is nog bezig
        }

        try {
            $this->runDaily();
            $this->perCompany();

            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => 40,
                '--tries' => 3,
            ], $this->output);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    protected function perCompany(): void
    {
        $syncMail = ! Cache::has('crm:mail_synced');
        if ($syncMail) {
            Cache::put('crm:mail_synced', true, now()->addMinutes(4));
        }

        $tenants = app(TenantManager::class);
        foreach (Company::where('is_active', true)->get() as $company) {
            $tenants->runFor($company, function () use ($company, $syncMail) {
                try {
                    app(TaskReminders::class)->send($company);
                    app(\App\Support\DomainExpiryReminders::class)->send($company);

                    if ($syncMail) {
                        foreach (Mailbox::where('is_active', true)->get() as $mailbox) {
                            $new = app(MailboxSync::class)->sync($mailbox);
                            if ($new) {
                                $this->line("  {$company->slug}/{$mailbox->email}: {$new} nieuw");
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }

    protected function runDaily(): void
    {
        $now = now(config('crm.fallback_timezone'));
        $today = $now->toDateString();

        if ($now->hour < 2 || Cache::get('crm:daily_done') === $today) {
            return;
        }

        Cache::forever('crm:daily_done', $today);

        Artisan::call('crm:backup', [], $this->output);
        Artisan::call('auth:clear-resets', [], $this->output);
        Artisan::call('crm:retention', [], $this->output);

        // Terugkerende facturen (na de back-up)
        $tenants = app(TenantManager::class);
        foreach (Company::where('is_active', true)->get() as $company) {
            $tenants->runFor($company, function () use ($company) {
                try {
                    $made = app(\App\Support\Billing\RecurringInvoiceRunner::class)->run($company);
                    if ($made) {
                        $this->line("  {$company->slug}: {$made} terugkerende factuur/facturen gemaakt");
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }
}
