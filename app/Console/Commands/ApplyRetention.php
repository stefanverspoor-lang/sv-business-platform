<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Privacy\Retention;
use App\Support\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Throwable;

/** AVG-bewaartermijnen toepassen (dagelijks via crm:tick). */
class ApplyRetention extends Command
{
    protected $signature = 'crm:retention {--dry-run : Alleen tellen, niets wijzigen}';

    protected $description = 'Anonimiseert afgeronde leads en dossiers na de bewaartermijn en ruimt oude bestanden en logs op';

    public function handle(TenantManager $tenants): int
    {
        $retention = app(Retention::class)->dryRun((bool) $this->option('dry-run'));
        $prefix = $this->option('dry-run') ? '[proef] ' : '';

        foreach (Company::where('is_active', true)->get() as $company) {
            try {
                $c = $tenants->runFor($company, fn () => $retention->runForCompany($company));
                $this->line("{$prefix}{$company->slug}: {$c['leads']} leads en {$c['collection_files']} dossiers geanonimiseerd, "
                    ."{$c['import_files']} importbestanden en {$c['report_files']} rapportbestanden verwijderd");
            } catch (Throwable $e) {
                report($e);
                $this->error("{$company->slug}: {$e->getMessage()}");
            }
        }

        $this->line($prefix.'audit-log: '.$retention->purgeAuditLog().' oude regels verwijderd');

        return self::SUCCESS;
    }
}
