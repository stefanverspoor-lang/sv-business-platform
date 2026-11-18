<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillingLine extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['sort', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'vat_rate_id',
        'vat_rate', 'vat_label', 'line_total', 'source'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:2',
            'vat_rate' => 'decimal:2', 'line_total' => 'decimal:2', 'source' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BillingLine $line) {
            $line->guardLocked();
            // Tarief en naam vastleggen, zodat latere wijzigingen in Beheer oude facturen niet veranderen
            if ($line->vat_rate_id && (($line->exists && $line->isDirty('vat_rate_id')) || $line->vat_label === null)) {
                $rate = VatRate::find($line->vat_rate_id);
                $line->vat_rate = $rate?->rate ?? 0;
                $line->vat_label = $rate?->name;
            }
            $line->line_total = static::calculate($line->quantity, $line->unit_price, $line->discount_percent);
        });

        static::deleting(function (BillingLine $line) {
            $line->guardLocked();
            \App\Support\Billing\BillableWork::release($line);
        });
    }

    /** Regels van een definitieve factuur liggen vast. */
    protected function guardLocked(): void
    {
        if ($this->billable_type === 'invoice') {
            $status = Invoice::query()->toBase()->where('id', $this->billable_id)->value('status');
            if ($status && $status !== \App\Enums\InvoiceStatus::Draft->value) {
                throw new \RuntimeException(__('Een definitieve factuur kan niet meer worden gewijzigd.'));
            }
        }
    }

    public static function calculate(mixed $quantity, mixed $unitPrice, mixed $discount = 0): float
    {
        return round((float) $quantity * (float) $unitPrice * (1 - (float) $discount / 100), 2);
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function vatRateModel(): BelongsTo
    {
        return $this->belongsTo(VatRate::class, 'vat_rate_id');
    }

    public static function units(): array
    {
        return ['stuk' => __('stuk'), 'uur' => __('uur'), 'dag' => __('dag'), 'maand' => __('maand'), 'jaar' => __('jaar'),
            'record' => __('record'), 'dossier' => __('dossier'), 'project' => __('project')];
    }
}
