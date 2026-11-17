<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\Recurrence;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Client;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('taak');
    }

    public static function getPluralModelLabel(): string
    {
        return __('taken');
    }

    public static function getNavigationLabel(): string
    {
        return __('Taken');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('CRM');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Task::query()->open()->where('assigned_to', auth()->id())->where('due_at', '<', now()->endOfDay())->count();

        return $count ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Velden voor taken; ook gebruikt in het klantprofiel. */
    public static function taskFields(bool $withClient = true): array
    {
        return array_values(array_filter([
            TextInput::make('title')->label(__('Taak'))->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->label(__('Omschrijving'))->rows(3)->columnSpanFull(),
            $withClient ? Select::make('taskable_id')->label(__('Klant'))
                ->options(fn () => Client::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))
                ->searchable() : null,
            Select::make('assigned_to')->label(__('Toegewezen aan'))
                ->options(fn () => ClientResource::companyUserOptions())
                ->default(fn () => auth()->id())
                ->searchable()
                ->disabled(fn () => ! auth()->user()->can('tasks.manage'))
                ->dehydrated(),
            Select::make('status')->label(__('Status'))->options(TaskStatus::class)->default(TaskStatus::New)->required(),
            Select::make('priority')->label(__('Prioriteit'))->options(TaskPriority::class)->default(TaskPriority::Normal)->required(),
            DateTimePicker::make('due_at')->label(__('Deadline'))->seconds(false),
            DateTimePicker::make('remind_at')->label(__('Herinnering'))->seconds(false)
                ->helperText(__('Je krijgt op dat moment een melding in het CRM.')),
            Select::make('recurrence')->label(__('Herhalen'))->options(Recurrence::class)->default(Recurrence::None)->required()
                ->helperText(__('Na afronden wordt de volgende taak automatisch aangemaakt.')),
        ]));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema(static::taskFields()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->columns(static::taskColumns(withClient: true))
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(TaskStatus::class)->multiple()
                    ->default([TaskStatus::New->value, TaskStatus::InProgress->value, TaskStatus::Waiting->value]),
                SelectFilter::make('priority')->label(__('Prioriteit'))->options(TaskPriority::class),
                SelectFilter::make('assigned_to')->label(__('Toegewezen aan'))
                    ->options(fn () => ClientResource::companyUserOptions())
                    ->visible(fn () => auth()->user()->can('tasks.view_all')),
                TernaryFilter::make('overdue')->label(__('Te laat'))
                    ->queries(true: fn (Builder $query) => $query->overdue(), false: fn (Builder $query) => $query, blank: fn (Builder $query) => $query),
            ])
            ->recordActions(static::taskActions())
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function taskColumns(bool $withClient): array
    {
        return array_values(array_filter([
            TextColumn::make('title')->label(__('Taak'))->searchable()->wrap()
                ->description(fn (Task $r) => $r->recurrence !== Recurrence::None ? $r->recurrence->getLabel() : null),
            $withClient ? TextColumn::make('taskable.name')->label(__('Klant'))
                ->url(fn (Task $r) => $r->taskable instanceof Client ? ClientResource::getUrl('view', ['record' => $r->taskable]) : null) : null,
            TextColumn::make('assigned_to')->label(__('Toegewezen aan'))->formatStateUsing(fn (Task $r) => $r->assigneeName()),
            TextColumn::make('status')->label(__('Status'))->badge(),
            TextColumn::make('priority')->label(__('Prioriteit'))->badge(),
            TextColumn::make('due_at')->label(__('Deadline'))->dateTime('d-m-Y H:i')->sortable()
                ->color(fn (Task $r) => $r->isOverdue() ? 'danger' : null),
        ]));
    }

    public static function taskActions(): array
    {
        return [
            Action::make('complete')
                ->label(__('Afronden'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn (Task $r) => $r->status !== TaskStatus::Done && auth()->user()->can('update', $r))
                ->action(fn (Task $r) => $r->update(['status' => TaskStatus::Done])),
            EditAction::make(),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('taskable')->visibleTo(auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }
}
