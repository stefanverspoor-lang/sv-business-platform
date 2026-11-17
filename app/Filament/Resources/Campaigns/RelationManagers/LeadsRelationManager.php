<?php

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Filament\Resources\Leads\LeadResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeadsRelationManager extends RelationManager
{
    protected static string $relationship = 'leads';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Leads');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return LeadResource::configureLeadTable($table, withCampaign: false)
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleTo(auth()->user())->with('lastOutcome'));
    }
}
