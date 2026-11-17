<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use App\Models\Company;
use App\Support\Tenancy\TenantManager;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Kerncijfers van het actieve bedrijf; de superadmin ziet ook het totaal
 * van beide bedrijven. Wordt in fase 6 uitgebreid tot het volledige dashboard.
 */
class CompanyOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $company = Filament::getTenant();
        $user = auth()->user();

        $stats = [
            Stat::make(__('Actieve klanten').' · '.$company->name,
                Client::query()->visibleTo($user)->active()->count()),
            Stat::make(__('Nieuwe klanten deze maand'),
                Client::query()->visibleTo($user)->where('created_at', '>=', now()->startOfMonth())->count()),
        ];

        if ($user->isSuperadmin()) {
            $tenants = app(TenantManager::class);
            $total = Company::where('is_active', true)->get()
                ->sum(fn (Company $c) => $tenants->runFor($c, fn () => Client::query()->active()->count()));
            $stats[] = Stat::make(__('Actieve klanten, alle bedrijven'), $total);
        }

        return $stats;
    }
}
