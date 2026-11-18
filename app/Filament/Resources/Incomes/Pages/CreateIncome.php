<?php

namespace App\Filament\Resources\Incomes\Pages;

use App\Filament\Resources\Incomes\IncomeResource;
use App\Filament\Shared\Billing\FinanceForm;
use Filament\Resources\Pages\CreateRecord;

class CreateIncome extends CreateRecord
{
    protected static string $resource = IncomeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        FinanceForm::storeAttachment($this->record, $this->form->getRawState());
    }

    protected function getRedirectUrl(): string
    {
        return IncomeResource::getUrl();
    }
}
