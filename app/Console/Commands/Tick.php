<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Wordt elke minuut door de cron-taak van Hostinger gestart.
 *
 * Doet het werk van de Laravel-planner, maar zonder andere programma's te
 * starten (Hostinger staat proc_open/exec niet toe):
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
    }
}
