<?php

namespace App\Support\Privacy;

use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CollectionFile;
use App\Models\DataImport;
use App\Models\Lead;
use Illuminate\Support\Facades\Storage;

/**
 * AVG-bewaartermijnen (config/crm.php → retention). Afgeronde leads en
 * dossiers worden na de termijn geanonimiseerd, importbestanden en
 * rapport-exports verwijderd, en oude audit-regels opgeruimd.
 */
class Retention
{
    public function __construct(protected Anonymizer $anonymizer, protected bool $dryRun = false) {}

    public function dryRun(bool $dry = true): static
    {
        $this->dryRun = $dry;

        return $this;
    }

    /** Per bedrijf (binnen TenantManager::runFor). @return array<string, int> */
    public function runForCompany(Company $company): array
    {
        $cfg = config('crm.retention');
        $counts = ['leads' => 0, 'collection_files' => 0, 'import_files' => 0, 'report_files' => 0];

        if ($months = (int) ($cfg['leads_months'] ?? 0)) {
            $query = Lead::withTrashed()->whereNull('anonymized_at')
                ->whereNotIn('status', array_map(fn ($s) => $s->value, LeadStatus::open()))
                ->where('updated_at', '<', now()->subMonths($months));
            $counts['leads'] = $this->each($query, fn (Lead $l) => $this->anonymizer->lead($l, 'retention'));
        }

        if ($months = (int) ($cfg['collection_months'] ?? 0)) {
            $query = CollectionFile::withTrashed()->whereNull('anonymized_at')
                ->whereNotIn('status', array_map(fn ($s) => $s->value, CollectionStatus::open()))
                ->where(fn ($q) => $q->where('closed_at', '<', now()->subMonths($months))
                    ->orWhere(fn ($q) => $q->whereNull('closed_at')->where('updated_at', '<', now()->subMonths($months))));
            $counts['collection_files'] = $this->each($query, fn (CollectionFile $f) => $this->anonymizer->collectionFile($f, 'retention'));
        }

        if ($days = (int) ($cfg['import_files_days'] ?? 0)) {
            DataImport::query()->where('created_at', '<', now()->subDays($days))->where('path', '!=', '')
                ->chunkById(200, function ($imports) use (&$counts) {
                    foreach ($imports as $import) {
                        $counts['import_files']++;
                        if (! $this->dryRun) {
                            Storage::disk('local')->delete($import->path);
                            $import->forceFill(['path' => ''])->saveQuietly();
                        }
                    }
                });
        }

        if ($days = (int) ($cfg['report_files_days'] ?? 0)) {
            $counts['report_files'] = $this->purgeFolder("companies/{$company->slug}/reports", $days);
        }

        return $counts;
    }

    /** Centrale audit-log inkorten. */
    public function purgeAuditLog(): int
    {
        $months = (int) config('crm.retention.audit_log_months', 0);
        if (! $months) {
            return 0;
        }
        $query = AuditLog::query()->where('created_at', '<', now()->subMonths($months));

        return $this->dryRun ? $query->count() : $query->delete();
    }

    protected function each($query, callable $callback): int
    {
        if ($this->dryRun) {
            return $query->count();
        }
        $n = 0;
        $query->chunkById(200, function ($records) use ($callback, &$n) {
            foreach ($records as $record) {
                $callback($record);
                $n++;
            }
        });

        return $n;
    }

    protected function purgeFolder(string $folder, int $days): int
    {
        $disk = Storage::disk('local');
        $limit = now()->subDays($days)->getTimestamp();
        $n = 0;
        foreach ($disk->allFiles($folder) as $file) {
            if ($disk->lastModified($file) < $limit) {
                $n++;
                if (! $this->dryRun) {
                    $disk->delete($file);
                }
            }
        }

        return $n;
    }
}
