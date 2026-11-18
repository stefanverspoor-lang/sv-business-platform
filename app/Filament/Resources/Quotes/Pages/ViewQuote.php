<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Shared\Billing\BillingActions;
use App\Models\Quote;
use App\Support\Billing\Billing;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** @property Quote $record */
class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    public function getTitle(): string
    {
        return __('Offerte').' '.$this->record->displayNumber();
    }

    protected function getHeaderActions(): array
    {
        $manage = fn () => auth()->user()->can('invoices.manage');
        $open = fn () => in_array($this->record->status, [QuoteStatus::Draft, QuoteStatus::Sent], true);
        $refresh = fn () => $this->redirect(QuoteResource::getUrl('view', ['record' => $this->record]));

        return [
            EditAction::make()->visible(fn () => $this->record->isDraft() && $manage()),
            BillingActions::send(fn () => $this->record, $refresh)->visible(fn () => $open() && $manage() && auth()->user()->can('emails.send')),
            BillingActions::pdf(fn () => $this->record),
            Action::make('accept')->label(__('Geaccepteerd'))->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(fn () => $open() && $manage())
                ->action(function () use ($refresh) {
                    $this->record->forceFill(['status' => QuoteStatus::Accepted, 'decided_at' => now()])->save();
                    $refresh();
                }),
            Action::make('decline')->label(__('Afgewezen'))->icon(Heroicon::OutlinedHandThumbDown)->color('gray')
                ->visible(fn () => $open() && $manage())
                ->requiresConfirmation()
                ->action(function () use ($refresh) {
                    $this->record->forceFill(['status' => QuoteStatus::Declined, 'decided_at' => now()])->save();
                    $refresh();
                }),
            Action::make('toInvoice')->label(__('Omzetten naar factuur'))->icon(Heroicon::OutlinedArrowRightCircle)
                ->visible(fn () => ! $this->record->invoice_id && in_array($this->record->status, [QuoteStatus::Sent, QuoteStatus::Accepted, QuoteStatus::Draft], true) && $manage())
                ->requiresConfirmation()
                ->modalDescription(__('Er wordt een conceptfactuur gemaakt met dezelfde regels.'))
                ->action(function () {
                    $invoice = app(Billing::class)->convertQuote($this->record);
                    $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                }),
        ];
    }
}
