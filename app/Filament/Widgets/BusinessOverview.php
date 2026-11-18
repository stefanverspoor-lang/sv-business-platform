<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\DataJobs\DataJobResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Support\Billing\Money;
use App\Support\Dashboard\CompanyMetrics;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Omzet, uitgaven, openstaande facturen, klanten en projecten van het actieve bedrijf. */
class BusinessOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u->can('invoices.view') || $u->can('finance.view') || $u->can('clients.view_all') || $u->can('projects.view_all');
    }

    public function getHeading(): ?string
    {
        return Filament::getTenant()?->name;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $m = cache()->remember('dashboard:business:'.Filament::getTenant()->id.':'.auth()->id(), 120, fn () => (new CompanyMetrics($user))->all());
        $money = fn ($v) => Money::format($v, $m['currency']);
        $finance = $user->can('finance.view') || $user->can('invoices.view');

        return array_values(array_filter([
            $finance ? Stat::make(__('Omzet deze maand'), $money($m['revenue_month']))
                ->description(__('Dit jaar: :amount', ['amount' => $money($m['revenue_year'])]))
                ->icon(Heroicon::OutlinedArrowTrendingUp)->color('success') : null,
            $user->can('finance.view') ? Stat::make(__('Uitgaven deze maand'), $money($m['expenses_month']))
                ->description(__('Dit jaar: :amount', ['amount' => $money($m['expenses_year'])]))
                ->icon(Heroicon::OutlinedArrowTrendingDown) : null,
            $finance ? Stat::make(__('Openstaande facturen'), $money($m['open_invoices']['amount']))
                ->description(trans_choice(':count factuur|:count facturen', $m['open_invoices']['count'], ['count' => $m['open_invoices']['count']]))
                ->icon(Heroicon::OutlinedDocumentText)
                ->url(InvoiceResource::getUrl('index', ['activeTab' => 'open'])) : null,
            $finance ? Stat::make(__('Achterstallige betalingen'), $money($m['overdue_invoices']['amount']))
                ->description(trans_choice(':count factuur te laat|:count facturen te laat', $m['overdue_invoices']['count'], ['count' => $m['overdue_invoices']['count']]))
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->color($m['overdue_invoices']['count'] ? 'danger' : 'success')
                ->url(InvoiceResource::getUrl('index', ['activeTab' => 'overdue'])) : null,
            Stat::make(__('Actieve klanten'), $m['active_clients'])
                ->description(__(':count nieuw in de laatste 30 dagen', ['count' => $m['new_clients']]))
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->url(ClientResource::getUrl()),
            Stat::make(__('Lopende websiteprojecten'), $m['running_projects'])
                ->description(__(':count data-entry-opdrachten lopend', ['count' => $m['running_data_jobs']]))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->url(ProjectResource::getUrl()),
        ]));
    }
}
