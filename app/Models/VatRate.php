<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VatRate extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    public const KINDS = ['standard', 'reduced', 'zero', 'exempt', 'reverse_charge', 'none'];

    protected $fillable = ['name', 'rate', 'kind', 'invoice_note', 'invoice_note_en', 'is_default', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(function (VatRate $rate) {
            if ($rate->is_default) {
                static::whereKeyNot($rate->getKey())->update(['is_default' => false]);
            }
        });
    }

    public static function kinds(): array
    {
        return [
            'standard' => __('Standaardtarief'), 'reduced' => __('Verlaagd tarief'), 'zero' => __('Nultarief'),
            'exempt' => __('Vrijgesteld'), 'reverse_charge' => __('Btw verlegd'), 'none' => __('Geen btw (buiten btw-heffing)'),
        ];
    }

    public static function defaultId(): ?int
    {
        return static::query()->where('is_active', true)->where('is_default', true)->value('id')
            ?? static::query()->where('is_active', true)->orderBy('sort')->value('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort');
    }

    public function note(string $locale): ?string
    {
        return $locale === 'en' ? ($this->invoice_note_en ?: $this->invoice_note) : $this->invoice_note;
    }
}
