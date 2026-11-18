<?php

namespace App\Filament\Resources\DataJobs\Pages;

use App\Filament\Resources\DataJobs\DataJobResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDataJob extends CreateRecord
{
    protected static string $resource = DataJobResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        $this->record->syncAssignees($this->form->getRawState()['assignees'] ?? []);
    }

    protected function getRedirectUrl(): string
    {
        return DataJobResource::getUrl('view', ['record' => $this->record]);
    }
}
