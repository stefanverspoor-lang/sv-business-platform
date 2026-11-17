<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Tasks\Pages\TaskData;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Taken');
    }

    public static function getModelLabel(): string
    {
        return __('taak');
    }

    public static function getPluralModelLabel(): string
    {
        return __('taken');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(TaskResource::taskFields(withClient: false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('due_at')
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->columns(TaskResource::taskColumns(withClient: false))
            ->filters([
                TernaryFilter::make('open')->label(__('Open taken'))->default(true)
                    ->queries(true: fn (Builder $query) => $query->open(), false: fn (Builder $query) => $query->whereNotNull('completed_at'), blank: fn (Builder $query) => $query),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => TaskData::prepare($data, creating: true)),
            ])
            ->recordActions([
                ...TaskResource::taskActions(),
                DeleteAction::make(),
            ]);
    }
}
