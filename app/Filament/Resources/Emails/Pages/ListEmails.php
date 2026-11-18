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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', 'in')->where('is_spam', false)),
            'mine' => Tab::make(__('Aan mij toegewezen'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())->where('is_spam', false)),
            'sent' => Tab::make(__('Verzonden'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', 'out')),
            'spam' => Tab::make(__('Spam'))
                ->badge(fn () => EmailResource::getEloquentQuery()->where('is_spam', true)->where('is_read', false)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_spam', true)),
            'all' => Tab::make(__('Alles')),
        ];
    }
}
