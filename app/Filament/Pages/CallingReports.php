<?php

namespace App\Filament\Pages;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Mailbox;
use App\Support\Imports\Spreadsheet;
use App\Support\Mail\EmailSender;
use App\Support\Reports\CallingReport;
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
 * Rapportages telemarketing en pre-incasso, met export naar Excel en
 * handmatig mailen naar de opdrachtgever.
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
        return __('Rapportages');
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
            ->columns(['default' => 1, 'md' => 5])
            ->components([
                ToggleButtons::make('module')->label(__('Rapport'))->inline()->live()
                    ->options(['collection' => __('Pre-incasso'), 'telemarketing' => __('Telemarketing'), 'data_entry' => __('Data-entry'), 'hours' => __('Uren')])
                    ->afterStateUpdated(fn ($set) => $set('campaign_id', null)),
                Select::make('client_id')->label(__('Opdrachtgever'))->placeholder(__('Alle opdrachtgevers'))
                    ->options(fn () => Client::query()->where(fn ($q) => $q->whereHas('collectionFiles')->orWhereHas('campaigns')->orWhereHas('dataJobs')->orWhereHas('projects'))->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->live()->afterStateUpdated(fn ($set) => $set('campaign_id', null)),
                Select::make('campaign_id')->label(__('Campagne'))->placeholder(__('Alle campagnes'))
                    ->options(fn (Get $get) => Campaign::query()->when($get('client_id'), fn ($q, $id) => $q->where('client_id', $id))->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn (Get $get) => $get('module') === 'telemarketing')->live(),
                DatePicker::make('from')->label(__('Van'))->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1)->live()->required(),
                DatePicker::make('until')->label(__('Tot en met'))->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1)->live()->required(),
            ]);
    }

    protected function report(): array
    {
        $f = $this->filters;
        $period = CallingReport::period($f['from'] ?? null, $f['until'] ?? null, FilamentTimezone::get());

        $client = $f['client_id'] ? (int) $f['client_id'] : null;
        $work = fn () => new WorkReport(
            \Illuminate\Support\Carbon::parse($f['from'] ?? now()->startOfMonth())->startOfDay(),
            \Illuminate\Support\Carbon::parse($f['until'] ?? now())->endOfDay(),
        );

        return match ($f['module'] ?? 'collection') {
            'telemarketing' => $period->telemarketing($client, $f['campaign_id'] ? (int) $f['campaign_id'] : null),
            'data_entry' => $work()->dataEntry($client),
            'hours' => $work()->hoursOverview($client),
            default => $period->collection($client),
        };
    }

    protected function title(): string
    {
        $f = $this->filters;
        $parts = [match ($f['module'] ?? '') {
            'telemarketing' => __('Rapportage telemarketing'),
            'data_entry' => __('Rapportage data-entry'),
            'hours' => __('Urenoverzicht'),
            default => __('Rapportage pre-incasso'),
        }];
        if ($f['client_id'] ?? null) {
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

        return $schema->components([
            Section::make()->schema([EmbeddedSchema::make('filtersForm')]),
            Section::make($this->title())->description($this->periodLabel())
                ->columns(['default' => 2, 'md' => 4])
                ->schema($entries),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make(__('Per medewerker'))->schema([Html::make($this->table($report['perUser']))]),
                Section::make($report['titles']['perOutcome'] ?? __('Per gespreksresultaat'))->schema([Html::make($this->table($report['perOutcome']))]),
                Section::make(__('Per maand'))->schema([Html::make($this->table($report['perMonth']))]),
            ]),
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

    protected function writeFile(): array
    {
        $name = Str::slug($this->title()).'-'.now()->format('Ymd').'.xlsx';
        $relative = 'companies/'.Filament::getTenant()->slug.'/reports/'.now()->format('Y/m').'/'.Str::random(8).'-'.$name;
        $full = Storage::disk('local')->path($relative);
        @mkdir(dirname($full), 0775, true);
        $rows = CallingReport::toRows($this->report(), $this->title(), $this->periodLabel());
        Spreadsheet::write($full, array_shift($rows), $rows);

        return [$name, $relative];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Exporteren naar Excel'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function () {
                    [$name, $path] = $this->writeFile();

                    return Storage::disk('local')->download($path, $name);
                }),
            Action::make('mail')
                ->label(__('Rapport mailen'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible(fn () => auth()->user()->can('emails.send') && Mailbox::where('is_active', true)->exists())
                ->fillForm(function () {
                    $client = ($id = $this->filters['client_id'] ?? null) ? Client::with('primaryContact')->find($id) : null;

                    return [
                        'mailbox_id' => Mailbox::where('is_active', true)->orderBy('id')->value('id'),
                        'to' => array_values(array_filter([$client?->primaryContact?->email ?? $client?->email])),
                        'subject' => $this->title().' – '.$this->periodLabel(),
                        'body' => __("Beste :name,\n\nIn de bijlage vind je de rapportage over de genoemde periode.\n\nMet vriendelijke groet,\n:sender", [
                            'name' => $client?->primaryContact?->first_name ?? '', 'sender' => auth()->user()->name,
                        ]),
                    ];
                })
                ->schema([
                    Select::make('mailbox_id')->label(__('Versturen vanaf'))
                        ->options(fn () => Mailbox::where('is_active', true)->get()->mapWithKeys(fn ($m) => [$m->id => "{$m->name} <{$m->email}>"]))->required(),
                    TagsInput::make('to')->label(__('Aan'))->nestedRecursiveRules(['email'])->required(),
                    TextInput::make('subject')->label(__('Onderwerp'))->required(),
                    Textarea::make('body')->label(__('Bericht'))->rows(8)->required(),
                ])
                ->modalDescription(__('Het rapport gaat als Excel-bijlage mee.'))
                ->modalSubmitActionLabel(__('Versturen'))
                ->action(function (array $data) {
                    [$name, $path] = $this->writeFile();
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
