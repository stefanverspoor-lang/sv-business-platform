<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Support\Billing\Billing;
use App\Support\Billing\BillingSettings;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    public function mount(): void
    {
        parent::mount();
        $defaults = app(Billing::class)->newInvoiceAttributes(['client_id' => request()->integer('client') ?: null]);
        $this->form->fill(array_merge($this->form->getRawState(), [
            'client_id' => $defaults['client_id'] ?? null,
            'issue_date' => $defaults['issue_date'],
            'payment_terms_days' => $defaults['payment_terms_days'],
            'due_date' => $defaults['due_date'],
            'locale' => $defaults['locale'],
            'currency' => BillingSettings::baseCurrency(),
        ]));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(Billing::class)->newInvoiceAttributes($data);
    }

    protected function afterCreate(): void
    {
        $this->record->recalculateTotals();
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->record]);
    }
}
