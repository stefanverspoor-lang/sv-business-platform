<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Support\Billing\Money;
use App\Support\Dashboard\CompanyMetrics;
use App\Support\Tenancy\TenantManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Totaaloverzicht van alle bedrijven naast elkaar. Alleen voor de
 * superadmin; elk bedrijf wordt los uit zijn eigen database gelezen.
 */
class GroupOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'totaaloverzicht';

    public static function getNavigationLabel(): string
    {
        return __('Totaaloverzicht');
    }

    public function getTitle(): string
    {
        return __('Totaaloverzicht alle bedrijven');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperadmin() ?? false;
    }

    /** @return array<string, array> naam => cijfers */
    public function metrics(): array
    {
        return cache()->remember('dashboard:group:'.auth()->id(), 120, function () {
            $out = [];
            foreach (Company::query()->where('is_active', true)->orderBy('name')->get() as $company) {
                try {
                    $out[$company->name] = app(TenantManager::class)->runFor($company, fn () => (new CompanyMetrics(auth()->user()))->all());
                } catch (Throwable $e) {
                    report($e);
                    $out[$company->name] = null;
                }
            }

            return $out;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label(__('Vernieuwen'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(fn () => cache()->forget('dashboard:group:'.auth()->id())),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->metrics();
        $ok = array_filter($data);
        $currencies = array_unique(array_column($ok, 'currency'));
        $sameCurrency = count($currencies) === 1;

        $rows = [
            [__('Financieel'), null],
            [__('Omzet deze maand'), 'revenue_month', 'money'],
            [__('Omzet dit jaar'), 'revenue_year', 'money'],
            [__('Uitgaven deze maand'), 'expenses_month', 'money'],
            [__('Uitgaven dit jaar'), 'expenses_year', 'money'],
            [__('Resultaat dit jaar'), fn ($m) => $m['revenue_year'] - $m['expenses_year'], 'money'],
            [__('Openstaande facturen'), fn ($m) => $m['open_invoices']['amount'], 'money', fn ($m) => $m['open_invoices']['count']],
            [__('Achterstallige betalingen'), fn ($m) => $m['overdue_invoices']['amount'], 'money', fn ($m) => $m['overdue_invoices']['count']],
            [__('Klanten en werk'), null],
            [__('Actieve klanten'), 'active_clients'],
            [__('Nieuwe klanten (30 dagen)'), 'new_clients'],
            [__('Lopende websiteprojecten'), 'running_projects'],
            [__('Lopende data-entry'), 'running_data_jobs'],
            [__('Open taken'), 'tasks_open'],
            [__('Achterstallige taken'), 'tasks_overdue'],
            [__('Ongelezen e-mails'), 'emails_unread'],
            [__('Telemarketing deze maand'), null],
            [__('Belpogingen'), 'tm_calls_month'],
            [__('Conversies'), 'tm_conversions_month'],
            [__('Leads nog te bellen'), 'tm_leads_open'],
            [__('Pre-incasso deze maand'), null],
            [__('Belpogingen'), 'pc_calls_month'],
            [__('Betalingsafspraken'), 'pc_agreements_month'],
            [__('Volledig betaald'), 'pc_paid_month'],
            [__('Commissie'), 'pc_commission_month', 'money'],
            [__('Open dossiers'), 'pc_files_open'],
        ];

        $th = 'style="text-align:right;padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.3);font-weight:600;white-space:nowrap"';
        $td = 'style="text-align:right;padding:6px 10px;border-bottom:1px solid rgba(127,127,127,.15);white-space:nowrap"';
        $tl = 'style="text-align:left;padding:6px 10px;border-bottom:1px solid rgba(127,127,127,.15)"';
        $html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr><th style="text-align:left;padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.3)"></th>';
        foreach (array_keys($data) as $name) {
            $html .= "<th {$th}>".e($name).'</th>';
        }
        if (count($data) > 1) {
            $html .= "<th {$th}>".e(__('Totaal')).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            [$label, $key] = $row;
            if ($key === null) {
                $html .= '<tr><td colspan="'.(count($data) + 2).'" style="padding:14px 10px 4px;font-weight:600;opacity:.75;text-transform:uppercase;font-size:12px;letter-spacing:.04em">'.e($label).'</td></tr>';

                continue;
            }
            $isMoney = ($row[2] ?? null) === 'money';
            $count = $row[3] ?? null;
            $value = fn ($m) => is_callable($key) ? $key($m) : $m[$key];
            $html .= "<tr><td {$tl}>".e($label).'</td>';
            $sum = 0;
            $sumCount = 0;
            foreach ($data as $m) {
                if (! $m) {
                    $html .= "<td {$td}>".e(__('niet beschikbaar')).'</td>';

                    continue;
                }
                $v = $value($m);
                $sum += $v;
                $sumCount += $count ? $count($m) : 0;
                $cell = $isMoney ? Money::format($v, $m['currency']) : number_format($v, 0, ',', '.');
                if ($count) {
                    $cell .= ' <span style="opacity:.6">('.$count($m).')</span>';
                }
                $html .= "<td {$td}>".$cell.'</td>';
            }
            if (count($data) > 1) {
                $total = $isMoney ? ($sameCurrency ? Money::format($sum, $currencies[0] ?? 'EUR') : '–') : number_format($sum, 0, ',', '.');
                if ($count && ($sameCurrency || ! $isMoney)) {
                    $total .= ' <span style="opacity:.6">('.$sumCount.')</span>';
                }
                $html .= "<td {$td}><strong>".$total.'</strong></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        $note = $sameCurrency ? __('Bedragen excl. btw in :cur. Cijfers worden 2 minuten bewaard; klik op Vernieuwen voor de laatste stand.', ['cur' => $currencies[0] ?? 'EUR'])
            : __('De bedrijven rekenen in verschillende basisvaluta, daarom worden bedragen niet opgeteld.');

        return $schema->components([
            Section::make()->description($note)->schema([Html::make($html)]),
        ]);
    }
}
