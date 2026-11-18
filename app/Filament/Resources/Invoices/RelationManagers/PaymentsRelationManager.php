<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Models\InvoicePayment;
use App\Support\Billing\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Betalingen');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading(__('Nog geen betalingen'))
            ->emptyStateDescription(__('Registreer een betaling met de knop bovenaan.'))
            ->columns([
                TextColumn::make('paid_at')->label(__('Datum'))->date('d-m-Y'),
                TextColumn::make('amount')->label(__('Bedrag'))->formatStateUsing(fn (InvoicePayment $r) => Money::format($r->amount, $this->getOwnerRecord()->currency)),
                TextColumn::make('method')->label(__('Betaalwijze')),
                TextColumn::make('reference')->label(__('Kenmerk'))->placeholder('-'),
            ])
            ->recordActions([
                DeleteAction::make()->authorize(fn () => auth()->user()->can('payments.manage'))
                    ->after(fn () => $this->redirect(\App\Filament\Resources\Invoices\InvoiceResource::getUrl('view', ['record' => $this->getOwnerRecord()]))),
            ]);
    }
}
