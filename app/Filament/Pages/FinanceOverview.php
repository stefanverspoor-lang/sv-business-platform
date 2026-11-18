<?php

namespace App\Filament\Pages;

use App\Support\Imports\Spreadsheet;
use App\Support\Reports\FinanceReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Winst en verlies, btw, openstaande posten en exports voor de accountant. */
class FinanceOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'financieel-overzicht';

    public ?array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('Overzicht');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Financiën');
    }

    public function getTitle(): string
    {
        return __('Financieel overzicht');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('finance.view') ?? false;
    }

    public function mount(): void
    {
        $this->filtersForm->fill(['from' => now()->startOfYear()->toDateString(), 'until' => now()->toDateString()]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->statePath('filters')->columns(['default' => 1, 'md' => 4])->components([
            DatePicker::make('from')->label(__('Van'))->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1)->live()->required(),
            DatePicker::make('until')->label(__('Tot en met'))->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1)->live()->required(),
        ]);
    }

    protected function report(): FinanceReport
    {
        return new FinanceReport(
            Carbon::parse($this->filters['from'] ?? now()->startOfYear())->startOfDay(),
            Carbon::parse($this->filters['until'] ?? now())->endOfDay(),
        );
    }

    public function content(Schema $schema): Schema
    {
        $report = $this->report();
        $data = $report->build();
        $entries = collect($data['summary'])->map(fn ($v, $label) => TextEntry::make('s_'.Str::slug($label))->label($label)->state((string) $v)->weight('bold')->size('lg'))->values()->all();
        $tables = collect($data['tables'])->map(fn ($t, $title) => Section::make($title)->schema([Html::make($this->table($t))]))->values()->all();

        return $schema->components([
            Section::make()->schema([EmbeddedSchema::make('filtersForm')]),
            Section::make(__('Resultaat in :base', ['base' => $report->base]))
                ->description(__('Periode: :from t/m :until', ['from' => $report->from->format('d-m-Y'), 'until' => $report->until->format('d-m-Y')]))
                ->columns(['default' => 2, 'md' => 4])
                ->schema($entries),
            Grid::make(['default' => 1, 'lg' => 2])->schema($tables),
            Text::make(__('Dit overzicht is een hulpmiddel en vervangt geen wettelijke boekhouding. Gebruik de exports (rechtsboven) voor je accountant of boekhoudprogramma.'))->size('sm')->color('gray'),
        ]);
    }

    protected function table(array $data): string
    {
        if (! $data['rows']) {
            return '<p style="opacity:.7">'.e(__('Geen gegevens in deze periode.')).'</p>';
        }
        $th = 'style="text-align:left;padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.3);font-weight:600"';
        $td = 'style="padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.15)"';
        $html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr>';
        foreach ($data['headers'] as $h) {
            $html .= "<th {$th}>".e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($data['rows'] as $row) {
            $html .= '<tr>'.collect($row)->map(fn ($v) => "<td {$td}>".e((string) $v).'</td>')->implode('').'</tr>';
        }

        return $html.'</tbody></table></div>';
    }

    protected function download(string $name, array $export, string $format)
    {
        [$headers, $rows] = $export;
        $file = tempnam(sys_get_temp_dir(), 'fin').'.'.$format;
        $filename = Str::slug($name).'-'.$this->report()->from->format('Ymd').'-'.$this->report()->until->format('Ymd').'.'.$format;

        if ($format === 'csv') {
            // CSV voor Excel (NL): puntkomma en UTF-8 met BOM
            $h = fopen($file, 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, $headers, ';', '"', '');
            foreach ($rows as $row) {
                fputcsv($h, array_map(fn ($v) => is_float($v) ? str_replace('.', ',', (string) $v) : $v, $row), ';', '"', '');
            }
            fclose($h);
        } else {
            Spreadsheet::write($file, $headers, $rows);
        }

        return response()->download($file, $filename)->deleteFileAfterSend();
    }

    protected function getHeaderActions(): array
    {
        $exports = [
            'invoices' => [__('Verkoopfacturen'), 'exportInvoices'],
            'payments' => [__('Ontvangen betalingen'), 'exportPayments'],
            'expenses' => [__('Uitgaven en inkoopfacturen'), 'exportExpenses'],
            'incomes' => [__('Overige inkomsten'), 'exportIncomes'],
        ];
        $actions = [];
        foreach (['xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label) {
            foreach ($exports as $key => [$name, $method]) {
                $actions[] = Action::make("export_{$key}_{$format}")
                    ->label($name.' ('.$label.')')
                    ->icon($format === 'csv' ? Heroicon::OutlinedDocumentText : Heroicon::OutlinedTableCells)
                    ->action(fn () => $this->download($name, $this->report()->{$method}(), $format));
            }
        }

        return [
            ActionGroup::make($actions)->label(__('Exporteren'))->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray'),
        ];
    }
}
