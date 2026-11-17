<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Commissieafspraak met een opdrachtgever. */
class CommissionModel extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    protected $fillable = ['client_id', 'name', 'type', 'rate', 'currency', 'valid_from', 'valid_until', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['type' => CommissionType::class, 'rate' => 'decimal:4', 'valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Het model dat gold op een datum (de betaaldatum). */
    public static function activeFor(int $clientId, ?Carbon $on = null): ?self
    {
        $on ??= now();

        return static::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $on))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $on))
            ->latest('valid_from')
            ->first();
    }

    public function calculate(CollectionFile $file): float
    {
        return round(match ($this->type) {
            CommissionType::PercentPaid => (float) $file->paid_amount * (float) $this->rate / 100,
            CommissionType::FixedPerSuccess => (float) $this->rate,
            CommissionType::PercentOriginal => (float) $file->original_amount * (float) $this->rate / 100,
        }, 2);
    }
}
