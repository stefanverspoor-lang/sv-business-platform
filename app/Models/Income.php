<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use Auditable, BelongsToCompanyDatabase, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'due_date' => 'date', 'paid_at' => 'date',
            'amount_excl' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total' => 'decimal:2', 'exchange_rate' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Income $record) {
            $record->total = round((float) $record->amount_excl + (float) $record->vat_amount, 2);
            $record->exchange_rate = $record->exchange_rate ?: 1;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** Bedrag exclusief btw in de basisvaluta van het bedrijf. */
    public function baseAmount(): float
    {
        return round((float) $this->amount_excl * (float) $this->exchange_rate, 2);
    }
}
