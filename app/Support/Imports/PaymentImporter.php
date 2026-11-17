<?php

namespace App\Support\Imports;

use App\Enums\CollectionStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\CommissionModel;
use App\Models\DataImport;
use App\Models\PaymentResult;
use Illuminate\Support\Carbon;

/**
 * Betaalimport: de opdrachtgever meldt welke dossiers betaald zijn. Pas dan
 * telt een dossier als geslaagd (volledig afgesproken bedrag betaald) en
 * wordt de commissie berekend. Deelbetalingen tellen niet als geslaagd.
 */
class PaymentImporter extends Importer
{
    /** @var array<int, true> dossiers die in dit bestand voorkomen */
    protected array $touched = [];

    public function __construct(protected Client $client, protected bool $markMissingUnpaid = false) {}

    protected function type(): string
    {
        return DataImport::TYPE_PAYMENTS;
    }

    public static function columns(): array
    {
        return [
            'file_number' => ['Dossiernummer', 'Dossier', 'Dossier nr', 'Dossiernr', 'Referentie', 'File number', 'Case number'],
            'paid_amount' => ['Betaald bedrag', 'Betaald', 'Ontvangen bedrag', 'Bedrag', 'Paid amount', 'Paid', 'Amount'],
            'paid_at' => ['Betaaldatum', 'Datum betaling', 'Datum', 'Payment date', 'Paid date', 'Date'],
            'reference' => ['Kenmerk', 'Omschrijving', 'Betalingskenmerk', 'Payment reference', 'Description'],
        ];
    }

    public static function required(): array
    {
        return ['file_number', 'paid_amount'];
    }

    public static function template(): array
    {
        return [
            ['Dossiernummer', 'Betaald bedrag', 'Betaaldatum', 'Kenmerk'],
            ['D-2026-0001', '250,00', '15-11-2026', 'Volledig betaald'],
        ];
    }

    protected function importRow(array $data, int $line, DataImport $import): void
    {
        $number = Spreadsheet::text($data['file_number']);
        $file = CollectionFile::where('client_id', $this->client->id)->where('file_number', $number)->first();
        if (! $file) {
            $this->fail($line, __('dossier :number niet gevonden bij deze opdrachtgever', ['number' => $number]));

            return;
        }

        $amount = Spreadsheet::amount($data['paid_amount']);
        if ($amount === null || $amount < 0) {
            $this->fail($line, __('betaald bedrag is ongeldig'));

            return;
        }
        $paidAt = Spreadsheet::date($data['paid_at'] ?? null);
        $reference = Spreadsheet::text($data['reference'] ?? null);

        // Hetzelfde bestand twee keer importeren mag niet dubbel tellen.
        $exists = PaymentResult::where('collection_file_id', $file->id)
            ->where('paid_amount', $amount)
            ->where(fn ($q) => $paidAt ? $q->whereDate('paid_at', $paidAt) : $q->whereNull('paid_at'))
            ->where(fn ($q) => $reference ? $q->where('reference', $reference) : $q->whereNull('reference'))
            ->exists();
        if ($exists) {
            $this->duplicate($line, __('betaling voor dossier :number is al eerder geïmporteerd', ['number' => $number]));

            return;
        }

        if ($amount > 0) {
            $file->paymentResults()->create([
                'data_import_id' => $import->id,
                'paid_amount' => $amount,
                'paid_at' => $paidAt,
                'reference' => $reference,
            ]);
        }
        $this->touched[$file->id] = true;
        $this->imported++;
    }

    protected function after(DataImport $import): void
    {
        foreach (CollectionFile::whereIn('id', array_keys($this->touched))->get() as $file) {
            static::evaluate($file);
        }

        if ($this->markMissingUnpaid) {
            CollectionFile::where('client_id', $this->client->id)
                ->whereIn('status', [CollectionStatus::Promise, CollectionStatus::Arrangement])
                ->whereNotIn('id', array_keys($this->touched))
                ->where('payment_status', PaymentStatus::Unknown)
                ->update(['payment_status' => PaymentStatus::Unpaid]);
        }
    }

    /** Bepaalt opnieuw de betaalstatus en commissie van een dossier. */
    public static function evaluate(CollectionFile $file): void
    {
        $paid = round((float) $file->paymentResults()->sum('paid_amount'), 2);
        $lastDate = $file->paymentResults()->max('paid_at');
        $target = round($file->targetAmount(), 2);

        $status = match (true) {
            $paid <= 0 => PaymentStatus::Unpaid,
            $paid + 0.005 >= $target => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };

        $file->forceFill([
            'paid_amount' => $paid,
            'paid_at' => $lastDate,
            'payment_status' => $status,
        ]);

        $commission = null;
        if ($status === PaymentStatus::Paid) {
            $model = CommissionModel::activeFor($file->client_id, $lastDate ? Carbon::parse($lastDate) : now());
            $commission = $model?->calculate($file);
        }
        $file->forceFill([
            'commission_amount' => $commission,
            'commission_calculated_at' => $commission !== null ? now() : null,
        ])->save();
    }
}
