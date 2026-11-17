<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Emails\EmailResource;
use App\Filament\Shared\ComposeEmailAction;
use App\Models\EmailMessage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmailsRelationManager extends RelationManager
{
    protected static string $relationship = 'emails';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('E-mail');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->defaultSort('sent_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->columns(EmailResource::emailColumns())
            ->recordUrl(fn (EmailMessage $r) => EmailResource::getUrl('view', ['record' => $r]))
            ->headerActions([
                ComposeEmailAction::make(client: fn () => $this->getOwnerRecord()),
            ]);
    }
}
