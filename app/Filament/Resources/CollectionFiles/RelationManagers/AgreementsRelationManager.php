<?php

namespace App\Filament\Resources\CollectionFiles\RelationManagers;

use App\Models\PaymentAgreement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Betalingsafspraken; vastgelegd vanuit het belscherm. */
class AgreementsRelationManager extends RelationManager
{
    protected static string $relationship = 'agreements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Betalingsafspraken');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $money = fn (PaymentAgreement $r, $amount) => $this->getOwnerRecord()->currency.' '.number_format((float) $amount, 2, ',', '.');

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('terms'))
            ->columns([
                TextColumn::make('created_at')->label(__('Vastgelegd'))->dateTime('d-m-Y H:i'),
                TextColumn::make('type')->label(__('Soort'))->badge(),
                TextColumn::make('total_amount')->label(__('Afgesproken bedrag'))->formatStateUsing(fn (PaymentAgreement $r) => $money($r, $r->total_amount)),
                TextColumn::make('installments')->label(__('Termijnen'))
                    ->formatStateUsing(fn (PaymentAgreement $r) => $r->installments > 1
                        ? $r->installments.' × '.$money($r, $r->installment_amount).' ('.PaymentAgreement::intervals()[$r->interval].')'
                        : __('in één keer')),
                TextColumn::make('first_payment_date')->label(__('Eerste betaling'))->date('d-m-Y'),
                TextColumn::make('terms')->label(__('Schema'))
                    ->state(fn (PaymentAgreement $r) => $r->terms->map(fn ($t) => $t->due_date->format('d-m').': '.number_format((float) $t->amount, 2, ',', '.'))->implode(' · '))
                    ->wrap()->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active' => __('Geldig'), 'replaced' => __('Vervangen'), default => __('Vervallen'),
                    })
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('notes')->label(__('Opmerkingen'))->wrap()->placeholder('-'),
            ]);
    }
}
