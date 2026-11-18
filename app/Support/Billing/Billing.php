<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\QuoteStatus;
use App\Models\BillingLine;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\RecurringInvoice;
use App\Support\Audit\Audit;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Alle handelingen op offertes en facturen. */
class Billing
{
    public function __construct(protected NumberGenerator $numbers, protected PdfRenderer $pdf) {}

    /** Nieuw concept met de standaardinstellingen. */
    public function newInvoiceAttributes(array $attributes = []): array
    {
        $attributes = array_filter($attributes, fn ($v) => $v !== null);
        $terms = (int) BillingSettings::get('payment_terms_days');
        $issue = Carbon::parse($attributes['issue_date'] ?? now());
        $currency = $attributes['currency'] ?? BillingSettings::baseCurrency();

        return $attributes + [
            'type' => InvoiceType::Invoice,
            'status' => InvoiceStatus::Draft,
            'issue_date' => $issue->toDateString(),
            'payment_terms_days' => $terms,
            'due_date' => $issue->copy()->addDays($terms)->toDateString(),
            'locale' => app(TenantManager::class)->current()?->default_locale ?: 'nl',
            'currency' => $currency,
            'base_currency' => BillingSettings::baseCurrency(),
            'exchange_rate' => \App\Models\ExchangeRate::lookup($currency, BillingSettings::baseCurrency(), $issue) ?? 1,
            'created_by' => auth()->id(),
        ];
    }

    /** Concept definitief maken: nummer, vastgelegde gegevens en PDF. */
    public function finalize(Invoice $invoice): Invoice
    {
        if (! $invoice->isDraft()) {
            return $invoice;
        }
        if (! $invoice->lines()->exists()) {
            throw new RuntimeException(__('Voeg eerst minstens één regel toe.'));
        }
        if ($invoice->currency !== $invoice->base_currency && (float) $invoice->exchange_rate <= 0) {
            throw new RuntimeException(__('Vul de wisselkoers in.'));
        }

        DB::connection('tenant')->transaction(function () use ($invoice) {
            $invoice->recalculateTotals();
            $type = $invoice->isCreditNote() ? 'credit_note' : 'invoice';
            $issue = $invoice->issue_date ?? now();

            $invoice->forceFill([
                'number' => $this->numbers->next($type, (int) $issue->format('Y')),
                'status' => InvoiceStatus::Open,
                'due_date' => $invoice->isCreditNote() ? null : ($invoice->due_date ?? $issue->copy()->addDays($invoice->payment_terms_days)),
                'finalized_at' => now(),
                'client_snapshot' => ClientSnapshot::make($invoice->client, $invoice->contact),
                'company_snapshot' => CompanySnapshot::make(app(TenantManager::class)->current()),
            ])->save();
        });

        $invoice->refreshPaymentStatus(); // totaal 0 → meteen "betaald"
        if ($invoice->isCreditNote()) {
            $this->settleCreditNote($invoice->fresh());
        }
        $this->pdf->store($invoice->fresh());
        Audit::log('invoice_finalized', $invoice, ['number' => $invoice->number, 'total' => (string) $invoice->total]);

        return $invoice->fresh();
    }

    /**
     * Creditnota verrekenen met de oorspronkelijke factuur: wat daar nog open
     * stond, wordt weggestreept. Was de factuur al betaald, dan blijft het
     * restant op de creditnota open als terug te betalen bedrag.
     */
    protected function settleCreditNote(Invoice $credit): void
    {
        $original = $credit->creditedInvoice;
        if (! $original || $original->currency !== $credit->currency) {
            return;
        }
        $settle = round(min(max($original->outstanding(), 0), abs((float) $credit->total)), 2);
        if ($settle <= 0) {
            return;
        }
        $en = $credit->locale === 'en';
        $original->payments()->create(['amount' => $settle, 'paid_at' => now()->toDateString(), 'method' => 'other',
            'reference' => ($en ? 'Settled with credit note ' : 'Verrekend met creditnota ').$credit->number, 'user_id' => auth()->id()]);
        $credit->payments()->create(['amount' => -$settle, 'paid_at' => now()->toDateString(), 'method' => 'other',
            'reference' => ($en ? 'Settled with invoice ' : 'Verrekend met factuur ').$original->number, 'user_id' => auth()->id()]);
    }

    public function finalizeQuote(Quote $quote): Quote
    {
        if (! $quote->lines()->exists()) {
            throw new RuntimeException(__('Voeg eerst minstens één regel toe.'));
        }
        $quote->recalculateTotals();
        if (! $quote->number) {
            $quote->forceFill([
                'number' => $this->numbers->next('quote', (int) ($quote->issue_date ?? now())->format('Y')),
                'client_snapshot' => ClientSnapshot::make($quote->client, $quote->contact),
            ])->save();
        }
        $this->pdf->store($quote->fresh());

        return $quote->fresh();
    }

    /** PDF opnieuw maken (alleen voor concepten en offertes; definitieve facturen blijven zoals ze zijn). */
    public function pdfPath(Invoice|Quote $document): string
    {
        if ($document instanceof Invoice && ! $document->isDraft() && $document->pdf_path && \Storage::disk('local')->exists($document->pdf_path)) {
            return $document->pdf_path;
        }
        $document->recalculateTotals();

        return $this->pdf->store($document->fresh());
    }

    public function convertQuote(Quote $quote): Invoice
    {
        if ($quote->invoice_id) {
            return $quote->invoice;
        }

        return DB::connection('tenant')->transaction(function () use ($quote) {
            $invoice = Invoice::create($this->newInvoiceAttributes([
                'client_id' => $quote->client_id,
                'contact_id' => $quote->contact_id,
                'locale' => $quote->locale,
                'currency' => $quote->currency,
                'subject' => $quote->subject,
                'quote_id' => $quote->id,
                'reference' => $quote->number,
            ]));
            $this->copyLines($quote, $invoice);
            $invoice->recalculateTotals();
            $quote->forceFill(['status' => QuoteStatus::Invoiced, 'invoice_id' => $invoice->id, 'decided_at' => $quote->decided_at ?? now()])->save();

            return $invoice;
        });
    }

    /** Creditnota: alle regels negatief, verwijst naar de oorspronkelijke factuur. */
    public function creditNote(Invoice $invoice): Invoice
    {
        if ($invoice->isDraft() || $invoice->isCreditNote()) {
            throw new RuntimeException(__('Alleen een definitieve factuur kan worden gecrediteerd.'));
        }

        return DB::connection('tenant')->transaction(function () use ($invoice) {
            $credit = Invoice::create($this->newInvoiceAttributes([
                'type' => InvoiceType::CreditNote,
                'client_id' => $invoice->client_id,
                'contact_id' => $invoice->contact_id,
                'locale' => $invoice->locale,
                'currency' => $invoice->currency,
                'exchange_rate' => $invoice->exchange_rate,
                'credited_invoice_id' => $invoice->id,
                'subject' => ($invoice->locale === 'en' ? 'Credit note for invoice ' : 'Creditering van factuur ').$invoice->number,
                'issue_date' => now()->toDateString(),
                'due_date' => null,
            ]));
            $this->copyLines($invoice, $credit, negate: true);
            $credit->recalculateTotals();

            return $credit;
        });
    }

    public function duplicate(Invoice $invoice): Invoice
    {
        $copy = Invoice::create($this->newInvoiceAttributes([
            'client_id' => $invoice->client_id,
            'contact_id' => $invoice->contact_id,
            'locale' => $invoice->locale,
            'currency' => $invoice->currency,
            'subject' => $invoice->subject,
            'intro' => $invoice->intro,
            'notes' => $invoice->notes,
        ]));
        $this->copyLines($invoice, $copy, keepSource: false);
        $copy->recalculateTotals();

        return $copy;
    }

    /** Concept verwijderen; gekoppelde uren en commissie komen weer vrij. */
    public function deleteDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException(__('Een definitieve factuur kan niet worden verwijderd. Maak een creditnota.'));
        }
        DB::connection('tenant')->transaction(function () use ($invoice) {
            $invoice->lines->each->delete();
            \App\Models\Quote::where('invoice_id', $invoice->id)->update(['invoice_id' => null, 'status' => QuoteStatus::Accepted]);
            $invoice->delete();
        });
    }

    /** Factuur maken uit een terugkerende factuur en de volgende datum zetten. */
    public function generateFromRecurring(RecurringInvoice $recurring): Invoice
    {
        $recurring->refresh();

        return DB::connection('tenant')->transaction(function () use ($recurring) {
            $issue = $recurring->next_date->copy();
            $period = $this->periodLabel($issue, $recurring->interval->months(), $recurring->locale);

            $invoice = Invoice::create($this->newInvoiceAttributes([
                'client_id' => $recurring->client_id,
                'contact_id' => $recurring->contact_id,
                'locale' => $recurring->locale,
                'currency' => $recurring->currency,
                'payment_terms_days' => $recurring->payment_terms_days,
                'issue_date' => $issue->toDateString(),
                'due_date' => $issue->copy()->addDays($recurring->payment_terms_days)->toDateString(),
                'subject' => $recurring->subject ? $this->placeholders($recurring->subject, $issue, $period) : null,
                'notes' => $recurring->notes,
                'recurring_invoice_id' => $recurring->id,
            ]));

            foreach ($recurring->lines as $line) {
                $invoice->lines()->create([
                    'sort' => $line->sort,
                    'description' => $this->placeholders($line->description, $issue, $period),
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'vat_rate_id' => $line->vat_rate_id,
                ]);
            }
            $invoice->recalculateTotals();

            $next = $issue->copy()->addMonthsNoOverflow($recurring->interval->months());
            $recurring->forceFill([
                'next_date' => $next,
                'last_generated_at' => now(),
                'is_active' => ! $recurring->end_date || $next->lte($recurring->end_date),
            ])->save();

            return $invoice;
        });
    }

    protected function periodLabel(Carbon $start, int $months, string $locale): string
    {
        $start = $start->copy()->locale($locale);
        if ($months === 1) {
            return $start->translatedFormat('F Y');
        }
        $end = $start->copy()->addMonthsNoOverflow($months - 1);

        return $start->translatedFormat('F Y').' – '.$end->translatedFormat('F Y');
    }

    protected function placeholders(string $text, Carbon $date, string $period): string
    {
        return strtr($text, ['{periode}' => $period, '{period}' => $period, '{maand}' => $period, '{month}' => $period,
            '{jaar}' => $date->format('Y'), '{year}' => $date->format('Y')]);
    }

    protected function copyLines(Model $from, Model $to, bool $negate = false, bool $keepSource = false): void
    {
        foreach ($from->lines as $line) {
            $to->lines()->create([
                'sort' => $line->sort,
                'description' => $line->description,
                'quantity' => $negate ? -1 * (float) $line->quantity : $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'discount_percent' => $line->discount_percent,
                'vat_rate_id' => $line->vat_rate_id,
                'vat_rate' => $line->vat_rate,
                'vat_label' => $line->vat_label,
                'source' => $keepSource ? $line->source : null,
            ]);
        }
    }
}
