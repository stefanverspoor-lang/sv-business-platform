<?php

namespace App\Filament\Resources\TimeEntries;

use App\Enums\DataJobStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\TimeEntries\Pages\ManageTimeEntries;
use App\Filament\Shared\TimeEntriesRelationManager;
use App\Models\DataJob;
use App\Models\Project;
use App\Models\TimeEntry;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Urenregistratie: eigen uren schrijven en bekijken (met time.view_all ook van anderen). */
class TimeEntryResource extends Resource
{
    protected static ?string $model = TimeEntry::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'uren';

    public static function getModelLabel(): string
    {
        return __('urenregel');
    }

    public static function getPluralModelLabel(): string
    {
        return __('uren');
    }

    public static function getNavigationLabel(): string
    {
        return __('Urenregistratie');
    }

    /** Waar je uren op kunt schrijven: lopende projecten en opdrachten die je mag zien. */
    public static function subjectOptions(): array
    {
        $user = auth()->user();

        return [
            __('Websiteprojecten') => Project::query()->visibleTo($user)->with('client')->orderBy('name')->get()
                ->mapWithKeys(fn (Project $p) => ['project:'.$p->id => $p->name.' ('.$p->client?->name.')'])->all(),
            __('Data-entry') => DataJob::query()->visibleTo($user)->whereIn('status', DataJobStatus::running())->with('client')->orderBy('name')->get()
                ->mapWithKeys(fn (DataJob $j) => ['data_job:'.$j->id => $j->name.' ('.$j->client?->name.')'])->all(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('subject')->label(__('Waaraan gewerkt'))
                ->options(fn () => static::subjectOptions())
                ->searchable()->required()->live()->columnSpanFull()
                ->afterStateHydrated(fn (Select $component, ?TimeEntry $record) => $record ? $component->state($record->timeable_type.':'.$record->timeable_id) : null),
            ...collect(TimeEntriesRelationManager::entryFields(true))
                ->map(fn ($field) => in_array($field->getName(), ['records_done', 'errors_found', 'corrections'], true)
                    ? $field->visible(fn (Get $get) => str_starts_with((string) $get('subject'), 'data_job:'))->required(false)
                    : $field)
                ->all(),
        ]);
    }

    /** Formulier → urenregel, met controle dat je het onderwerp mag zien. */
    public static function prepare(array $data): array
    {
        [$type, $id] = explode(':', (string) $data['subject']) + [null, null];
        $model = $type === 'project' ? Project::class : ($type === 'data_job' ? DataJob::class : null);
        abort_unless($model && ($subject = $model::query()->visibleTo(auth()->user())->find($id)), 403);
        unset($data['subject']);
        if ($type !== 'data_job') {
            unset($data['records_done'], $data['errors_found'], $data['corrections']);
        }

        return TimeEntriesRelationManager::prepare($data) + ['timeable_type' => $type, 'timeable_id' => $subject->getKey()];
    }

    public static function table(Table $table): Table
    {
        $time = fn ($minutes) => sprintf('%d:%02d', intdiv((int) $minutes, 60), (int) $minutes % 60);

        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('timeable'))
            ->columns([
                TextColumn::make('date')->label(__('Datum'))->date('D d-m-Y')->sortable(),
                TextColumn::make('user_id')->label(__('Medewerker'))->formatStateUsing(fn (TimeEntry $r) => $r->userName())
                    ->visible(fn () => auth()->user()->can('time.view_all')),
                TextColumn::make('timeable_id')->label(__('Waaraan gewerkt'))->formatStateUsing(fn (TimeEntry $r) => $r->subjectLabel())->wrap(),
                TextColumn::make('description')->label(__('Omschrijving'))->wrap()->placeholder('-'),
                TextColumn::make('minutes')->label(__('Tijd'))->formatStateUsing(fn ($state) => $time($state))
                    ->summarize(Sum::make()->formatStateUsing(fn ($state) => $time($state))),
                TextColumn::make('records_done')->label(__('Records'))->numeric()->placeholder('-')->summarize(Sum::make()),
                IconColumn::make('billable')->label(__('Factureerbaar'))->boolean(),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('Van'))->default(now()->startOfMonth()),
                        DatePicker::make('until')->label(__('Tot en met')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
                SelectFilter::make('user_id')->label(__('Medewerker'))
                    ->options(fn () => ClientResource::companyUserOptions())
                    ->visible(fn () => auth()->user()->can('time.view_all')),
                SelectFilter::make('timeable_type')->label(__('Soort'))
                    ->options(['project' => __('Websiteproject'), 'project_request' => __('Verzoek'), 'data_job' => __('Data-entry')]),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (TimeEntry $r) => $r->only(['date', 'records_done', 'errors_found', 'corrections', 'user_id', 'description', 'billable'])
                        + ['subject' => $r->timeable_type.':'.$r->timeable_id, 'duration' => $time($r->minutes)])
                    ->mutateDataUsing(fn (array $data) => static::prepare($data)),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getPages(): array
    {
        return ['index' => ManageTimeEntries::route('/')];
    }
}
