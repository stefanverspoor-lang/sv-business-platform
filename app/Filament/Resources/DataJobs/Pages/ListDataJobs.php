<?php

namespace App\Filament\Resources\DataJobs\Pages;

use App\Enums\DataJobStatus;
use App\Filament\Resources\DataJobs\DataJobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDataJobs extends ListRecords
{
    protected static string $resource = DataJobResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'running' => Tab::make(__('Lopend'))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', DataJobStatus::running())),
            'delivered' => Tab::make(__('Opgeleverd'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', DataJobStatus::Delivered)),
            'all' => Tab::make(__('Alle')),
        ];
    }
}
