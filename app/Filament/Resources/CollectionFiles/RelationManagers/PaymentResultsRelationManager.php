<?php

namespace App\Filament\Resources\CollectionFiles\RelationManagers;

use App\Models\PaymentResult;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Betalingen zoals gemeld door de opdrachtgever (betaalimport). */
class PaymentResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentResults';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Gemelde betalingen');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_at', 'desc')
            ->emptyStateHeading(__('Nog geen betalingen gemeld'))
            ->emptyStateDescription(__('Betalingen komen binnen via de betaalimport van de opdrachtgever.'))
            ->columns([
                TextColumn::make('paid_at')->label(__('Betaaldatum'))->date('d-m-Y')->placeholder('-'),
                TextColumn::make('paid_amount')->label(__('Bedrag'))
                    ->formatStateUsing(fn (PaymentResult $r) => $this->getOwnerRecord()->currency.' '.number_format((float) $r->paid_amount, 2, ',', '.')),
                TextColumn::make('reference')->label(__('Kenmerk'))->placeholder('-'),
                TextColumn::make('created_at')->label(__('Geïmporteerd'))->dateTime('d-m-Y H:i'),
            ]);
    }
}
