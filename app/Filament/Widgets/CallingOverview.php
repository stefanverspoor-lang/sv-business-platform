<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\CallingReports;
use App\Support\Billing\Money;
use App\Support\Dashboard\CompanyMetrics;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Resultaten telemarketing en pre-incasso deze maand. */
class CallingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u->can('reports.view') || $u->can('campaigns.manage') || $u->can('collection.manage');
    }

    public function getHeading(): ?string
    {
        return __('Bellen deze maand');
    }

    protected function getStats(): array
    {
        $m = cache()->remember('dashboard:business:'.Filament::getTenant()->id.':'.auth()->id(), 120, fn () => (new CompanyMetrics(auth()->user()))->all());
        $url = auth()->user()->can('reports.view') ? CallingReports::getUrl() : null;

        return [
            Stat::make(__('Telemarketing: belpogingen'), $m['tm_calls_month'])
                ->description(__(':count conversies · :open leads nog te bellen', ['count' => $m['tm_conversions_month'], 'open' => $m['tm_leads_open']]))
                ->icon(Heroicon::OutlinedMegaphone)->url($url),
            Stat::make(__('Pre-incasso: afspraken'), $m['pc_agreements_month'])
                ->description(__(':calls belpogingen · :open dossiers open', ['calls' => $m['pc_calls_month'], 'open' => $m['pc_files_open']]))
                ->icon(Heroicon::OutlinedFolderOpen)->url($url),
            Stat::make(__('Pre-incasso: volledig betaald'), $m['pc_paid_month'])
                ->description(__('Commissie: :amount', ['amount' => Money::format($m['pc_commission_month'], $m['currency'])]))
                ->icon(Heroicon::OutlinedBanknotes)->color('success')->url($url),
        ];
    }
}
