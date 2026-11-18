<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Een bankrekening van het bedrijf; per factuur te kiezen (bijv. per land of valuta). */
class BankAccount extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    protected $fillable = ['name', 'account_holder', 'bank_name', 'iban', 'bic', 'currency', 'extra', 'is_default', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Er is maar één standaardrekening.
        static::saved(function (BankAccount $account) {
            if ($account->is_default) {
                static::query()->whereKeyNot($account->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Rekening voor een valuta: eerst een rekening in die valuta, anders de standaard. */
    public static function forCurrency(?string $currency): ?self
    {
        return static::active()->when($currency, fn ($q) => $q->where('currency', $currency))->orderByDesc('is_default')->orderBy('sort')->first()
            ?? static::active()->orderByDesc('is_default')->orderBy('sort')->first();
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::active()->orderBy('sort')->orderBy('name')->get()
            ->mapWithKeys(fn (self $a) => [$a->id => $a->name.($a->iban ? ' – '.$a->iban : '')])->all();
    }

    /** Velden zoals ze in de vastgelegde bedrijfsgegevens van een factuur komen. */
    public function snapshot(): array
    {
        return [
            'bank_name' => $this->bank_name,
            'account_holder' => $this->account_holder,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'bank_extra' => $this->extra,
        ];
    }
}
