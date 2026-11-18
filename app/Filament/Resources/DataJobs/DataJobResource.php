<?php

namespace App\Filament\Resources\DataJobs;

use App\Enums\DataJobStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\RelationManagers\TasksRelationManager;
use App\Filament\Resources\DataJobs\Pages\CreateDataJob;
use App\Filament\Resources\DataJobs\Pages\EditDataJob;
use App\Filament\Resources\DataJobs\Pages\ListDataJobs;
use App\Filament\Resources\DataJobs\Pages\ViewDataJob;
use App\Filament\Shared\DocumentsRelationManager;
use App\Filament\Shared\TimeEntriesRelationManager;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\DataJob;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DataJobResource extends Resource
{
    protected static ?string $model = DataJob::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'data-entry';

    public static function getModelLabel(): string
    {
        return __('opdracht');
    }

    public static function getPluralModelLabel(): string
    {
        return __('opdrachten');
    }

    public static function getNavigationLabel(): string
    {
        return __('Opdrachten');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Data-entry');
    }

    public static function serviceLabel(ClientService $s): string
    {
        return collect([$s->description ?: $s->service->getLabel(), $s->pricing_model?->getLabel(),
            $s->price !== null ? $s->currency.' '.number_format((float) $s->price, 2, ',', '.') : null])->filter()->implode(' · ');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Opdracht'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Naam'))->required()->maxLength(255),
                    Select::make('client_id')->label(__('Opdrachtgever'))
                        ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required()->live(),
                    Select::make('client_service_id')->label(__('Prijsafspraak'))
                        ->options(fn (Get $get) => $get('client_id')
                            ? ClientService::where('client_id', $get('client_id'))->where('is_active', true)
                                ->orderByRaw("case when service = 'data_entry' then 0 else 1 end")->get()
                                ->mapWithKeys(fn (ClientService $s) => [$s->id => static::serviceLabel($s)])
                            : [])
                        ->placeholder(__('Geen'))
                        ->helperText(__('Prijzen stel je per klant in onder "Diensten en prijzen" (per record, per uur, per project, maandbedrag of eigen afspraak).')),
                    Select::make('status')->label(__('Status'))->options(DataJobStatus::class)->default(DataJobStatus::New)->required(),
                    TextInput::make('total_records')->label(__('Aantal te verwerken records'))->numeric()->minValue(0)->default(0)->required(),
                    DatePicker::make('deadline')->label(__('Deadline')),
                    Select::make('assignees')->label(__('Medewerkers'))->multiple()
                        ->options(fn () => ClientResource::companyUserOptions())
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (Select $component, ?DataJob $record) => $component->state($record?->assigneeIds() ?? []))
                        ->columnSpanFull(),
                    Textarea::make('description')->label(__('Omschrijving'))->rows(2)->columnSpanFull(),
                    Textarea::make('instructions')->label(__('Werkinstructies'))->rows(5)->columnSpanFull()
                        ->helperText(__('Zien de medewerkers bij de opdracht.')),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('client.name')->label(__('Opdrachtgever')),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('deadline')->label(__('Deadline'))->date('d-m-Y')->placeholder('-')
                        ->color(fn (DataJob $r) => $r->isOverdue() ? 'danger' : null),
                    TextEntry::make('assignees')->label(__('Medewerkers'))
                        ->state(fn (DataJob $r) => $r->assignees()->pluck('name')->implode(', ') ?: null)->placeholder('-'),
                    TextEntry::make('progress')->label(__('Voortgang'))
                        ->state(fn (DataJob $r) => number_format($r->recordsDone(), 0, ',', '.').' / '.number_format($r->total_records, 0, ',', '.')
                            .(($p = $r->progress()) !== null ? ' ('.number_format($p, 1, ',', '.').'%)' : ''))
                        ->weight('bold')->size('lg'),
                    TextEntry::make('errors')->label(__('Fouten / correcties'))
                        ->state(fn (DataJob $r) => (int) $r->timeEntries()->sum('errors_found').' / '.(int) $r->timeEntries()->sum('corrections')),
                    TextEntry::make('hours')->label(__('Uren'))
                        ->state(fn (DataJob $r) => number_format($r->hours(), 1, ',', '.')),
                    TextEntry::make('speed')->label(__('Records per uur'))
                        ->state(fn (DataJob $r) => $r->hours() > 0 ? number_format($r->recordsDone() / $r->hours(), 1, ',', '.') : '-'),
                    TextEntry::make('service')->label(__('Prijsafspraak'))
                        ->state(fn (DataJob $r) => $r->service ? static::serviceLabel($r->service) : null)->placeholder('-'),
                    TextEntry::make('value')->label(__('Waarde tot nu toe'))
                        ->state(fn (DataJob $r) => ($v = $r->value()) !== null ? ($r->service->currency ?? 'EUR').' '.number_format($v, 2, ',', '.') : null)
                        ->placeholder(__('niet per opdracht te berekenen')),
                    TextEntry::make('delivered_at')->label(__('Opgeleverd'))->dateTime('d-m-Y H:i')->placeholder('-'),
                    TextEntry::make('delivery_notes')->label(__('Opleveringsnotitie'))->placeholder('-'),
                    TextEntry::make('instructions')->label(__('Werkinstructies'))->placeholder('-')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('deadline')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client')->withSum('timeEntries as records_done_sum', 'records_done'))
            ->columns([
                TextColumn::make('name')->label(__('Opdracht'))->searchable()->sortable()->description(fn (DataJob $r) => $r->client?->name),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('records_done_sum')->label(__('Voortgang'))
                    ->formatStateUsing(fn (DataJob $r) => number_format((int) $r->records_done_sum, 0, ',', '.').' / '.number_format($r->total_records, 0, ',', '.')
                        .($r->total_records ? ' ('.min(100, round((int) $r->records_done_sum / $r->total_records * 100)).'%)' : ''))
                    ->default(0),
                TextColumn::make('deadline')->label(__('Deadline'))->date('d-m-Y')->sortable()->placeholder('-')
                    ->color(fn (DataJob $r) => $r->isOverdue() ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(DataJobStatus::class)->multiple(),
                SelectFilter::make('client_id')->label(__('Opdrachtgever'))
                    ->options(fn () => Client::query()->whereHas('dataJobs')->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordUrl(fn (DataJob $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([EditAction::make()->iconButton()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getRelations(): array
    {
        return [TimeEntriesRelationManager::class, DocumentsRelationManager::class, TasksRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataJobs::route('/'),
            'create' => CreateDataJob::route('/create'),
            'view' => ViewDataJob::route('/{record}'),
            'edit' => EditDataJob::route('/{record}/edit'),
        ];
    }
}
