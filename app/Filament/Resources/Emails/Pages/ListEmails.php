<?php

namespace App\Filament\Resources\Emails\Pages;

use App\Filament\Resources\Emails\EmailResource;
use App\Filament\Shared\ComposeEmailAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEmails extends ListRecords
{
    protected static string $resource = EmailResource::class;

    protected function getHeaderActions(): array
    {
        return [ComposeEmailAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'inbox' => Tab::make(__('Postvak IN'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', 'in')),
            'mine' => Tab::make(__('Aan mij toegewezen'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            'sent' => Tab::make(__('Verzonden'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', 'out')),
            'all' => Tab::make(__('Alles')),
        ];
    }
}
