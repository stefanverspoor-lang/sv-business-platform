<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\ClientService;
use App\Models\DataJob;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Quote;
use App\Models\RecurringInvoice;
use App\Models\TimeEntry;
use App\Models\VatRate;
use App\Support\Billing\Billing;
use App\Support\Billing\BillableWork;
use App\Support\Billing\BillingSettings;
use App\Support\Billing\Money;
use App\Support\Permissions\Roles;
use App\Support\Reports\FinanceReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 5: facturatie en financiën.
 */
class BillingTest extends TestCase
{
    protected Client $client;

    protected Billing $billing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs($this->makeUser(superadmin: true));
        $this->inCompany($this->sv);
        $this->client = Client::create(['name' => 'Klant BV', 'vat_number' => 'NL001']);
        $this->billing = app(Billing::class);
    }

    protected function rate(float $rate): int
    {
        return VatRate::where('rate', $rate)->where('kind', $rate > 0 ? ($rate == 21.0 ? 'standard' : 'reduced') : 'none')->value('id');
    }

    protected function invoice(array $lines, array $attributes = []): Invoice
    {
        $invoice = Invoice::create($this->billing->newInvoiceAttributes($attributes + ['client_id' => $this->client->id]));
        foreach ($lines as $line) {
            $invoice->lines()->create($line);
        }
        $invoice->recalculateTotals();

        return $invoice->fresh();
    }

    public function test_billing_tables_live_only_in_the_company_database(): void
    {
        $this->invoice([['description' => 'x', 'quantity' => 1, 'unit_price' => 10]]);
        $this->assertSame(1, DB::connection('company_sv')->table('invoices')->count());
        $this->assertSame(0, DB::connection('company_yes')->table('invoices')->count());
        $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('invoices'));
    }

    public function test_totals_with_mixed_vat_rates_and_discount(): void
    {
        $invoice = $this->invoice([
            ['description' => 'Website', 'quantity' => 1, 'unit_price' => 950, 'vat_rate_id' => $this->rate(21)],
            ['description' => 'Onderhoud', 'quantity' => 1, 'unit_price' => 49, 'vat_rate_id' => $this->rate(21)],
            ['description' => 'Support', 'quantity' => 2.5, 'unit_price' => 65, 'discount_percent' => 10, 'vat_rate_id' => $this->rate(9)],
        ]);

        $this->assertSame('1145.25', $invoice->subtotal);          // 950 + 49 + 146,25
        $this->assertSame('222.95', $invoice->vat_total);           // 209,79 + 13,16
        $this->assertSame('1368.20', $invoice->total);
    }

    public function test_numbers_are_sequential_per_type_and_year_and_only_on_finalize(): void
    {
        $a = $this->invoice([['description' => 'a', 'quantity' => 1, 'unit_price' => 10]]);
        $b = $this->invoice([['description' => 'b', 'quantity' => 1, 'unit_price' => 10]]);
        $this->assertNull($a->number);

        $b = $this->billing->finalize($b);
        $a = $this->billing->finalize($a);
        $year = now()->year;
        $this->assertSame("SV-{$year}-0001", $b->number);
        $this->assertSame("SV-{$year}-0002", $a->number);

        $old = $this->billing->finalize($this->invoice([['description' => 'c', 'quantity' => 1, 'unit_price' => 10]], ['issue_date' => '2025-12-31']));
        $this->assertSame('SV-2025-0001', $old->number);

        BillingSettings::save(['invoice_prefix' => 'F', 'number_digits' => 3]);
        $this->assertSame("F{$year}-003", $this->billing->finalize($this->invoice([['description' => 'd', 'quantity' => 1, 'unit_price' => 10]]))->number);
    }

    public function test_finalized_invoice_is_locked_and_keeps_its_snapshot(): void
    {
        $invoice = $this->billing->finalize($this->invoice([['description' => 'a', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => $this->rate(21)]]));
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame('Klant BV', $invoice->client_snapshot['name']);
        $this->assertTrue(Storage::disk('local')->exists($invoice->pdf_path));

        $this->client->update(['name' => 'Nieuwe naam BV']);
        $this->assertSame('Klant BV', $invoice->fresh()->client_snapshot['name']);

        $this->expectException(RuntimeException::class);
        $invoice->lines()->first()->update(['unit_price' => 1]);
    }

    public function test_finalized_invoice_cannot_be_deleted_or_edited_by_policy(): void
    {
        $invoice = $this->billing->finalize($this->invoice([['description' => 'a', 'quantity' => 1, 'unit_price' => 100]]));
        $admin = $this->makeUser(companyRoles: ['sv' => Roles::ADMINISTRATION]);
        $this->actingAs($admin);
        $this->inCompany($this->sv);

        $this->assertTrue($admin->can('view', $invoice));
        $this->assertFalse($admin->can('update', $invoice));
        $this->assertFalse($admin->can('delete', $invoice));
        $this->expectException(RuntimeException::class);
        $this->billing->deleteDraft($invoice);
    }

    public function test_payments_update_status(): void
    {
        $invoice = $this->billing->finalize($this->invoice([['description' => 'a', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => $this->rate(21)]]));
        $invoice->payments()->create(['amount' => 50, 'paid_at' => now()]);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame(71.0, $invoice->fresh()->outstanding());

        $payment = $invoice->payments()->create(['amount' => 71, 'paid_at' => now()]);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);

        $payment->delete();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
    }

    public function test_credit_note_negates_lines_and_settles_the_open_amount(): void
    {
        $invoice = $this->billing->finalize($this->invoice([['description' => 'a', 'quantity' => 2, 'unit_price' => 100, 'vat_rate_id' => $this->rate(21)]]));
        $invoice->payments()->create(['amount' => 42, 'paid_at' => now()]);

        $credit = $this->billing->finalize($this->billing->creditNote($invoice->fresh()));
        $year = now()->year;
        $this->assertSame("SV-CN-{$year}-0001", $credit->number);
        $this->assertSame('-242.00', $credit->total);

        // 200 van de factuur stond nog open: weggestreept; 42 was al betaald en moet terug
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $credit->fresh()->status);
        $this->assertSame(-42.0, $credit->fresh()->outstanding());
    }

    public function test_quote_is_converted_to_a_draft_invoice(): void
    {
        $quote = Quote::create(['client_id' => $this->client->id, 'issue_date' => now(), 'status' => QuoteStatus::Draft, 'currency' => 'EUR', 'locale' => 'en']);
        $quote->lines()->create(['description' => 'Website', 'quantity' => 1, 'unit_price' => 1200, 'vat_rate_id' => $this->rate(21)]);
        $quote = $this->billing->finalizeQuote($quote);
        $this->assertSame('SV-OF-'.now()->year.'-0001', $quote->number);

        $invoice = $this->billing->convertQuote($quote);
        $this->assertTrue($invoice->isDraft());
        $this->assertSame('en', $invoice->locale);
        $this->assertSame('1452.00', $invoice->total);
        $this->assertSame(QuoteStatus::Invoiced, $quote->fresh()->status);
        $this->assertTrue($this->billing->convertQuote($quote->fresh())->is($invoice), 'niet twee keer omzetten');
    }

    public function test_foreign_currency_uses_the_exchange_rate_for_reports(): void
    {
        $invoice = $this->billing->finalize($this->invoice(
            [['description' => 'Bali', 'quantity' => 1, 'unit_price' => 10000000]],
            ['currency' => 'IDR', 'exchange_rate' => 0.00006],
        ));
        $this->assertSame(600.0, $invoice->baseSubtotal());
        $this->assertSame('IDR 10.000.000', Money::format($invoice->total, 'IDR'));
        $this->assertSame('USD 1,234.50', Money::format(1234.5, 'USD', 'en'));
    }

    public function test_recurring_invoice_creates_the_next_invoice_and_moves_the_date(): void
    {
        $recurring = RecurringInvoice::create(['client_id' => $this->client->id, 'name' => 'Onderhoud', 'interval' => 'quarterly',
            'next_date' => '2026-01-31', 'end_date' => '2026-06-30', 'subject' => 'Onderhoud {periode}', 'locale' => 'nl', 'currency' => 'EUR']);
        $recurring->lines()->create(['description' => 'Onderhoud {periode}', 'quantity' => 1, 'unit_price' => 49, 'vat_rate_id' => $this->rate(21)]);

        $invoice = $this->billing->generateFromRecurring($recurring);
        $this->assertSame('2026-01-31', $invoice->issue_date->toDateString());
        $this->assertStringContainsString('januari 2026', $invoice->lines->first()->description);
        $this->assertSame('59.29', $invoice->total);
        $this->assertSame('2026-04-30', $recurring->fresh()->next_date->toDateString());
        $this->assertTrue($recurring->fresh()->is_active);

        $this->billing->generateFromRecurring($recurring->fresh());
        $this->assertFalse($recurring->fresh()->is_active, 'na de einddatum gestopt');
    }

    public function test_hours_records_and_commission_are_invoiced_once(): void
    {
        $user = $this->makeUser(companyRoles: ['sv' => Roles::PROJECT]);
        $project = Project::create(['client_id' => $this->client->id, 'name' => 'Site']);
        $project->timeEntries()->create(['user_id' => $user->id, 'date' => '2026-10-05', 'minutes' => 90]);
        $project->timeEntries()->create(['user_id' => $user->id, 'date' => '2026-10-06', 'minutes' => 30, 'billable' => false]);
        $service = ClientService::create(['client_id' => $this->client->id, 'service' => 'data_entry', 'pricing_model' => 'per_record', 'price' => 0.1, 'currency' => 'EUR']);
        $job = DataJob::create(['client_id' => $this->client->id, 'client_service_id' => $service->id, 'name' => 'Adressen']);
        $job->timeEntries()->create(['user_id' => $user->id, 'date' => '2026-10-07', 'minutes' => 120, 'records_done' => 300]);
        $file = CollectionFile::create(['client_id' => $this->client->id, 'file_number' => 'D1', 'debtor_name' => 'X', 'original_amount' => 100, 'outstanding_amount' => 100]);
        $file->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_amount' => 100, 'paid_at' => '2026-10-10', 'commission_amount' => 15])->save();

        $invoice = $this->invoice([]);
        $work = app(BillableWork::class);
        $from = Carbon::parse('2026-10-01');
        $until = Carbon::parse('2026-10-31');
        $this->assertSame(2, $work->addHours($invoice, $from, $until, 60, $this->rate(21)));
        $this->assertSame(1, $work->addCommission($invoice, $from, $until, $this->rate(21)));

        $lines = $invoice->fresh()->lines;
        $this->assertSame(['1.500', '300.000', '1.000'], $lines->pluck('quantity')->all());
        $this->assertSame('135.00', $invoice->fresh()->subtotal); // 1,5 u × 60 + 300 × 0,10 + 15
        $this->assertSame(0, $work->addHours($this->invoice([]), $from, $until, 60, null), 'niet nog een keer');
        $this->assertSame(1, TimeEntry::whereNull('invoice_id')->count(), 'niet-factureerbaar blijft over');

        // Concept verwijderen: alles weer vrij
        $this->billing->deleteDraft($invoice->fresh());
        $this->assertSame(0, TimeEntry::whereNotNull('invoice_id')->count());
        $this->assertNull($file->fresh()->commission_invoice_id);
    }

    public function test_finance_report_profit_and_vat(): void
    {
        $this->billing->finalize($this->invoice([['description' => 'a', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate_id' => $this->rate(21)]], ['issue_date' => '2026-03-01']));
        $this->invoice([['description' => 'concept telt niet', 'quantity' => 1, 'unit_price' => 5000]], ['issue_date' => '2026-03-02']);
        Expense::create(['date' => '2026-03-05', 'supplier' => 'Hostinger', 'finance_category_id' => FinanceCategory::where('type', 'expense')->value('id'),
            'amount_excl' => 100, 'vat_amount' => 21, 'currency' => 'EUR']);

        $report = (new FinanceReport(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')))->build();
        $s = $report['summary'];
        $this->assertSame('EUR 1.000,00', $s[__('Omzet (facturen, excl. btw)')]);
        $this->assertSame('EUR 100,00', $s[__('Kosten (excl. btw)')]);
        $this->assertSame('EUR 900,00', $s[__('Resultaat')]);
        $this->assertSame('EUR 189,00', $s[__('Btw-saldo')]);
    }

    public function test_finance_is_not_visible_for_call_center_agents(): void
    {
        $agent = $this->makeUser(companyRoles: ['sv' => Roles::CALLCENTER]);
        $this->actingAs($agent);
        $this->inCompany($this->sv);
        $this->assertFalse($agent->can('viewAny', Invoice::class));
        $this->assertFalse($agent->can('viewAny', Expense::class));
    }
}
