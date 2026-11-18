<?php

namespace App\Support\Reports;

use App\Enums\InvoiceStatus;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Support\Billing\BillingSettings;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Financieel overzicht per bedrijf, in de basisvaluta. Omzet = facturen
 * (excl. btw, op factuurdatum, creditnota's negatief) + overige inkomsten.
 * Geen vervanging van een wettelijke boekhouding.
 */
class FinanceReport
{
    public string $base;

    public function __construct(public Carbon $from, public Carbon $until)
    {
        $this->base = BillingSettings::baseCurrency();
    }

    public function invoices(): Builder
    {
        return Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->whereBetween('issue_date', [$this->from->toDateString(), $this->until->toDateString()]);
    }

    public function expenses(): Builder
    {
        return Expense::query()->whereBetween('date', [$this->from->toDateString(), $this->until->toDateString()]);
    }

    public function incomes(): Builder
    {
        return Income::query()->whereBetween('date', [$this->from->toDateString(), $this->until->toDateString()]);
    }

    protected function sumBase(Collection $rows, string $column): float
    {
        return round($rows->sum(fn ($r) => (float) $r->{$column} * (float) $r->exchange_rate), 2);
    }

    public function money(float $amount): string
    {
        return Money::format($amount, $this->base);
    }

    public function build(): array
    {
        $invoices = $this->invoices()->with('client')->get();
        $expenses = $this->expenses()->with('category')->get();
        $incomes = $this->incomes()->with('category')->get();

        $revenue = $this->sumBase($invoices, 'subtotal');
        $other = $this->sumBase($incomes, 'amount_excl');
        $costs = $this->sumBase($expenses, 'amount_excl');
        $vatCharged = $this->sumBase($invoices, 'vat_total') + $this->sumBase($incomes, 'vat_amount');
        $vatPaid = $this->sumBase($expenses, 'vat_amount');

        $open = Invoice::unpaid()->with('client')->get();
        $openAmount = round($open->sum(fn (Invoice $i) => $i->outstanding() * (float) $i->exchange_rate), 2);
        $overdue = $open->filter(fn (Invoice $i) => $i->isOverdue());
        $unpaidExpenses = Expense::whereNull('paid_at')->get();

        $summary = [
            __('Omzet (facturen, excl. btw)') => $this->money($revenue),
            __('Overige inkomsten') => $this->money($other),
            __('Kosten (excl. btw)') => $this->money($costs),
            __('Resultaat') => $this->money($revenue + $other - $costs),
            __('Btw gefactureerd') => $this->money($vatCharged),
            __('Btw betaald (voorbelasting)') => $this->money($vatPaid),
            __('Btw-saldo') => $this->money($vatCharged - $vatPaid),
            __('Facturen in periode') => $invoices->count(),
            __('Nog te ontvangen (alle open facturen)') => $this->money($openAmount),
            __('Waarvan te laat') => $this->money(round($overdue->sum(fn (Invoice $i) => $i->outstanding() * (float) $i->exchange_rate), 2)).' ('.$overdue->count().')',
            __('Nog te betalen (uitgaven)') => $this->money($this->sumBase($unpaidExpenses, 'total')).' ('.$unpaidExpenses->count().')',
            __('Ontvangen betalingen in periode') => $this->money(round(InvoicePayment::query()->with('invoice')
                ->whereBetween('paid_at', [$this->from->toDateString(), $this->until->toDateString()])->get()
                ->sum(fn ($p) => (float) $p->amount * (float) $p->invoice->exchange_rate), 2)),
        ];

        $costsPerCategory = $expenses->groupBy(fn ($e) => $e->category?->name ?? __('Zonder categorie'))
            ->map(fn ($g, $name) => ['name' => $name, 'amount' => $this->sumBase($g, 'amount_excl')])
            ->sortByDesc('amount')
            ->map(fn ($r) => [$r['name'], $this->money($r['amount']), $costs ? Money::number($r['amount'] / $costs * 100, 1).'%' : '-'])
            ->values()->all();

        $revenuePerClient = $invoices->groupBy(fn ($i) => $i->client?->name ?? '-')
            ->map(fn ($g, $name) => ['name' => $name, 'amount' => $this->sumBase($g, 'subtotal'), 'count' => $g->count()])
            ->sortByDesc('amount')->take(25)
            ->map(fn ($r) => [$r['name'], $r['count'], $this->money($r['amount'])])->values()->all();

        $months = collect();
        foreach ($invoices as $i) {
            $months[$i->issue_date->format('Y-m')] = ($months[$i->issue_date->format('Y-m')] ?? ['r' => 0, 'c' => 0]);
            $months[$i->issue_date->format('Y-m')] = ['r' => $months[$i->issue_date->format('Y-m')]['r'] + (float) $i->subtotal * (float) $i->exchange_rate, 'c' => $months[$i->issue_date->format('Y-m')]['c']];
        }
        foreach ($incomes as $i) {
            $k = $i->date->format('Y-m');
            $months[$k] = ['r' => ($months[$k]['r'] ?? 0) + (float) $i->amount_excl * (float) $i->exchange_rate, 'c' => $months[$k]['c'] ?? 0];
        }
        foreach ($expenses as $e) {
            $k = $e->date->format('Y-m');
            $months[$k] = ['r' => $months[$k]['r'] ?? 0, 'c' => ($months[$k]['c'] ?? 0) + (float) $e->amount_excl * (float) $e->exchange_rate];
        }
        $perMonth = $months->sortKeys()->map(fn ($v, $k) => [
            Carbon::createFromFormat('Y-m', $k)->translatedFormat('F Y'), $this->money(round($v['r'], 2)), $this->money(round($v['c'], 2)), $this->money(round($v['r'] - $v['c'], 2)),
        ])->values()->all();

        $openList = $open->sortBy('due_date')->take(30)->map(fn (Invoice $i) => [
            $i->number, $i->client?->name, $i->due_date?->format('d-m-Y'), Money::format($i->outstanding(), $i->currency), $i->isOverdue() ? __('Te laat') : __('Open'),
        ])->values()->all();

        return [
            'summary' => $summary,
            'tables' => [
                __('Kosten per categorie') => ['headers' => [__('Categorie'), __('Bedrag'), __('Aandeel')], 'rows' => $costsPerCategory],
                __('Omzet per klant') => ['headers' => [__('Klant'), __('Facturen'), __('Omzet')], 'rows' => $revenuePerClient],
                __('Per maand') => ['headers' => [__('Maand'), __('Opbrengsten'), __('Kosten'), __('Resultaat')], 'rows' => $perMonth],
                __('Openstaande facturen') => ['headers' => [__('Nummer'), __('Klant'), __('Vervaldatum'), __('Open'), __('Status')], 'rows' => $openList],
            ],
        ];
    }

    // ------------------------------------------------------------- exports

    /** @return array{0: array, 1: iterable} kolommen en regels */
    public function exportInvoices(): array
    {
        return [
            [__('Nummer'), __('Soort'), __('Datum'), __('Vervaldatum'), __('Klant'), __('Btw-nr. klant'), __('Valuta'), __('Subtotaal'), __('Btw'), __('Totaal'),
                __('Betaald'), __('Status'), __('Wisselkoers'), __('Subtotaal :base', ['base' => $this->base]), __('Btw :base', ['base' => $this->base])],
            $this->invoices()->with('client')->orderBy('issue_date')->orderBy('number')->lazy()->map(fn (Invoice $i) => [
                $i->number, $i->type->getLabel(), $i->issue_date->format('Y-m-d'), $i->due_date?->format('Y-m-d'), $i->client_snapshot['name'] ?? $i->client?->name,
                $i->client_snapshot['vat_number'] ?? null, $i->currency, (float) $i->subtotal, (float) $i->vat_total, (float) $i->total, (float) $i->paid_total,
                $i->status->getLabel(), (float) $i->exchange_rate, round((float) $i->subtotal * (float) $i->exchange_rate, 2), round((float) $i->vat_total * (float) $i->exchange_rate, 2),
            ]),
        ];
    }

    public function exportPayments(): array
    {
        return [
            [__('Datum'), __('Factuur'), __('Klant'), __('Valuta'), __('Bedrag'), __('Betaalwijze'), __('Kenmerk')],
            InvoicePayment::query()->with('invoice.client')->whereBetween('paid_at', [$this->from->toDateString(), $this->until->toDateString()])
                ->orderBy('paid_at')->lazy()->map(fn (InvoicePayment $p) => [
                    $p->paid_at->format('Y-m-d'), $p->invoice->number, $p->invoice->client?->name, $p->invoice->currency, (float) $p->amount, $p->method?->getLabel(), $p->reference,
                ]),
        ];
    }

    public function exportExpenses(): array
    {
        return [
            [__('Datum'), __('Leverancier'), __('Factuurnummer leverancier'), __('Omschrijving'), __('Categorie'), __('Valuta'), __('Excl. btw'), __('Btw'), __('Totaal'),
                __('Wisselkoers'), __('Excl. btw :base', ['base' => $this->base]), __('Betaald op')],
            $this->expenses()->with('category')->orderBy('date')->lazy()->map(fn (Expense $e) => [
                $e->date->format('Y-m-d'), $e->supplier, $e->invoice_number, $e->description, $e->category?->name, $e->currency, (float) $e->amount_excl,
                (float) $e->vat_amount, (float) $e->total, (float) $e->exchange_rate, $e->baseAmount(), $e->paid_at?->format('Y-m-d'),
            ]),
        ];
    }

    public function exportIncomes(): array
    {
        return [
            [__('Datum'), __('Omschrijving'), __('Categorie'), __('Klant'), __('Valuta'), __('Excl. btw'), __('Btw'), __('Totaal'), __('Wisselkoers'), __('Excl. btw :base', ['base' => $this->base])],
            $this->incomes()->with(['category', 'client'])->orderBy('date')->lazy()->map(fn (Income $i) => [
                $i->date->format('Y-m-d'), $i->description, $i->category?->name, $i->client?->name, $i->currency, (float) $i->amount_excl,
                (float) $i->vat_amount, (float) $i->total, (float) $i->exchange_rate, $i->baseAmount(),
            ]),
        ];
    }
}
