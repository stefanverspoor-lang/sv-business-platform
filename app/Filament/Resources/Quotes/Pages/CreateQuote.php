<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\VatRate;
use App\Support\Billing\BillingSettings;
use App\Support\Tenancy\TenantManager;
use Filament\Resources\Pages\CreateRecord;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->form->fill(array_merge($this->form->getRawState(), [
            'client_id' => request()->integer('client') ?: null,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays((int) BillingSettings::get('quote_valid_days'))->toDateString(),
            'locale' => app(TenantManager::class)->current()?->default_locale ?: 'nl',
            'currency' => BillingSettings::baseCurrency(),
        ]));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['status' => QuoteStatus::Draft, 'created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        $this->record->recalculateTotals();
    }

    protected function getRedirectUrl(): string
    {
        return QuoteResource::getUrl('view', ['record' => $this->record]);
    }
}
