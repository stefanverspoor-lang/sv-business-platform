<?php

namespace Tests\Feature;

use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\CallAttempt;
use App\Models\CallOutcome;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Support\Billing\Billing;
use App\Support\Calling\CallRecorder;
use App\Support\Dashboard\CompanyMetrics;
use App\Support\Permissions\Roles;
use App\Support\Privacy\Anonymizer;
use App\Support\Privacy\Retention;
use App\Support\Reports\BusinessReport;
use App\Support\Reports\ReportExporter;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 6: dashboard, rapportagecentrum, beveiliging en AVG.
 */
class DashboardAndPrivacyTest extends TestCase
{
    protected User $admin;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->makeUser(superadmin: true);
        $this->actingAs($this->admin);
        $this->inCompany($this->sv);
        $this->client = Client::create(['name' => 'Opdrachtgever BV']);
    }

    protected function withMfa(User $user): User
    {
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $user;
    }

    protected function lead(array $attributes = []): Lead
    {
        $campaign = Campaign::firstOrCreate(['name' => 'Test'], ['client_id' => $this->client->id, 'max_attempts' => 3, 'retry_after_minutes' => 60]);

        return Lead::create($attributes + ['campaign_id' => $campaign->id, 'contact_name' => 'Jan Jansen', 'phone' => '0612345678',
            'email' => 'jan@example.com', 'notes' => 'Belt liever na vijven']);
    }

    protected function file(array $attributes = []): CollectionFile
    {
        return CollectionFile::create($attributes + ['client_id' => $this->client->id, 'file_number' => 'D-'.uniqid(),
            'debtor_name' => 'Piet Pietersen', 'phone' => '0687654321', 'email' => 'piet@example.com', 'address_line' => 'Dorpsstraat 1',
            'original_amount' => 250, 'outstanding_amount' => 250]);
    }

    protected function outcome(string $module, string $code): CallOutcome
    {
        return CallOutcome::where('module', $module)->where('code', $code)->firstOrFail();
    }

    // ------------------------------------------------------------ dashboard

    public function test_company_metrics_count_only_the_active_company(): void
    {
        $billing = app(Billing::class);
        $invoice = Invoice::create($billing->newInvoiceAttributes(['client_id' => $this->client->id]));
        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 2, 'unit_price' => 100]);
        $invoice->recalculateTotals();
        $billing->finalize($invoice->fresh());
        $lead = $this->lead();
        app(CallRecorder::class)->record($lead, $this->admin, $this->outcome('telemarketing', 'appointment'));

        $sv = (new CompanyMetrics($this->admin))->all();
        $this->assertSame(200.0, $sv['revenue_month']);
        $this->assertSame(1, $sv['open_invoices']['count']);
        $this->assertSame(1, $sv['active_clients']);
        $this->assertSame(1, $sv['tm_calls_month']);
        $this->assertSame(1, $sv['tm_conversions_month']);

        $yes = app(TenantManager::class)->runFor($this->yes, fn () => (new CompanyMetrics($this->admin))->all());
        $this->assertSame(0.0, $yes['revenue_month']);
        $this->assertSame(0, $yes['active_clients']);
        $this->assertSame(0, $yes['tm_calls_month']);
    }

    public function test_business_report_groups_per_client_service_and_employee(): void
    {
        $file = $this->file();
        app(CallRecorder::class)->record($file, $this->admin, $this->outcome('collection', 'no_answer'));
        app(CallRecorder::class)->record($this->lead(), $this->admin, $this->outcome('telemarketing', 'appointment'));

        $report = new BusinessReport(now()->startOfMonth(), now()->endOfDay());
        $clients = $report->clients();
        $this->assertSame('Opdrachtgever BV', $clients['tables'][0]['rows'][0][0]);
        $this->assertSame('2', $clients['summary'][__('Belpogingen')]);

        $services = collect($report->services()['tables'][0]['rows'])->keyBy(0);
        $this->assertSame('1', $services[__('Telemarketing')][6]);
        $this->assertSame('1', $services[__('Telefonische pre-incasso')][6]);

        $employees = $report->employees()['tables'][0]['rows'];
        $this->assertSame($this->admin->name, $employees[0][0]);

        $companies = BusinessReport::companies(['SV' => $report->companyTotals(), 'YES' => app(TenantManager::class)->runFor($this->yes,
            fn () => (new BusinessReport(now()->startOfMonth(), now()->endOfDay()))->companyTotals())]);
        $this->assertCount(3, $companies['tables'][0]['rows'], 'twee bedrijven plus totaalregel');
    }

    public function test_reports_export_to_excel_csv_and_pdf(): void
    {
        $report = (new BusinessReport(now()->startOfMonth(), now()->endOfDay()))->clients();
        foreach (['xlsx' => 'PK', 'csv' => "\xEF\xBB\xBF", 'pdf' => '%PDF'] as $format => $magic) {
            $path = Storage::disk('local')->path("test.$format");
            ReportExporter::write($format, $path, $report, 'Test', 'Periode');
            $this->assertStringStartsWith($magic, file_get_contents($path), $format);
        }
        $this->assertSame("'=HYPERLINK(1)", ReportExporter::safeCell('=HYPERLINK(1)'));
        $this->assertSame('-12,50', ReportExporter::safeCell('-12,50'));
    }

    // ------------------------------------------------------------ toegang

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/login');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_roles_only_reach_their_own_screens(): void
    {
        $worker = $this->withMfa($this->makeUser(companyRoles: ['sv' => Roles::DATA_ENTRY]));
        $this->actingAs($worker);
        foreach (['/sv/facturen', '/sv/financieel-overzicht', '/sv/rapportages', '/sv/totaaloverzicht', '/sv/dossiers'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/sv')->assertOk();

        $supervisor = $this->withMfa($this->makeUser(companyRoles: ['sv' => Roles::SUPERVISOR]));
        $this->actingAs($supervisor)->get('/sv/totaaloverzicht')->assertForbidden();

        $this->actingAs($this->withMfa($this->admin))->get('/sv/totaaloverzicht')->assertOk()->assertSee('YES Marketing Solutions');
    }

    public function test_records_of_the_other_company_cannot_be_opened(): void
    {
        $client = Client::create(['name' => 'Alleen SV']);
        $yesUser = $this->withMfa($this->makeUser(companyRoles: ['yes' => Roles::COMPANY_MANAGER]));

        $this->actingAs($yesUser)->get("/sv/klanten/{$client->id}")->assertNotFound();
        $this->actingAs($yesUser)->get("/yes/klanten/{$client->id}")->assertNotFound();
    }

    // ------------------------------------------------------------ AVG

    public function test_anonymizing_a_lead_wipes_personal_data_but_keeps_numbers(): void
    {
        $lead = $this->lead();
        app(CallRecorder::class)->record($lead, $this->admin, $this->outcome('telemarketing', 'no_answer'), 'Vrouw nam op, Jan belt terug');

        app(Anonymizer::class)->lead($lead->fresh());

        $lead = $lead->fresh();
        $this->assertNotNull($lead->anonymized_at);
        $this->assertSame(Anonymizer::LABEL, $lead->company_name);
        $this->assertNull($lead->contact_name);
        $this->assertNull($lead->phone);
        $this->assertNull($lead->phone_normalized);
        $this->assertNull($lead->email);
        $this->assertNull($lead->notes);
        $this->assertSame(LeadStatus::Closed, $lead->status, 'niet meer in de belwachtrij');
        $attempt = CallAttempt::where('callable_id', $lead->id)->first();
        $this->assertNull($attempt->notes);
        $this->assertNull($attempt->phone);
        $this->assertSame(1, $lead->attempts);
        $this->assertTrue(AuditLog::where('event', 'anonymized')->where('subject_id', $lead->id)->exists());
    }

    public function test_do_not_call_number_is_kept_after_anonymizing(): void
    {
        $lead = $this->lead(['status' => LeadStatus::DoNotCall]);
        app(Anonymizer::class)->lead($lead);

        $this->assertNotNull($lead->fresh()->phone_normalized);
        $this->assertNull($lead->fresh()->phone);
    }

    public function test_retention_anonymizes_only_old_finished_records(): void
    {
        $old = $this->file(['status' => CollectionStatus::Closed, 'closed_at' => now()->subMonths(30)]);
        $recent = $this->file(['status' => CollectionStatus::Closed, 'closed_at' => now()->subMonths(2)]);
        $open = $this->file(['status' => CollectionStatus::Callback]);
        CollectionFile::whereKey($open->id)->toBase()->update(['updated_at' => now()->subMonths(30)]);
        $oldLead = $this->lead(['status' => LeadStatus::NotInterested]);
        Lead::whereKey($oldLead->id)->toBase()->update(['updated_at' => now()->subMonths(13)]);
        $openLead = $this->lead(['status' => LeadStatus::Callback]);
        Lead::whereKey($openLead->id)->toBase()->update(['updated_at' => now()->subMonths(13)]);

        Storage::disk('local')->put('companies/sv/reports/2025/01/oud.xlsx', 'x');
        touch(Storage::disk('local')->path('companies/sv/reports/2025/01/oud.xlsx'), now()->subDays(40)->getTimestamp());
        Storage::disk('local')->put('companies/sv/reports/2026/11/nieuw.xlsx', 'x');

        $dry = app(Retention::class)->dryRun()->runForCompany($this->sv);
        $this->assertSame(1, $dry['collection_files']);
        $this->assertNull($old->fresh()->anonymized_at, 'proefdraai wijzigt niets');

        $counts = app(Retention::class)->runForCompany($this->sv);
        $this->assertSame(['leads' => 1, 'collection_files' => 1, 'import_files' => 0, 'report_files' => 1], $counts);
        $this->assertSame(Anonymizer::LABEL, $old->fresh()->debtor_name);
        $this->assertSame('Piet Pietersen', $recent->fresh()->debtor_name);
        $this->assertSame('Piet Pietersen', $open->fresh()->debtor_name);
        $this->assertNotNull($oldLead->fresh()->anonymized_at);
        $this->assertNull($openLead->fresh()->anonymized_at);
        Storage::disk('local')->assertMissing('companies/sv/reports/2025/01/oud.xlsx');
        Storage::disk('local')->assertExists('companies/sv/reports/2026/11/nieuw.xlsx');
    }

    public function test_old_audit_log_lines_are_purged(): void
    {
        AuditLog::create(['event' => 'login', 'created_at' => now()->subMonths(30)]);
        AuditLog::create(['event' => 'login', 'created_at' => now()]);

        $this->assertSame(1, app(Retention::class)->purgeAuditLog());
        $this->assertSame(1, AuditLog::where('event', 'login')->count());
    }
}
