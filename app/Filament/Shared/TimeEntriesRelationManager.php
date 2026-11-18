<?php

namespace App\Filament\Shared;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\DataJob;
use App\Models\TimeEntry;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Uren (en bij data-entry: voortgang) bij een project, verzoek of opdracht. */
class TimeEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timeEntries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof DataJob ? __('Voortgang en uren') : __('Uren');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function isDataJob(): bool
    {
        return $this->getOwnerRecord() instanceof DataJob;
    }

    /** Velden voor een urenregel; ook gebruikt door "Voortgang registreren" en de urenpagina. */
    public static function entryFields(bool $production): array
    {
        return array_values(array_filter([
            DatePicker::make('date')->label(__('Datum'))->default(now())->maxDate(now()->addDay())->required()
                ->native(false)->displayFormat('d-m-Y')->firstDayOfWeek(1),
            TextInput::make('duration')->label(__('Tijd'))->required()
                ->placeholder('1:30')
                ->helperText(__('Bijv. 1:30, 1,5 of 90m'))
                ->rule(fn () => function ($attribute, $value, $fail) {
                    $minutes = TimeEntry::parseDuration($value);
                    if (! $minutes || $minutes > 24 * 60) {
                        $fail(__('Vul een geldige tijd in (maximaal 24 uur).'));
                    }
                }),
            $production ? TextInput::make('records_done')->label(__('Records verwerkt'))->numeric()->minValue(0)->default(0)->required() : null,
            $production ? TextInput::make('errors_found')->label(__('Fouten gevonden'))->numeric()->minValue(0)->default(0) : null,
            $production ? TextInput::make('corrections')->label(__('Correcties'))->numeric()->minValue(0)->default(0) : null,
            Select::make('user_id')->label(__('Medewerker'))
                ->options(fn () => ClientResource::companyUserOptions())
                ->default(fn () => auth()->id())->required()
                ->visible(fn () => auth()->user()->can('time.view_all')),
            TextInput::make('description')->label(__('Omschrijving'))->maxLength(500)->columnSpanFull(),
            Toggle::make('billable')->label(__('Factureerbaar'))->default(true),
        ]));
    }

    /** Formuliergegevens → urenregel. */
    public static function prepare(array $data): array
    {
        $data['minutes'] = TimeEntry::parseDuration($data['duration'] ?? null);
        unset($data['duration']);
        if (! auth()->user()->can('time.view_all') || empty($data['user_id'])) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(static::entryFields($this->isDataJob()));
    }

    public function table(Table $table): Table
    {
        $production = $this->isDataJob();

        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->visibleTo(auth()->user()))
            ->columns(array_values(array_filter([
                TextColumn::make('date')->label(__('Datum'))->date('d-m-Y')->sortable(),
                TextColumn::make('user_id')->label(__('Medewerker'))->formatStateUsing(fn (TimeEntry $r) => $r->userName()),
                TextColumn::make('minutes')->label(__('Tijd'))->formatStateUsing(fn (TimeEntry $r) => sprintf('%d:%02d', intdiv($r->minutes, 60), $r->minutes % 60))
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->formatStateUsing(fn ($state) => sprintf('%d:%02d', intdiv((int) $state, 60), (int) $state % 60))),
                $production ? TextColumn::make('records_done')->label(__('Records'))->numeric()->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()) : null,
                $production ? TextColumn::make('errors_found')->label(__('Fouten'))->numeric()->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()) : null,
                $production ? TextColumn::make('corrections')->label(__('Correcties'))->numeric()->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()) : null,
                TextColumn::make('description')->label(__('Omschrijving'))->wrap()->placeholder('-'),
                IconColumn::make('billable')->label(__('Factureerbaar'))->boolean(),
            ])))
            ->headerActions([
                CreateAction::make()
                    ->label($production ? __('Voortgang registreren') : __('Uren schrijven'))
                    ->visible(fn () => ! $production || auth()->user()->can('logProgress', $this->getOwnerRecord()))
                    ->mutateDataUsing(fn (array $data) => static::prepare($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (TimeEntry $r) => $r->only(['date', 'records_done', 'errors_found', 'corrections', 'user_id', 'description', 'billable'])
                        + ['duration' => sprintf('%d:%02d', intdiv($r->minutes, 60), $r->minutes % 60)])
                    ->mutateDataUsing(fn (array $data) => static::prepare($data)),
                DeleteAction::make(),
            ]);
    }
}
