<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\Billing\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** De facturen die het langst te laat zijn. */
class OverdueInvoices extends TableWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return auth()->user()->can('invoices.view') && Invoice::unpaid()->whereDate('due_date', '<', today())->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Te laat betaald'))
            ->query(Invoice::unpaid()->whereDate('due_date', '<', today())->with('client'))
            ->defaultSort('due_date')
            ->paginated([5])
            ->columns([
                TextColumn::make('number')->label(__('Factuur')),
                TextColumn::make('client.name')->label(__('Klant')),
                TextColumn::make('due_date')->label(__('Vervaldatum'))->date('d-m-Y')->color('danger')
                    ->description(fn (Invoice $r) => __(':days dagen te laat', ['days' => (int) $r->due_date->diffInDays(today())])),
                TextColumn::make('open')->label(__('Open'))->state(fn (Invoice $r) => Money::format($r->outstanding(), $r->currency)),
            ])
            ->recordUrl(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r]));
    }
}
