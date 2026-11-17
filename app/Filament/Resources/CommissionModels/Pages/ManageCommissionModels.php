<?php

namespace App\Filament\Resources\CommissionModels\Pages;

use App\Filament\Resources\CommissionModels\CommissionModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommissionModels extends ManageRecords
{
    protected static string $resource = CommissionModelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
