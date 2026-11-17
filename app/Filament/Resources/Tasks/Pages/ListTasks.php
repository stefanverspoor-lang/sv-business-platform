<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        $tabs = [
            'mine' => Tab::make(__('Mijn taken'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            'today' => Tab::make(__('Vandaag en te laat'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())->open()->where('due_at', '<', now()->endOfDay())),
        ];

        if (auth()->user()->can('tasks.view_all')) {
            $tabs['all'] = Tab::make(__('Alle taken'));
        }

        return $tabs;
    }
}
