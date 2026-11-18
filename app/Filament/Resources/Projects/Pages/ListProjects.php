<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'running' => Tab::make(__('Lopend'))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ProjectStatus::running())),
            'maintenance' => Tab::make(__('Onderhoud'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProjectStatus::Maintenance)),
            'all' => Tab::make(__('Alle')),
        ];
    }
}
