<?php

namespace App\Models\Concerns;

use App\Models\BillingLine;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Regels en totalen voor offertes, facturen en terugkerende facturen.
 * Btw wordt per tarief over het totaal van de regels berekend (en
 * afgerond), zoals op een Nederlandse factuur gebruikelijk is.
 */
trait HasBillingLines
{
    public function lines(): MorphMany
    {
        return $this->morphMany(BillingLine::class, 'billable')->orderBy('sort')->orderBy('id');
    }

    /** @return Collection<int, array{label: string, rate: float, base: float, vat: float, note: ?string}> */
    public function vatBreakdown(?Collection $lines = null): Collection
    {
        $lines ??= $this->lines()->with('vatRateModel')->get();

        return $lines->groupBy(fn (BillingLine $l) => $l->vat_label.'|'.$l->vat_rate)
            ->map(function (Collection $group) {
                $first = $group->first();
                $base = round($group->sum(fn ($l) => (float) $l->line_total), 2);

                return [
                    'label' => (string) $first->vat_label,
                    'rate' => (float) $first->vat_rate,
                    'base' => $base,
                    'vat' => round($base * (float) $first->vat_rate / 100, 2),
                    'note' => $first->vatRateModel?->note($this->locale ?? 'nl'),
                ];
            })->values();
    }

    public function recalculateTotals(): void
    {
        $lines = $this->lines()->with('vatRateModel')->get();
        $subtotal = round($lines->sum(fn ($l) => (float) $l->line_total), 2);
        $vat = round($this->vatBreakdown($lines)->sum('vat'), 2);

        $this->forceFill(['subtotal' => $subtotal, 'vat_total' => $vat, 'total' => round($subtotal + $vat, 2)])->saveQuietly();
    }
}
