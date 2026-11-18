<?php

namespace App\Filament\Pages;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Mailbox;
use App\Support\Mail\EmailSender;
use App\Models\Company;
use App\Support\Reports\BusinessReport;
use App\Support\Reports\CallingReport;
use App\Support\Reports\ReportExporter;
use App\Support\Tenancy\TenantManager;
use Filament\Actions\ActionGroup;
use Illuminate\Support\Carbon;
use App\Support\Reports\WorkReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rapportagecentrum: pre-incasso, telemarketing, data-entry, uren, klanten,
 * diensten/campagnes, medewerkers en (superadmin) bedrijven. Export naar
 * Excel, CSV of PDF en handmatig mailen naar de opdrachtgever.
 */
class CallingReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'rapportages';

    public ?array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('Rapportages');
    }

    public function getTitle(): string
    {
        return __('Rapportagecentrum');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    public function mount(): void
    {
        $tz = FilamentTimezone::get();
        $this->filtersForm->fill([
            'module' => 'collection',
            'client_id' => null,
            'campaign_id' => null,
            'from' => now($tz)->startOfMonth()->toDateString(),
            'until' => now($tz)->toDateString(),
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->columns(['default' => 1, 'md' => 6])
            ->components([
                Select::make('module')->label(__('Rapport'))->live()->selectablePlaceholder(false)->columnSpan(['md' => 2])
                    ->options(fn () => static::modules())
                    ->afterStateUpdated(fn ($set) => $set('campaign_id', null)),
                Select::make('client_id')->label(__('Opdrachtgever'))->placeholder(__('Alle opdrachtgevers'))
                    ->options(fn () => Client::query()->where(fn ($q) => $q->whereHas('collectionFiles')->orWhereHas('campaigns')->orWhereHas('dataJobs')->orWhereHas('projects'))->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->live()->afterStateUpdated(fn ($set) => $set('campaign_id', null))
                    ->visible(fn (Get $get) => $get('module') !== 'companies'),
                Select::make('campaign_id')->label(__('Campagne'))->placeholder(__('Alle campagnes'))
                    ->options(fn (Get $get) => Campaign::query()->when($get('client_id'), fn ($q, $id) => $q->where('client_id', $id))->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn (Get $get) => $get('module') === 'telemarketing')->live(),
                DatePicker::make('from')->label(__('Van'))->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1)->live()->required(),
                DatePicker::make('until')->label(__('Tot en met'))->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1)->live()->required(),
            ]);
    }

    /** @return array<string, string> */
    public static function modules(): array
    {
        $user = auth()->user();

        return array_filter([
            'collection' => __('Pre-incasso'),
            'telemarketing' => __('Telemarketing'),
            'data_entry' => __('Data-entry'),
            'hours' => __('Uren'),
            'clients' => __('Klanten'),
            'services' => __('Diensten en campagnes'),
            'employees' => __('Medewerkers'),
            'companies' => $user?->isSuperadmin() ? __('Bedrijven (alle)') : null,
        ]);
    }

    protected function withMoney(): bool
    {
        return auth()->user()->can('invoices.view') || auth()->user()->can('finance.view');
    }

    protected function business(): BusinessReport
    {
        $tz = FilamentTimezone::get();
        $f = $this->filters;

        return new BusinessReport(
            Carbon::parse($f['from'] ?? now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay(),
            Carbon::parse($f['until'] ?? now($tz)->toDateString(), $tz)->endOfDay(),
        );
    }

    protected function report(): array
    {
        return $this->cachedReport ??= $this->buildReport();
    }

    protected ?array $cachedReport = null;

    protected function buildReport(): array
    {
        $f = $this->filters;
        $period = CallingReport::period($f['from'] ?? null, $f['until'] ?? null, FilamentTimezone::get());
        $module = array_key_exists($f['module'] ?? '', static::modules()) ? $f['module'] : 'collection';

        $client = $f['client_id'] ? (int) $f['client_id'] : null;
        $work = fn () => new WorkReport(
            \Illuminate\Support\Carbon::parse($f['from'] ?? now()->startOfMonth())->startOfDay(),
            \Illuminate\Support\Carbon::parse($f['until'] ?? now())->endOfDay(),
        );

        return match ($module) {
            'clients' => $this->business()->clients($client, $this->withMoney()),
            'services' => $this->business()->services($client, $this->withMoney()),
            'employees' => $this->business()->employees($client),
            'companies' => BusinessReport::companies($this->perCompany()),
            'telemarketing' => $period->telemarketing($client, $f['campaign_id'] ? (int) $f['campaign_id'] : null),
            'data_entry' => $work()->dataEntry($client),
            'hours' => $work()->hoursOverview($client),
            default => $period->collection($client),
        };
    }

    /** Superadmin: per bedrijf de kerncijfers over de periode. */
    protected function perCompany(): array
    {
        if (! auth()->user()->isSuperadmin()) {
            return [];
        }
        $f = $this->filters;
        $out = [];
        foreach (Company::query()->where('is_active', true)->orderBy('name')->get() as $company) {
            try {
                $out[$company->name] = app(TenantManager::class)->runFor($company, function () use ($company, $f) {
                    $tz = $company->timezone ?: FilamentTimezone::get();

                    return (new BusinessReport(
                        Carbon::parse($f['from'] ?? now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay(),
                        Carbon::parse($f['until'] ?? now($tz)->toDateString(), $tz)->endOfDay(),
                    ))->companyTotals();
                });
            } catch (Throwable $e) {
                report($e);
                $out[$company->name] = null;
            }
        }

        return $out;
    }

    protected function title(): string
    {
        $f = $this->filters;
        $parts = [match ($f['module'] ?? '') {
            'clients' => __('Rapportage klanten'),
            'services' => __('Rapportage diensten en campagnes'),
            'employees' => __('Rapportage medewerkers'),
            'companies' => __('Rapportage bedrijven'),
            'telemarketing' => __('Rapportage telemarketing'),
            'data_entry' => __('Rapportage data-entry'),
            'hours' => __('Urenoverzicht'),
            default => __('Rapportage pre-incasso'),
        }];
        if (($f['client_id'] ?? null) && ($f['module'] ?? '') !== 'companies') {
            $parts[] = Client::find($f['client_id'])?->name;
        }
        if ($f['campaign_id'] ?? null) {
            $parts[] = Campaign::find($f['campaign_id'])?->name;
        }

        return implode(' · ', array_filter($parts));
    }

    protected function periodLabel(): string
    {
        $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-m-Y') : '';

        return __('Periode: :from t/m :until', ['from' => $fmt($this->filters['from'] ?? null), 'until' => $fmt($this->filters['until'] ?? null)]);
    }

    public function content(Schema $schema): Schema
    {
        $report = $this->report();
        $entries = [];
        foreach ($report['summary'] as $label => $value) {
            $entries[] = TextEntry::make('s_'.Str::slug($label))->label($label)->state((string) $value)->weight('bold')->size('lg');
        }

        $tables = ReportExporter::tables($report);
        $sections = array_map(fn ($t) => Section::make($t['title'])->schema([Html::make($this->table($t))])
            ->columnSpan(count($t['headers']) > 4 || count($tables) === 1 ? 'full' : 1), $tables);

        return $schema->components([
            Section::make()->schema([EmbeddedSchema::make('filtersForm')]),
            Section::make($this->title())->description($this->periodLabel())
                ->columns(['default' => 2, 'md' => 4])
                ->schema($entries),
            Grid::make(['default' => 1, 'lg' => 2])->schema($sections),
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
        $td = 'style="padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.15);white-space:nowrap"';
        $html .= '</tr></thead><tbody>';
        foreach ($data['rows'] as $row) {
            $html .= '<tr>'.collect($row)->map(fn ($v) => "<td {$td}>".e((string) $v).'</td>')->implode('').'</tr>';
        }

        return $html.'</tbody></table></div>';
    }

    protected function writeFile(string $format = 'xlsx'): array
    {
        $format = array_key_exists($format, ReportExporter::FORMATS) ? $format : 'xlsx';
        $name = Str::slug($this->title()).'-'.now()->format('Ymd').'.'.$format;
        $relative = 'companies/'.Filament::getTenant()->slug.'/reports/'.now()->format('Y/m').'/'.Str::random(8).'-'.$name;
        ReportExporter::write($format, Storage::disk('local')->path($relative), $this->report(), $this->title(), $this->periodLabel());

        return [$name, $relative];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(array_map(fn ($format, $label) => Action::make('export_'.$format)
                ->label($label)
                ->action(function () use ($format) {
                    [$name, $path] = $this->writeFile($format);

                    return response()->download(Storage::disk('local')->path($path), $name)->deleteFileAfterSend();
                }), array_keys(ReportExporter::FORMATS), ReportExporter::FORMATS))
                ->label(__('Exporteren'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->button(),
            Action::make('mail')
                ->label(__('Rapport mailen'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible(fn () => auth()->user()->can('emails.send') && Mailbox::where('is_active', true)->exists())
                ->fillForm(function () {
                    $client = ($id = $this->filters['client_id'] ?? null) ? Client::with('primaryContact')->find($id) : null;

                    return [
                        'format' => 'pdf',
                        'mailbox_id' => Mailbox::where('is_active', true)->orderBy('id')->value('id'),
                        'to' => array_values(array_filter([$client?->primaryContact?->email ?? $client?->email])),
                        'subject' => $this->title().' – '.$this->periodLabel(),
                        'body' => __("Beste :name,\n\nIn de bijlage vind je de rapportage over de genoemde periode.\n\nMet vriendelijke groet,\n:sender", [
                            'name' => $client?->primaryContact?->first_name ?? '', 'sender' => auth()->user()->name,
                        ]),
                    ];
                })
                ->schema([
                    ToggleButtons::make('format')->label(__('Bijlage'))->inline()->options(ReportExporter::FORMATS)->default('pdf')->required(),
                    Select::make('mailbox_id')->label(__('Versturen vanaf'))
                        ->options(fn () => Mailbox::where('is_active', true)->get()->mapWithKeys(fn ($m) => [$m->id => "{$m->name} <{$m->email}>"]))->required(),
                    TagsInput::make('to')->label(__('Aan'))->nestedRecursiveRules(['email'])->required(),
                    TextInput::make('subject')->label(__('Onderwerp'))->required(),
                    Textarea::make('body')->label(__('Bericht'))->rows(8)->required(),
                ])
                ->modalDescription(__('Het rapport gaat als bijlage mee.'))
                ->modalSubmitActionLabel(__('Versturen'))
                ->action(function (array $data) {
                    [$name, $path] = $this->writeFile($data['format'] ?? 'pdf');
                    $client = ($id = $this->filters['client_id'] ?? null) ? Client::find($id) : null;
                    try {
                        app(EmailSender::class)->send(Mailbox::findOrFail($data['mailbox_id']), $data['to'], $data['subject'], $data['body'],
                            attachmentPaths: [$name => $path], linkTo: array_filter([$client]));
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title(__('Versturen mislukt'))->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title(__('Rapport verstuurd'))->send();
                }),
        ];
    }
}
