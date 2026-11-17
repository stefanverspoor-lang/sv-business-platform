<?php

namespace App\Filament\Pages;

use App\Enums\AgreementType;
use App\Enums\CampaignStatus;
use App\Enums\OutcomeEffect;
use App\Filament\Resources\CollectionFiles\CollectionFileResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\CallOutcome;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\Lead;
use App\Models\PaymentAgreement;
use App\Support\Calling\CallQueue;
use App\Support\Calling\CallRecorder;
use App\Support\Calling\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Throwable;

/**
 * Belscherm voor telemarketing en pre-incasso.
 *
 * Links de gegevens, in het midden BEL NU (opent CloudTalk Desktop), rechts
 * het resultaat. Na opslaan komt automatisch de volgende uit je wachtrij.
 */
class CallScreen extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'belscherm';

    /** leads of collection */
    #[Url]
    public ?string $type = null;

    /** campagne (leads) of opdrachtgever (dossiers) */
    #[Url]
    public ?int $scope = null;

    /** direct een bepaalde lead of een bepaald dossier openen */
    #[Url(as: 'record')]
    public ?int $requestedRecord = null;

    public ?int $currentId = null;

    /** @var array<int, int> overgeslagen in deze sessie */
    public array $skipped = [];

    public int $handled = 0;

    public ?array $data = [];

    protected ?Model $cachedRecord = null;

    public static function getNavigationLabel(): string
    {
        return __('Belscherm');
    }

    public function getTitle(): string
    {
        return __('Belscherm');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('calls.make') ?? false;
    }

    public function mount(): void
    {
        if (! in_array($this->type, [CallQueue::LEADS, CallQueue::COLLECTION], true)) {
            $this->type = null;

            return;
        }

        if ($this->requestedRecord) {
            $record = $this->model()::query()->find($this->requestedRecord);
            if (! $record || ! auth()->user()->can('view', $record)) {
                Notification::make()->danger()->title(__('Niet gevonden of geen toegang.'))->send();
            } elseif ($record->isLockedFor(auth()->user())) {
                Notification::make()->warning()->title(__('Een collega is dit nummer op dit moment aan het bellen.'))->send();
            } else {
                $record->lockFor(auth()->user());
                $this->setCurrent($record);

                return;
            }
        }

        $this->loadNext();
    }

    /** @return class-string<Lead|CollectionFile> */
    protected function model(): string
    {
        return $this->type === CallQueue::LEADS ? Lead::class : CollectionFile::class;
    }

    protected function module(): string
    {
        return $this->type === CallQueue::LEADS ? CallOutcome::MODULE_TELEMARKETING : CallOutcome::MODULE_COLLECTION;
    }

    /** @return Lead|CollectionFile|null */
    public function record(): ?Model
    {
        if (! $this->currentId || ! $this->type) {
            return null;
        }

        return $this->cachedRecord ??= $this->model()::query()
            ->with($this->type === CallQueue::LEADS ? ['campaign', 'lastOutcome'] : ['client', 'lastOutcome', 'activeAgreement'])
            ->find($this->currentId);
    }

    protected function setCurrent(?Model $record): void
    {
        $this->currentId = $record?->getKey();
        $this->cachedRecord = null;
        $this->form->fill($this->defaults());
    }

    protected function loadNext(): void
    {
        $next = app(CallQueue::class)->next(auth()->user(), $this->type, $this->scope, $this->skipped);
        $this->setCurrent($next);
    }

    protected function defaults(): array
    {
        $record = $this->record();

        return [
            'outcome_id' => null,
            'notes' => null,
            'callback_at' => null,
            'agreement_type' => AgreementType::Promise->value,
            'total_amount' => $record instanceof CollectionFile ? (float) $record->outstanding_amount : null,
            'installments' => 3,
            'installment_amount' => null,
            'interval' => 'monthly',
            'first_payment_date' => now()->addWeek()->toDateString(),
            'agreement_notes' => null,
        ];
    }

    protected function outcome(?int $id): ?CallOutcome
    {
        return $id ? CallOutcome::query()->find($id) : null;
    }

    public function form(Schema $schema): Schema
    {
        $effect = fn (Get $get) => $this->outcome((int) $get('outcome_id'))?->effect;
        $isArrangement = fn (Get $get) => in_array($get('agreement_type'), [AgreementType::Arrangement, AgreementType::Arrangement->value], true);
        $outcomes = fn () => CallOutcome::query()->for($this->module())->get();

        return $schema
            ->statePath('data')
            ->components([
                ToggleButtons::make('outcome_id')
                    ->label(__('Resultaat van het gesprek'))
                    ->options(fn () => $outcomes()->mapWithKeys(fn (CallOutcome $o) => [$o->id => $o->displayLabel()])->all())
                    ->colors(fn () => $outcomes()->mapWithKeys(fn (CallOutcome $o) => [$o->id => $o->color])->all())
                    ->columns(2)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        $outcome = $this->outcome((int) $state);
                        if ($outcome?->effect === OutcomeEffect::Agreement) {
                            $set('agreement_type', $outcome->record_status === 'arrangement' ? AgreementType::Arrangement->value : AgreementType::Promise->value);
                        }
                    }),
                DateTimePicker::make('callback_at')
                    ->label(__('Terugbellen op'))
                    ->seconds(false)
                    ->native(false)
                    ->displayFormat('d-m-Y H:i')
                    ->firstDayOfWeek(1)
                    ->minDate(now()->subMinutes(5))
                    ->required(fn (Get $get) => $effect($get) === OutcomeEffect::Callback)
                    ->visible(fn (Get $get) => $effect($get) === OutcomeEffect::Callback)
                    ->helperText(__('In jouw eigen tijdzone.')),
                Grid::make(2)
                    ->visible(fn (Get $get) => $effect($get) === OutcomeEffect::Agreement)
                    ->schema([
                        Select::make('agreement_type')->label(__('Soort afspraak'))->options(AgreementType::class)->required()->live()->columnSpanFull(),
                        TextInput::make('total_amount')->label(__('Afgesproken bedrag'))->numeric()->minValue(0.01)->required()
                            ->prefix(fn () => $this->record()?->currency ?? 'EUR')->live(onBlur: true),
                        DatePicker::make('first_payment_date')
                            ->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1)
                            ->label(fn (Get $get) => $isArrangement($get) ? __('Eerste betaling op') : __('Betaaldatum'))
                            ->required(),
                        TextInput::make('installments')->label(__('Aantal termijnen'))->numeric()->minValue(2)->maxValue(60)
                            ->required(fn (Get $get) => $isArrangement($get))
                            ->visible(fn (Get $get) => $isArrangement($get))->live(onBlur: true),
                        TextInput::make('installment_amount')->label(__('Bedrag per termijn'))->numeric()->minValue(0.01)
                            ->visible(fn (Get $get) => $isArrangement($get))
                            ->placeholder(fn (Get $get) => ($get('total_amount') && $get('installments')) ? number_format((float) $get('total_amount') / max(1, (int) $get('installments')), 2, '.', '') : null)
                            ->helperText(__('Leeg = gelijk verdeeld')),
                        Select::make('interval')->label(__('Termijn'))->options(PaymentAgreement::intervals())
                            ->visible(fn (Get $get) => $isArrangement($get)),
                        Textarea::make('agreement_notes')->label(__('Opmerkingen bij de afspraak'))->rows(2)->columnSpanFull(),
                    ]),
                Textarea::make('notes')->label(__('Gespreksnotitie'))->rows(4),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->type) {
            return $schema->components($this->chooser());
        }

        $record = $this->record();
        if (! $record) {
            return $schema->components([
                Section::make(__('Je wachtrij is leeg'))
                    ->description($this->handled
                        ? trans_choice('Je hebt :count gesprek vastgelegd. Er staat nu niets meer klaar om te bellen.|Je hebt :count gesprekken vastgelegd. Er staat nu niets meer klaar om te bellen.', $this->handled, ['count' => $this->handled])
                        : __('Er staat niets klaar om te bellen. Terugbelafspraken en nieuwe pogingen verschijnen vanzelf op het afgesproken moment.'))
                    ->footerActions([
                        Action::make('back')->label(__('Andere lijst kiezen'))->color('gray')
                            ->url(static::getUrl()),
                        Action::make('retry')->label(__('Opnieuw kijken'))->action(function () {
                            $this->skipped = [];
                            $this->loadNext();
                        }),
                    ]),
            ]);
        }

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make($record->displayName())
                    ->description($this->subtitle($record))
                    ->schema($this->detailEntries($record))
                    ->columnSpan(1),
                Grid::make(1)->schema([
                    Section::make(__('Bellen'))
                        ->schema([
                            Actions::make([
                                Action::make('dial')
                                    ->label(__('BEL NU'))
                                    ->icon(Heroicon::Phone)
                                    ->color('success')
                                    ->size(Size::ExtraLarge)
                                    ->url(PhoneNumber::telLink($record->phone_normalized)),
                                Action::make('dialCloudTalk')
                                    ->label(__('Via CloudTalk'))
                                    ->icon(Heroicon::OutlinedPhoneArrowUpRight)
                                    ->color('gray')
                                    ->url(PhoneNumber::cloudTalkLink($record->phone_normalized)),
                            ])->fullWidth(),
                            Text::make(fn () => $record->phone_normalized)->size('lg')->weight('bold'),
                            Callout::make(__('Let op het tijdstip'))
                                ->description(fn () => __('Bij deze persoon is het nu :time.', ['time' => $record->localTime()?->format('H:i')]))
                                ->warning()
                                ->visible(fn () => ($t = $record->localTime()) && ($t->hour < 8 || $t->hour >= 21)),
                            TextEntry::make('localTime')->label(__('Lokale tijd'))
                                ->state(fn () => $record->localTime()?->format('H:i'))
                                ->visible(fn () => ($t = $record->localTime()) && $t->hour >= 8 && $t->hour < 21),
                            TextEntry::make('alt')->label(__('Ander nummer'))->state($record->phone_alt)
                                ->visible(filled($record->phone_alt)),
                        ]),
                    Section::make(__('Belscript'))
                        ->collapsible()
                        ->schema([Html::make($this->cleanScript($record instanceof Lead ? $record->campaign?->script : null))])
                        ->visible($record instanceof Lead && filled($record->campaign?->script)),
                    Section::make(__('Eerdere gesprekken'))
                        ->collapsible()
                        ->schema([Html::make($this->historyHtml($record))])
                        ->visible($record->attempts > 0),
                ])->columnSpan(1),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('call-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label(__('Opslaan en volgende'))->submit('save')->keyBindings(['mod+s']),
                            Action::make('skip')->label(__('Overslaan'))->color('gray')->action(fn () => $this->skip()),
                            Action::make('stop')->label(__('Stoppen'))->color('gray')->action(fn () => $this->stop()),
                        ]),
                    ])
                    ->columnSpan(1),
            ]),
        ]);
    }

    protected function subtitle(Model $record): string
    {
        return $record instanceof Lead
            ? __('Campagne: :name', ['name' => $record->campaign?->name])
            : __('Dossier :number · :client', ['number' => $record->file_number, 'client' => $record->client?->name]);
    }

    protected function detailEntries(Model $record): array
    {
        $money = fn ($amount) => $record->currency.' '.number_format((float) $amount, 2, ',', '.');

        $common = [
            TextEntry::make('status')->label(__('Status'))->state($record->status)->badge(),
            TextEntry::make('attempts')->label(__('Belpoging'))->state(($record->attempts + 1).' / '.$record->maxAttempts()),
            TextEntry::make('lastOutcome')->label(__('Vorig resultaat'))->state($record->lastOutcome?->displayLabel())->placeholder('-'),
            TextEntry::make('contact')->label(__('Contactpersoon'))->state($record->contact_name)->placeholder('-'),
            TextEntry::make('email')->label(__('E-mail'))->state($record->email)->placeholder('-'),
            TextEntry::make('address')->label(__('Adres'))
                ->state(collect([$record->address_line, trim($record->postal_code.' '.$record->city), $record->country])->filter()->implode(', ') ?: null)
                ->placeholder('-'),
        ];

        if ($record instanceof CollectionFile) {
            array_unshift($common,
                TextEntry::make('outstanding')->label(__('Openstaand bedrag'))->state($money($record->outstanding_amount))->weight('bold')->size('lg'),
                TextEntry::make('invoice')->label(__('Factuur'))
                    ->state(collect([$record->invoice_number, $record->due_date ? __('vervallen :date', ['date' => $record->due_date->format('d-m-Y')]) : null])->filter()->implode(' · ') ?: null)
                    ->placeholder('-'),
                TextEntry::make('debtorType')->label(__('Soort debiteur'))->state($record->debtor_type),
            );
            if ($record->activeAgreement) {
                $common[] = TextEntry::make('agreement')->label(__('Huidige afspraak'))
                    ->state($record->activeAgreement->type->getLabel().': '.$money($record->activeAgreement->total_amount)
                        .', '.__('eerste betaling :date', ['date' => $record->activeAgreement->first_payment_date->format('d-m-Y')]));
            }
        } else {
            $common[] = TextEntry::make('website')->label(__('Website'))->state($record->website)->placeholder('-');
        }

        $common[] = TextEntry::make('notes')->label(__('Notities'))->state($record->notes)->placeholder('-');
        foreach (array_slice($record->extra ?? [], 0, 8, true) as $key => $value) {
            $common[] = TextEntry::make('extra_'.Str::slug($key))->label($key)->state($value);
        }
        $common[] = Actions::make([
            Action::make('openRecord')->label(__('Volledig profiel'))->link()->openUrlInNewTab()
                ->url($record instanceof Lead ? LeadResource::getUrl('view', ['record' => $record]) : CollectionFileResource::getUrl('view', ['record' => $record])),
        ]);

        return $common;
    }

    protected function historyHtml(Model $record): string
    {
        $tz = FilamentTimezone::get();

        return '<ul style="margin:0;padding-left:1rem">'.$record->callAttempts()->with('outcome')->limit(5)->get()->map(function ($a) use ($tz) {
            $line = '<strong>'.e($a->called_at->timezone($tz)->format('d-m H:i')).'</strong> · '.e($a->outcome?->displayLabel() ?? '-');
            $line .= $a->userName() ? ' · '.e($a->userName()) : '';

            return '<li>'.$line.($a->notes ? '<br><span style="opacity:.8">'.e(Str::limit($a->notes, 200)).'</span>' : '').'</li>';
        })->implode('').'</ul>';
    }

    protected function cleanScript(?string $html): string
    {
        $config = (new HtmlSanitizerConfig)->allowSafeElements();

        $clean = (new HtmlSanitizer($config))->sanitize((string) $html);

        // Lijsten zichtbaar maken (de standaardopmaak verbergt opsommingstekens).
        return strtr($clean, [
            '<ul>' => '<ul style="list-style:disc;padding-left:1.25rem;margin:.25rem 0">',
            '<ol>' => '<ol style="list-style:decimal;padding-left:1.25rem;margin:.25rem 0">',
            '<p>' => '<p style="margin:.25rem 0">',
        ]);
    }

    /** Keuzescherm: welke campagne of opdrachtgever ga je bellen? */
    protected function chooser(): array
    {
        $user = auth()->user();
        $queue = app(CallQueue::class);

        $campaignActions = Campaign::query()->visibleTo($user)->where('status', CampaignStatus::Active)->orderBy('name')->get()
            ->map(fn (Campaign $c) => Action::make('c'.$c->id)
                ->label($c->name.' ('.$queue->count($user, CallQueue::LEADS, $c->id).')')
                ->color('gray')
                ->url(static::getUrl(['type' => CallQueue::LEADS, 'scope' => $c->id])))
            ->all();

        $clientIds = CollectionFile::query()->visibleTo($user)->distinct()->pluck('client_id');
        $collectionActions = Client::query()->whereIn('id', $clientIds)->orderBy('name')->get()
            ->map(fn (Client $c) => Action::make('d'.$c->id)
                ->label($c->name.' ('.$queue->count($user, CallQueue::COLLECTION, $c->id).')')
                ->color('gray')
                ->url(static::getUrl(['type' => CallQueue::COLLECTION, 'scope' => $c->id])))
            ->all();

        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Section::make(__('Telemarketing'))
                    ->description(__('Kies een campagne. Tussen haakjes: wat nu klaarstaat om te bellen.'))
                    ->icon(Heroicon::OutlinedMegaphone)
                    ->schema($campaignActions
                        ? [Actions::make([
                            Action::make('allLeads')->label(__('Alle campagnes').' ('.$queue->count($user, CallQueue::LEADS).')')
                                ->url(static::getUrl(['type' => CallQueue::LEADS])),
                            ...$campaignActions,
                        ])]
                        : [Text::make(__('Geen actieve campagnes met leads voor jou.'))]),
                Section::make(__('Pre-incasso'))
                    ->description(__('Kies een opdrachtgever. Tussen haakjes: wat nu klaarstaat om te bellen.'))
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->schema($collectionActions
                        ? [Actions::make([
                            Action::make('allFiles')->label(__('Alle opdrachtgevers').' ('.$queue->count($user, CallQueue::COLLECTION).')')
                                ->url(static::getUrl(['type' => CallQueue::COLLECTION])),
                            ...$collectionActions,
                        ])]
                        : [Text::make(__('Geen dossiers voor jou.'))]),
            ]),
        ];
    }

    public function save(): void
    {
        $record = $this->record();
        if (! $record) {
            return;
        }
        if ($record->isLockedFor(auth()->user())) {
            Notification::make()->danger()->title(__('Een collega heeft dit record overgenomen. Je gesprek is niet opgeslagen.'))->send();
            $this->loadNext();

            return;
        }

        $data = $this->form->getState();
        $outcome = $this->outcome((int) $data['outcome_id']);

        $agreement = null;
        if ($outcome?->effect === OutcomeEffect::Agreement) {
            $agreement = [
                'type' => $data['agreement_type'],
                'total_amount' => $data['total_amount'],
                'installments' => $data['installments'] ?? 1,
                'installment_amount' => $data['installment_amount'] ?? null,
                'interval' => $data['interval'] ?? 'monthly',
                'first_payment_date' => $data['first_payment_date'],
                'notes' => $data['agreement_notes'] ?? null,
            ];
        }

        try {
            app(CallRecorder::class)->record(
                $record,
                auth()->user(),
                $outcome,
                $data['notes'] ?? null,
                filled($data['callback_at'] ?? null) ? \Illuminate\Support\Carbon::parse($data['callback_at']) : null,
                $agreement,
            );
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('Opslaan mislukt'))->body($e->getMessage())->send();

            return;
        }

        $this->handled++;
        Notification::make()->success()->title(__('Opgeslagen: :name', ['name' => $record->displayName()]))->send();
        $this->requestedRecord = null;
        $this->loadNext();
    }

    public function skip(): void
    {
        if ($record = $this->record()) {
            $record->unlock();
            $this->skipped[] = $record->getKey();
        }
        $this->requestedRecord = null;
        $this->loadNext();
    }

    public function stop(): void
    {
        $this->record()?->unlock();
        $this->redirect($this->type === CallQueue::LEADS ? LeadResource::getUrl() : CollectionFileResource::getUrl());
    }
}
