<?php

namespace App\Filament\Resources\CollectionFiles\Pages;

use App\Filament\Resources\CollectionFiles\CollectionFileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCollectionFile extends EditRecord
{
    protected static string $resource = CollectionFileResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return CollectionFileResource::getUrl('view', ['record' => $this->record]);
    }
}
