<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        // Wie een klant aanmaakt zonder alle klanten te mogen zien, wordt
        // accountmanager, zodat de klant daarna zichtbaar blijft.
        if (empty($data['account_manager_id']) && ! auth()->user()->can('clients.view_all')) {
            $data['account_manager_id'] = auth()->id();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
