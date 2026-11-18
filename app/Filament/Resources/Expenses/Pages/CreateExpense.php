<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Shared\Billing\FinanceForm;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

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
        return ExpenseResource::getUrl();
    }
}
