<?php

namespace App\Filament\Resources\CallOutcomes\Pages;

use App\Filament\Resources\CallOutcomes\CallOutcomeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCallOutcomes extends ManageRecords
{
    protected static string $resource = CallOutcomeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['code' => CallOutcomeResource::newCode($data['label'])]),
        ];
    }
}
