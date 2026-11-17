<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Voert de migraties van de bedrijfsdatabases uit op SV en YES.
 * Zo krijgen beide databases altijd precies dezelfde structuur.
 */
class MigrateCompanies extends Command
{
    protected $signature = 'crm:migrate-companies {--fresh : Alle tabellen in de bedrijfsdatabases opnieuw aanmaken (wist alle gegevens)} {--force : Ook in productie uitvoeren zonder vraag}';

    protected $description = 'Migraties uitvoeren op de database van elk bedrijf';

    public function handle(): int
    {
        foreach (config('crm.companies') as $company) {
            $this->components->info("Bedrijfsdatabase {$company['name']} ({$company['connection']})");

            $exit = Artisan::call($this->option('fresh') ? 'migrate:fresh' : 'migrate', [
                '--database' => $company['connection'],
                '--path' => 'database/migrations/company',
                '--force' => $this->option('force') || ! app()->isProduction(),
            ], $this->output);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }
        }

        return self::SUCCESS;
    }
}
