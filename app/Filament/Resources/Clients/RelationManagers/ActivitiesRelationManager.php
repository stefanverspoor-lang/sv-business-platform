<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Enums\ActivityType;
use App\Models\Activity;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tijdlijn: alle contacthistorie van de klant op één plek.
 * Notities, gesprekken en afspraken leg je zelf vast; e-mails, taken en
 * documenten verschijnen automatisch.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Tijdlijn');
    }

    public static function getModelLabel(): string
    {
        return __('notitie');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notities');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label(__('Soort'))
                ->options(collect(ActivityType::manual())->mapWithKeys(fn ($t) => [$t->value => $t->getLabel()]))
                ->default(ActivityType::Note->value)->required(),
            DateTimePicker::make('occurred_at')->label(__('Datum en tijd'))->default(now())->required()->seconds(false),
            TextInput::make('title')->label(__('Onderwerp'))->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('body')->label(__('Notitie'))->rows(5)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('occurred_at')->label(__('Wanneer'))->dateTime('d-m-Y H:i')->sortable(),
                TextColumn::make('type')->label(__('Soort'))->badge(),
                TextColumn::make('title')->label(__('Onderwerp'))->searchable()->wrap()
                    ->description(fn (Activity $r) => $r->body ? str($r->body)->limit(160) : null),
                TextColumn::make('user_id')->label(__('Door'))->formatStateUsing(fn (Activity $r) => $r->userName()),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Soort'))->options(ActivityType::class),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Notitie toevoegen'))
                    ->mutateDataUsing(fn (array $data) => $data + ['user_id' => auth()->id()]),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Activity $r) => in_array($r->type, ActivityType::manual(), true) && $r->user_id === auth()->id()),
                DeleteAction::make()->visible(fn (Activity $r) => in_array($r->type, ActivityType::manual(), true) && ($r->user_id === auth()->id() || auth()->user()->isSuperadmin())),
            ]);
    }
}
