<?php

namespace App\Support\Billing;

use App\Enums\PaymentStatus;
use App\Enums\PricingModel;
use App\Models\BillingLine;
use App\Models\CollectionFile;
use App\Models\DataJob;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectRequest;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nog niet gefactureerd werk van een klant op een conceptfactuur zetten:
 * uren (websiteprojecten, verzoeken, data-entry per uur), data-entry per
 * record en commissie uit pre-incasso. Wat op een factuur staat, wordt
 * gemarkeerd zodat het niet nog een keer gefactureerd kan worden.
 */
class BillableWork
{
    /** Factureerbare, nog niet gefactureerde urenregels van de klant in de periode. */
    public function hours(int $clientId, Carbon $from, Carbon $until): Collection
    {
        $projectIds = Project::withTrashed()->where('client_id', $clientId)->pluck('id');
        $requestIds = ProjectRequest::whereIn('project_id', $projectIds)->pluck('id');
        $jobIds = DataJob::withTrashed()->where('client_id', $clientId)->pluck('id');

        return TimeEntry::query()
            ->whereNull('invoice_id')->where('billable', true)
            ->whereBetween('date', [$from->toDateString(), $until->toDateString()])
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('timeable_type', 'project')->whereIn('timeable_id', $projectIds))
                ->orWhere(fn ($p) => $p->where('timeable_type', 'project_request')->whereIn('timeable_id', $requestIds))
                ->orWhere(fn ($p) => $p->where('timeable_type', 'data_job')->whereIn('timeable_id', $jobIds)))
            ->with('timeable')
            ->get();
    }

    /** Wat er klaarstaat, als samenvatting voor het scherm. */
    public function summary(int $clientId, Carbon $from, Carbon $until): array
    {
        $entries = $this->hours($clientId, $from, $until);
        $commission = $this->commissionFiles($clientId, $from, $until);

        return [
            'hours' => round($entries->sum('minutes') / 60, 2),
            'records' => (int) $entries->where('timeable_type', 'data_job')->sum('records_done'),
            'commission_files' => $commission->count(),
            'commission' => round((float) $commission->sum('commission_amount'), 2),
        ];
    }

    /**
     * Zet uren en records op de factuur, één regel per project/opdracht.
     * Data-entry met prijs per record wordt per record gefactureerd, de rest per uur.
     */
    public function addHours(Invoice $invoice, Carbon $from, Carbon $until, ?float $hourlyRate, ?int $vatRateId): int
    {
        $entries = $this->hours($invoice->client_id, $from, $until);
        $period = $from->copy()->locale($invoice->locale)->translatedFormat('j M Y').' – '.$until->copy()->locale($invoice->locale)->translatedFormat('j M Y');
        $en = $invoice->locale === 'en';
        $lines = 0;

        DB::connection('tenant')->transaction(function () use ($invoice, $entries, $hourlyRate, $vatRateId, $period, $en, &$lines) {
            foreach ($entries->groupBy(fn (TimeEntry $e) => $this->groupKey($e)) as $group) {
                /** @var TimeEntry $first */
                $first = $group->first();
                $subject = $first->timeable;
                $job = $subject instanceof DataJob ? $subject->loadMissing('service') : null;
                $service = $job?->service;

                if ($service?->pricing_model === PricingModel::PerRecord && $service->price !== null) {
                    $quantity = (int) $group->sum('records_done');
                    $unit = 'record';
                    $price = (float) $service->price;
                    $label = ($en ? 'Data entry: ' : 'Data-entry: ').$job->name;
                } else {
                    $quantity = round($group->sum('minutes') / 60, 2);
                    $unit = 'uur';
                    $price = $service?->pricing_model === PricingModel::PerHour && $service->price !== null ? (float) $service->price : (float) $hourlyRate;
                    $label = match (true) {
                        $subject instanceof Project => ($en ? 'Website: ' : 'Website: ').$subject->name,
                        $subject instanceof ProjectRequest => ($en ? 'Change/support: ' : 'Wijziging/support: ').$subject->title,
                        $job !== null => ($en ? 'Data entry: ' : 'Data-entry: ').$job->name,
                        default => $en ? 'Work' : 'Werkzaamheden',
                    };
                }
                if ($quantity <= 0) {
                    continue;
                }

                $invoice->lines()->create([
                    'sort' => 100 + $lines,
                    'description' => $label."\n".$period,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unit_price' => $price,
                    'vat_rate_id' => $vatRateId,
                    'source' => ['time_entries' => $group->pluck('id')->all()],
                ]);
                TimeEntry::whereIn('id', $group->pluck('id'))->update(['invoice_id' => $invoice->id]);
                $lines++;
            }
            $invoice->recalculateTotals();
        });

        return $lines;
    }

    protected function groupKey(TimeEntry $e): string
    {
        return $e->timeable_type.':'.$e->timeable_id;
    }

    public function commissionFiles(int $clientId, Carbon $from, Carbon $until): Collection
    {
        return CollectionFile::query()
            ->where('client_id', $clientId)
            ->where('payment_status', PaymentStatus::Paid)
            ->whereNotNull('commission_amount')
            ->whereNull('commission_invoice_id')
            ->whereBetween('paid_at', [$from->toDateString(), $until->toDateString()])
            ->orderBy('file_number')
            ->get();
    }

    /** Eén regel per geslaagd dossier, met dossiernummer en betaald bedrag. */
    public function addCommission(Invoice $invoice, Carbon $from, Carbon $until, ?int $vatRateId): int
    {
        $files = $this->commissionFiles($invoice->client_id, $from, $until);
        $en = $invoice->locale === 'en';

        DB::connection('tenant')->transaction(function () use ($invoice, $files, $vatRateId, $en) {
            foreach ($files->values() as $i => $file) {
                $invoice->lines()->create([
                    'sort' => 200 + $i,
                    'description' => ($en ? 'Commission file ' : 'Commissie dossier ').$file->file_number.' ('.$file->debtor_name.')'
                        ."\n".($en ? 'Paid: ' : 'Betaald: ').Money::format($file->paid_amount, $file->currency, $invoice->locale)
                        .($file->paid_at ? ' – '.$file->paid_at->format('d-m-Y') : ''),
                    'quantity' => 1,
                    'unit' => 'dossier',
                    'unit_price' => $file->commission_amount,
                    'vat_rate_id' => $vatRateId,
                    'source' => ['collection_files' => [$file->id]],
                ]);
            }
            CollectionFile::whereIn('id', $files->pluck('id'))->update(['commission_invoice_id' => $invoice->id]);
            $invoice->recalculateTotals();
        });

        return $files->count();
    }

    /** Regel verwijderd van een concept: het werk komt weer vrij. */
    public static function release(BillingLine $line): void
    {
        $source = $line->source ?? [];
        if (! empty($source['time_entries'])) {
            TimeEntry::whereIn('id', $source['time_entries'])->update(['invoice_id' => null]);
        }
        if (! empty($source['collection_files'])) {
            CollectionFile::whereIn('id', $source['collection_files'])->update(['commission_invoice_id' => null]);
        }
    }
}
