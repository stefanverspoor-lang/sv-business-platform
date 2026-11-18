<?php

namespace App\Filament\Widgets;

use App\Support\Billing\BillingSettings;
use App\Support\Dashboard\CompanyMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Omzet en kosten per maand, laatste 12 maanden. */
class RevenueChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()->can('finance.view');
    }

    public function getHeading(): ?string
    {
        return __('Omzet en kosten per maand (:cur, excl. btw)', ['cur' => BillingSettings::baseCurrency()]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $data = cache()->remember('dashboard:chart:'.Filament::getTenant()->id, 300, function () {
            $metrics = new CompanyMetrics(auth()->user());
            $rows = [];
            for ($i = 11; $i >= 0; $i--) {
                $start = now()->startOfMonth()->subMonthsNoOverflow($i);
                $end = $start->copy()->endOfMonth();
                $rows[] = [
                    'label' => $start->translatedFormat('M y'),
                    'revenue' => $metrics->revenue($start, $end),
                    'costs' => $metrics->expenses($start, $end),
                ];
            }

            return $rows;
        });

        $color = Filament::getTenant()?->primary_color ?: '#1F4D45';

        return [
            'datasets' => [
                ['label' => __('Omzet'), 'data' => array_column($data, 'revenue'), 'backgroundColor' => $color, 'borderRadius' => 4],
                ['label' => __('Kosten'), 'data' => array_column($data, 'costs'), 'backgroundColor' => '#b8b0a8', 'borderRadius' => 4],
            ],
            'labels' => array_column($data, 'label'),
        ];
    }
}
