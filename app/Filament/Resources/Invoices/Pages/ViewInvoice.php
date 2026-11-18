<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Shared\Billing\BillingActions;
use App\Models\Invoice;
use App\Models\VatRate;
use App\Support\Billing\Billing;
use App\Support\Billing\BillableWork;
use App\Support\Billing\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

/** @property Invoice $record */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        return ($this->record->isCreditNote() ? __('Creditnota') : __('Factuur')).' '.$this->record->displayNumber();
    }

    protected function refreshPage(): void
    {
        $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->record]));
    }

    protected function getHeaderActions(): array
    {
        $manage = fn () => auth()->user()->can('invoices.manage');
        $draft = fn () => $this->record->isDraft();

        return [
            EditAction::make()->label(__('Bewerken'))->visible(fn () => $draft() && $manage()),
            Action::make('finalize')
                ->label(__('Definitief maken'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->visible(fn () => $draft() && $manage())
                ->requiresConfirmation()
                ->modalDescription(__('De factuur krijgt een nummer en kan daarna niet meer worden gewijzigd. Corrigeren gaat dan met een creditnota. Gebruik dit als je de factuur zelf verstuurt.'))
                ->action(function () {
                    try {
                        app(Billing::class)->finalize($this->record);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title(__('Factuur is definitief'))->send();
                    $this->refreshPage();
                }),
            BillingActions::send(fn () => $this->record, fn () => $this->refreshPage()),
            BillingActions::pdf(fn () => $this->record),
            Action::make('payment')
                ->label(__('Betaling registreren'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn () => ! $draft() && $this->record->outstanding() != 0.0 && auth()->user()->can('payments.manage'))
                ->fillForm(fn () => ['amount' => $this->record->outstanding(), 'paid_at' => now()->toDateString(), 'method' => PaymentMethod::Bank->value])
                ->schema([
                    TextInput::make('amount')->label(fn () => __('Bedrag (:cur)', ['cur' => $this->record->currency]))->numeric()->required()
                        ->helperText(fn () => __('Nog open: :amount', ['amount' => Money::format($this->record->outstanding(), $this->record->currency)])),
                    DatePicker::make('paid_at')->label(__('Betaaldatum'))->required()->maxDate(now()),
                    Select::make('method')->label(__('Betaalwijze'))->options(PaymentMethod::class)->required(),
                    TextInput::make('reference')->label(__('Kenmerk / omschrijving'))->maxLength(255),
                ])
                ->action(function (array $data) {
                    $this->record->payments()->create($data + ['user_id' => auth()->id()]);
                    Notification::make()->success()->title(__('Betaling geregistreerd'))->send();
                    $this->refreshPage();
                }),
            Action::make('addWork')
                ->label(__('Uren en commissie toevoegen'))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->visible(fn () => $draft() && $manage() && ! $this->record->isCreditNote())
                ->modalWidth('2xl')
                ->fillForm(fn () => [
                    'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                    'until' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                    'hours' => true, 'commission' => true,
                    'vat_rate_id' => $this->record->lines()->value('vat_rate_id') ?? VatRate::defaultId(),
                ])
                ->schema([
                    DatePicker::make('from')->label(__('Van'))->required()->live(),
                    DatePicker::make('until')->label(__('Tot en met'))->required()->live(),
                    TextEntry::make('available')->label(__('Nog niet gefactureerd in deze periode'))
                        ->state(function (Get $get) {
                            if (! $get('from') || ! $get('until')) {
                                return '-';
                            }
                            $s = app(BillableWork::class)->summary($this->record->client_id, Carbon::parse($get('from')), Carbon::parse($get('until')));

                            return __(':hours uur (waarvan :records records data-entry) · :files dossiers met commissie (:amount)', [
                                'hours' => Money::number($s['hours'], 2), 'records' => $s['records'], 'files' => $s['commission_files'],
                                'amount' => Money::format($s['commission'], $this->record->currency),
                            ]);
                        }),
                    Toggle::make('hours')->label(__('Uren en data-entry toevoegen'))->live(),
                    TextInput::make('hourly_rate')->label(__('Uurtarief'))->numeric()->minValue(0)
                        ->helperText(__('Alleen voor werk zonder eigen prijsafspraak; afspraken uit "Diensten en prijzen" gaan voor.'))
                        ->visible(fn (Get $get) => $get('hours')),
                    Toggle::make('commission')->label(__('Commissie pre-incasso toevoegen (geslaagde dossiers)')),
                    Select::make('vat_rate_id')->label(__('Btw op deze regels'))->options(fn () => VatRate::active()->pluck('name', 'id'))->required(),
                ])
                ->action(function (array $data) {
                    $work = app(BillableWork::class);
                    $from = Carbon::parse($data['from']);
                    $until = Carbon::parse($data['until']);
                    $lines = 0;
                    if ($data['hours'] ?? false) {
                        $lines += $work->addHours($this->record, $from, $until, isset($data['hourly_rate']) ? (float) $data['hourly_rate'] : null, (int) $data['vat_rate_id']);
                    }
                    if ($data['commission'] ?? false) {
                        $lines += $work->addCommission($this->record, $from, $until, (int) $data['vat_rate_id']);
                    }
                    Notification::make()->{$lines ? 'success' : 'warning'}()
                        ->title($lines ? trans_choice(':count regel toegevoegd|:count regels toegevoegd', $lines, ['count' => $lines]) : __('Niets gevonden om te factureren in deze periode.'))
                        ->send();
                    $this->refreshPage();
                }),
            ActionGroup::make([
                Action::make('credit')
                    ->label(__('Creditnota maken'))
                    ->icon(Heroicon::OutlinedReceiptRefund)
                    ->visible(fn () => ! $draft() && ! $this->record->isCreditNote() && $manage())
                    ->requiresConfirmation()
                    ->modalDescription(__('Er wordt een conceptcreditnota gemaakt met alle regels negatief. Die kun je nog aanpassen voor een gedeeltelijke creditering.'))
                    ->action(function () {
                        $credit = app(Billing::class)->creditNote($this->record);
                        $this->redirect(InvoiceResource::getUrl('edit', ['record' => $credit]));
                    }),
                Action::make('duplicate')
                    ->label(__('Kopiëren naar nieuw concept'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->visible($manage)
                    ->action(function () {
                        $copy = app(Billing::class)->duplicate($this->record);
                        $this->redirect(InvoiceResource::getUrl('edit', ['record' => $copy]));
                    }),
                Action::make('cancelInvoice')
                    ->label(__('Laten vervallen'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn () => ! $draft() && (float) $this->record->paid_total == 0.0 && $this->record->status !== \App\Enums\InvoiceStatus::Cancelled && $manage())
                    ->requiresConfirmation()
                    ->modalDescription(__('Alleen gebruiken als de factuur nooit had mogen bestaan. Het nummer blijft bestaan. Voor een correctie op een verstuurde factuur maak je een creditnota.'))
                    ->schema([Textarea::make('reason')->label(__('Reden'))->required()])
                    ->action(function (array $data) {
                        $this->record->forceFill(['status' => \App\Enums\InvoiceStatus::Cancelled])->save();
                        \App\Support\Audit\Audit::log('invoice_cancelled', $this->record, ['reason' => $data['reason']]);
                        $this->refreshPage();
                    }),
            ]),
        ];
    }
}
