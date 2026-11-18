<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Zet de bedrijfsgegevens terug naar nul (na het testen): klanten, leads,
 * dossiers, projecten, uren, offertes, facturen, uitgaven enz. worden gewist
 * en de factuurnummering begint opnieuw.
 *
 * Blijft staan: gebruikers en rollen, bedrijfsgegevens, mailboxen (met
 * wachtwoorden en handtekening), e-mailsjablonen, gespreksresultaten,
 * btw-tarieven, bankrekeningen, categorieën, wisselkoersen en instellingen.
 * Ontvangen en verzonden e-mail blijft ook staan (alleen de koppelingen met
 * klanten verdwijnen), tenzij --met-emails.
 */
class ResetBusinessData extends Command
{
    protected $signature = 'crm:reset-data
        {--bedrijf= : Alleen dit bedrijf (sv of yes); standaard alle bedrijven}
        {--met-emails : Ook de e-mails in het CRM wissen (blijven in de mailbox zelf staan)}
        {--force : Niet om bevestiging vragen}
        {--zonder-backup : Geen back-up vooraf (alleen voor tests)}';

    protected $description = 'Wist alle klant-, bel-, project- en factuurgegevens (testgegevens) maar houdt instellingen en mailboxen';

    /** Tabellen met bedrijfsgegevens, in volgorde van afhankelijkheid. */
    protected const DATA_TABLES = [
        'billing_lines', 'invoice_payments', 'invoices', 'quotes', 'recurring_invoices', 'number_sequences',
        'expenses', 'incomes',
        'time_entries', 'data_job_user', 'data_jobs', 'project_requests', 'project_hostings', 'project_domains', 'projects',
        'agreement_terms', 'payment_results', 'payment_agreements', 'call_attempts', 'collection_files', 'leads', 'campaigns',
        'commission_models', 'data_imports',
        'email_links', 'tasks', 'documents', 'activities', 'client_services', 'contracts', 'access_grants', 'contacts', 'clients',
    ];

    /** Mappen met bestanden die bij die gegevens horen. */
    protected const DATA_FOLDERS = ['invoices', 'quotes', 'imports', 'reports', 'documents'];

    public function handle(TenantManager $tenants): int
    {
        $companies = Company::query()->when($this->option('bedrijf'), fn ($q, $slug) => $q->where('slug', $slug))->get();
        if ($companies->isEmpty()) {
            $this->error('Geen bedrijf gevonden.');

            return self::FAILURE;
        }

        $this->warn('Dit wist ALLE klanten, leads, dossiers, projecten, uren, offertes, facturen, uitgaven en inkomsten van: '.$companies->pluck('name')->implode(', ').'.');
        $this->line('Blijft staan: gebruikers, mailboxen, sjablonen, btw-tarieven, bankrekeningen, instellingen'.($this->option('met-emails') ? '.' : ' en de e-mails.'));
        if (! $this->option('force') && $this->ask('Typ WISSEN om door te gaan') !== 'WISSEN') {
            $this->info('Afgebroken, er is niets gewist.');

            return self::FAILURE;
        }

        if (! $this->option('zonder-backup')) {
            $this->info('Eerst een back-up ...');
            if (Artisan::call('crm:backup', [], $this->output) !== self::SUCCESS) {
                $this->error('Back-up mislukt; er is niets gewist.');

                return self::FAILURE;
            }
        }

        $tables = self::DATA_TABLES;
        if ($this->option('met-emails')) {
            array_unshift($tables, 'email_attachments', 'email_messages');
        }

        foreach ($companies as $company) {
            $tenants->runFor($company, function () use ($company, $tables) {
                $connection = DB::connection('tenant');
                $schema = Schema::connection('tenant');
                $schema->disableForeignKeyConstraints();
                try {
                    foreach ($tables as $table) {
                        if ($schema->hasTable($table)) {
                            $connection->table($table)->truncate();
                        }
                    }
                } finally {
                    $schema->enableForeignKeyConstraints();
                }

                $folders = self::DATA_FOLDERS;
                if ($this->option('met-emails')) {
                    $folders[] = 'mail';
                }
                foreach ($folders as $folder) {
                    Storage::disk('local')->deleteDirectory("companies/{$company->slug}/{$folder}");
                }

                $this->info("{$company->name}: gewist.");
            });
        }

        if (! $this->option('bedrijf')) {
            DB::connection('central')->table('notifications')->delete();
        }
        Cache::flush();

        $this->info('Klaar. Factuurnummers beginnen weer bij 0001.');

        return self::SUCCESS;
    }
}
