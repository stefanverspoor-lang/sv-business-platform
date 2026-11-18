<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\ProjectRequest;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Wijzigingsverzoeken en supporthistorie van een websiteproject. */
class RequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'requests';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Verzoeken en support');
    }

    public static function getModelLabel(): string
    {
        return __('verzoek');
    }

    public static function getPluralModelLabel(): string
    {
        return __('verzoeken');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label(__('Soort'))->options(RequestType::class)->default(RequestType::Change)->required(),
            Select::make('status')->label(__('Status'))->options(RequestStatus::class)->default(RequestStatus::Open)->required(),
            TextInput::make('title')->label(__('Onderwerp'))->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->label(__('Omschrijving'))->rows(4)->columnSpanFull(),
            TextInput::make('requested_by')->label(__('Aangevraagd door'))->maxLength(255),
            DateTimePicker::make('requested_at')->label(__('Aangevraagd op'))->default(now())->seconds(false),
            Select::make('assigned_to')->label(__('Uitgevoerd door'))->options(fn () => ClientResource::companyUserOptions())->searchable(),
            TextInput::make('estimated_hours')->label(__('Geschatte uren'))->numeric()->minValue(0),
            Toggle::make('billable')->label(__('Factureerbaar'))->default(true)->inline(false),
            TextInput::make('price')->label(__('Afgesproken prijs'))->numeric()->minValue(0),
            Textarea::make('resolution')->label(__('Oplossing / wat is er gedaan'))->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('requested_at')->label(__('Datum'))->date('d-m-Y')->sortable()->placeholder('-'),
                TextColumn::make('type')->label(__('Soort'))->badge(),
                TextColumn::make('title')->label(__('Onderwerp'))->wrap()->searchable()->description(fn (ProjectRequest $r) => $r->requested_by),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('hours')->label(__('Uren'))
                    ->state(fn (ProjectRequest $r) => number_format($r->timeEntries()->sum('minutes') / 60, 1, ',', '.')
                        .($r->estimated_hours ? ' / '.number_format((float) $r->estimated_hours, 1, ',', '.') : '')),
                IconColumn::make('billable')->label(__('Factureerbaar'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(RequestStatus::class)->multiple()
                    ->default([RequestStatus::Open->value, RequestStatus::InProgress->value]),
                SelectFilter::make('type')->label(__('Soort'))->options(RequestType::class),
            ])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])])
            ->recordActions([
                Action::make('logTime')
                    ->label(__('Uren'))
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->schema(\App\Filament\Shared\TimeEntriesRelationManager::entryFields(false))
                    ->action(function (ProjectRequest $record, array $data) {
                        $record->timeEntries()->create(\App\Filament\Shared\TimeEntriesRelationManager::prepare($data));
                        Notification::make()->success()->title(__('Uren opgeslagen'))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
