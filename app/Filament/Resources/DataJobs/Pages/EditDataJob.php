<?php

namespace App\Filament\Resources\DataJobs\Pages;

use App\Filament\Resources\DataJobs\DataJobResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDataJob extends EditRecord
{
    protected static string $resource = DataJobResource::class;

    protected function afterSave(): void
    {
        $this->record->syncAssignees($this->form->getRawState()['assignees'] ?? []);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return DataJobResource::getUrl('view', ['record' => $this->record]);
    }
}
