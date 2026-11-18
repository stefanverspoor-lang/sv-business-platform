<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\VatRate;
use App\Support\Billing\Billing;
use App\Support\Billing\PdfRenderer;
use App\Support\Mail\MessageDeleter;
use App\Support\Mail\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/** Handtekening, spam, e-mail verwijderen, bankrekeningen, factuur zonder btw en resetten. */
class MailAndInvoiceOptionsTest extends TestCase
{
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs($this->makeUser(['name' => 'Stefan Verspoor'], superadmin: true));
        $this->inCompany($this->sv);
        $this->client = Client::create(['name' => 'Klant BV']);
    }

    protected function mailbox(array $attributes = []): Mailbox
    {
        return Mailbox::create($attributes + ['name' => 'Info', 'email' => 'info@svbusinesssupport.com', 'username' => 'x', 'password' => 'x']);
    }

    protected function invoice(?int $vatRateId, array $attributes = []): Invoice
    {
        $billing = app(Billing::class);
        $invoice = Invoice::create($billing->newInvoiceAttributes($attributes + ['client_id' => $this->client->id]));
        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 500, 'vat_rate_id' => $vatRateId]);
        $invoice->recalculateTotals();

        return $invoice->fresh();
    }

    protected function pdfHtml(Invoice $invoice): string
    {
        $data = (new ReflectionMethod(PdfRenderer::class, 'data'))->invoke(app(PdfRenderer::class), $invoice, $this->sv, 'nl');

        return view('pdf.billing-document', $data)->render();
    }

    public function test_signature_fills_in_the_sender_and_is_cleaned(): void
    {
        $mailbox = $this->mailbox(['signature' => '<p>Met vriendelijke groet,<br><strong>{naam}</strong><br>{email}</p><script>alert(1)</script>']);

        $signature = Signature::for($mailbox);
        $this->assertStringContainsString('<strong>Stefan Verspoor</strong>', $signature->html);
        $this->assertStringContainsString('info@svbusinesssupport.com', $signature->html);
        $this->assertStringNotContainsString('script', $signature->html);
        $this->assertSame("Met vriendelijke groet,\nStefan Verspoor\ninfo@svbusinesssupport.com", $signature->text);

        $this->assertNull(Signature::for($this->mailbox(['email' => 'leeg@svbusinesssupport.com', 'signature' => '<p></p>'])));
    }

    public function test_spam_does_not_count_as_unread_mail(): void
    {
        $mailbox = $this->mailbox();
        $base = ['mailbox_id' => $mailbox->id, 'folder' => 'INBOX', 'direction' => 'in', 'thread_key' => 'x', 'is_read' => false];
        EmailMessage::create($base + ['uid' => 1, 'subject' => 'Echt']);
        EmailMessage::create($base + ['uid' => 2, 'subject' => 'Spam', 'folder' => 'INBOX.Junk', 'is_spam' => true]);

        $this->assertSame(1, $mailbox->unreadCount());
        $this->assertSame(1, (new \App\Support\Dashboard\CompanyMetrics(auth()->user()))->all()['emails_unread']);
    }

    public function test_deleting_mail_removes_it_and_its_own_attachments_only(): void
    {
        $mailbox = $this->mailbox();
        $email = EmailMessage::create(['mailbox_id' => $mailbox->id, 'folder' => 'INBOX', 'direction' => 'in', 'thread_key' => 'x', 'subject' => 'Weg']);
        Storage::disk('local')->put('companies/sv/mail/2026/11/bijlage.pdf', 'x');
        Storage::disk('local')->put('companies/sv/invoices/2026/SV-2026-0001.pdf', 'x');
        $email->attachments()->create(['filename' => 'bijlage.pdf', 'path' => 'companies/sv/mail/2026/11/bijlage.pdf', 'size' => 1]);
        $email->attachments()->create(['filename' => 'factuur.pdf', 'path' => 'companies/sv/invoices/2026/SV-2026-0001.pdf', 'size' => 1]);

        $result = app(MessageDeleter::class)->delete([$email], fromServer: false);

        $this->assertSame(['deleted' => 1, 'server_failed' => 0], $result);
        $this->assertSame(0, EmailMessage::count());
        Storage::disk('local')->assertMissing('companies/sv/mail/2026/11/bijlage.pdf');
        Storage::disk('local')->assertExists('companies/sv/invoices/2026/SV-2026-0001.pdf');
    }

    public function test_bank_account_follows_currency_and_is_frozen_on_the_invoice(): void
    {
        $eur = BankAccount::create(['name' => 'ING EUR', 'iban' => 'NL91INGB0001234567', 'bic' => 'INGBNL2A', 'account_holder' => 'SV Business Support', 'is_default' => true]);
        $usd = BankAccount::create(['name' => 'Wise USD', 'iban' => '8310123456', 'bic' => 'CMFGUS33', 'currency' => 'USD', 'extra' => 'Routing number 026073150']);

        $this->assertSame($usd->id, BankAccount::forCurrency('USD')->id);
        $this->assertSame($eur->id, BankAccount::forCurrency('IDR')->id, 'geen IDR-rekening: standaard');

        $invoice = $this->invoice(VatRate::defaultId(), ['currency' => 'USD', 'exchange_rate' => 0.9]);
        $this->assertSame($usd->id, $invoice->bank_account_id);

        $final = app(Billing::class)->finalize($invoice);
        $this->assertSame('8310123456', $final->company_snapshot['iban']);
        $this->assertSame('Routing number 026073150', $final->company_snapshot['bank_extra']);

        $usd->update(['iban' => 'GEWIJZIGD']);
        $this->assertSame('8310123456', $final->fresh()->company_snapshot['iban'], 'definitieve factuur verandert niet mee');
        $this->assertStringContainsString('Routing number 026073150', $this->pdfHtml($final->fresh()));
    }

    public function test_invoice_without_vat_shows_only_the_net_amount(): void
    {
        $none = VatRate::where('kind', 'none')->value('id');
        $html = $this->pdfHtml($this->invoice($none));
        $this->assertStringNotContainsString(__('Subtotaal'), $html);
        $this->assertStringNotContainsString('<td class="num">0%</td>', $html);
        $this->assertStringNotContainsString('>'.__('Btw').'<', $html);
        $this->assertStringContainsString(__('Totaal'), $html);

        $html = $this->pdfHtml($this->invoice(VatRate::where('kind', 'standard')->value('id')));
        $this->assertStringContainsString(__('Subtotaal'), $html);
        $this->assertStringContainsString('21%', $html);

        $reverse = VatRate::where('kind', 'reverse_charge')->value('id');
        $this->assertStringContainsString(__('Subtotaal'), $this->pdfHtml($this->invoice($reverse)), 'btw verlegd moet wel vermeld blijven');
    }

    public function test_reset_clears_business_data_but_keeps_settings_and_mailboxes(): void
    {
        $mailbox = $this->mailbox();
        $email = EmailMessage::create(['mailbox_id' => $mailbox->id, 'folder' => 'INBOX', 'direction' => 'in', 'thread_key' => 'x', 'subject' => 'Blijft']);
        app(\App\Support\Mail\MessageStore::class)->link($email, $this->client);
        BankAccount::create(['name' => 'ING', 'iban' => 'NL91INGB0001234567']);
        $invoice = app(Billing::class)->finalize($this->invoice(VatRate::defaultId()));
        $this->assertStringEndsWith('0001', $invoice->number);
        app(Billing::class)->finalize($this->invoice(VatRate::defaultId()));

        $this->artisan('crm:reset-data', ['--force' => true, '--zonder-backup' => true])->assertSuccessful();

        $this->inCompany($this->sv);
        $db = DB::connection('company_sv');
        foreach (['clients', 'invoices', 'billing_lines', 'number_sequences', 'email_links'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), $table);
        }
        $this->assertSame(1, $db->table('mailboxes')->count());
        $this->assertSame(1, $db->table('email_messages')->count());
        $this->assertSame(1, $db->table('bank_accounts')->count());
        $this->assertGreaterThan(0, $db->table('vat_rates')->count());
        $this->assertGreaterThan(0, $db->table('call_outcomes')->count());

        $client = Client::create(['name' => 'Echte klant']);
        $this->client = $client;
        $this->assertStringEndsWith('0001', app(Billing::class)->finalize($this->invoice(VatRate::defaultId()))->number);
    }
}
