<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Support\Billing\Billing;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function afterSave(): void
    {
        $this->record->refresh()->recalculateTotals();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deleteDraft')
                ->label(__('Concept verwijderen'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () {
                    app(Billing::class)->deleteDraft($this->record);
                    $this->redirect(InvoiceResource::getUrl());
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->record]);
    }
}
