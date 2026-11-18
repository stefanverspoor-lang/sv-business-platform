<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Je eerstvolgende open taken. */
class MyTasks extends TableWidget
{
    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return Task::query()->open()->where('assigned_to', auth()->id())->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Mijn taken'))
            ->query(Task::query()->open()->where('assigned_to', auth()->id())->with('taskable'))
            ->defaultSort(fn ($query) => $query->orderByRaw('due_at is null')->orderBy('due_at'))
            ->paginated([5])
            ->columns([
                TextColumn::make('title')->label(__('Taak'))->wrap()->description(fn (Task $r) => $r->taskable?->name ?? $r->taskable?->title ?? null),
                TextColumn::make('due_at')->label(__('Deadline'))->dateTime('d-m-Y H:i')->placeholder('-')
                    ->color(fn (Task $r) => $r->isOverdue() ? 'danger' : null),
                TextColumn::make('priority')->label(__('Prioriteit'))->badge(),
            ])
            ->recordActions([
                Action::make('done')->label(__('Afronden'))->icon(Heroicon::OutlinedCheck)->color('success')->iconButton()
                    ->action(fn (Task $r) => $r->update(['status' => TaskStatus::Done])),
            ])
            ->recordUrl(fn (Task $r) => TaskResource::getUrl('edit', ['record' => $r]));
    }
}
