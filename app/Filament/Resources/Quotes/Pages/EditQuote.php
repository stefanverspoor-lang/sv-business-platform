<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function afterSave(): void
    {
        $this->record->refresh()->recalculateTotals();
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->before(fn () => $this->record->lines()->delete())];
    }

    protected function getRedirectUrl(): string
    {
        return QuoteResource::getUrl('view', ['record' => $this->record]);
    }
}
