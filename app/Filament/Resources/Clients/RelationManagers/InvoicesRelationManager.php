<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\Billing\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Facturen');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('invoices.view') || auth()->user()->can('invoices.manage');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Nummer'))->formatStateUsing(fn (Invoice $r) => $r->displayNumber())->default('-'),
                TextColumn::make('issue_date')->label(__('Datum'))->date('d-m-Y'),
                TextColumn::make('total')->label(__('Totaal'))->formatStateUsing(fn (Invoice $r) => Money::format($r->total, $r->currency)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (Invoice $r) => $r->isOverdue() ? __('Te laat') : $r->status->getLabel())
                    ->color(fn (Invoice $r) => $r->isOverdue() ? 'danger' : $r->status->getColor()),
            ])
            ->recordUrl(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r]))
            ->headerActions([
                Action::make('newInvoice')->label(__('Nieuwe factuur'))->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => auth()->user()->can('invoices.manage'))
                    ->url(fn () => InvoiceResource::getUrl('create', ['client' => $this->getOwnerRecord()->id])),
            ]);
    }
}
