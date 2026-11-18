<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Wisselkoers: 1 eenheid van `currency` is `rate` eenheden van de basisvaluta. */
class ExchangeRate extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['currency', 'base_currency', 'rate', 'date', 'source'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:8', 'date' => 'date'];
    }

    /** Laatst bekende koers op of voor de datum; 1 als valuta gelijk is, null als onbekend. */
    public static function lookup(string $currency, string $base, Carbon|string|null $date = null): ?float
    {
        if (strtoupper($currency) === strtoupper($base)) {
            return 1.0;
        }
        $date = $date ? Carbon::parse($date) : now();

        $rate = static::query()->where('currency', strtoupper($currency))->where('base_currency', strtoupper($base))
            ->whereDate('date', '<=', $date)->orderByDesc('date')->value('rate');

        return $rate !== null ? (float) $rate : null;
    }
}
