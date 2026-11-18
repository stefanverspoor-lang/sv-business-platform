<?php

namespace App\Filament\Resources\TimeEntries\Pages;

use App\Filament\Resources\TimeEntries\TimeEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageTimeEntries extends ManageRecords
{
    protected static string $resource = TimeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Uren schrijven'))
                ->mutateDataUsing(fn (array $data) => TimeEntryResource::prepare($data)),
        ];
    }

    public function getTabs(): array
    {
        return [
            'mine' => Tab::make(__('Mijn uren'))->modifyQueryUsing(fn (Builder $query) => $query->where('user_id', auth()->id())),
            'week' => Tab::make(__('Deze week'))->modifyQueryUsing(fn (Builder $query) => $query->where('user_id', auth()->id())
                ->whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])),
        ] + (auth()->user()->can('time.view_all') ? ['all' => Tab::make(__('Iedereen'))] : []);
    }
}
