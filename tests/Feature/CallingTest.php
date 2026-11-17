<?php

namespace Tests\Feature;

use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Models\CallOutcome;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\CommissionModel;
use App\Models\Lead;
use App\Models\User;
use App\Support\Calling\CallQueue;
use App\Support\Calling\CallRecorder;
use App\Support\Calling\PhoneNumber;
use App\Support\Imports\CollectionFileImporter;
use App\Support\Imports\LeadImporter;
use App\Support\Imports\PaymentImporter;
use App\Support\Imports\Spreadsheet;
use App\Support\Permissions\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fase 3: telemarketing en telefonische pre-incasso.
 */
class CallingTest extends TestCase
{
    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->agent = $this->makeUser(companyRoles: ['sv' => Roles::CALLCENTER]);
        $this->actingAs($this->agent);
        $this->inCompany($this->sv);
    }

    protected function outcome(string $module, string $code): CallOutcome
    {
        return CallOutcome::where('module', $module)->where('code', $code)->firstOrFail();
    }

    protected function file(array $attributes = []): CollectionFile
    {
        $client = Client::firstOrCreate(['name' => 'Opdrachtgever BV']);

        return CollectionFile::create($attributes + [
            'client_id' => $client->id, 'file_number' => 'D-'.uniqid(), 'debtor_name' => 'Debiteur',
            'phone' => '0612345678', 'original_amount' => 300, 'outstanding_amount' => 300,
            'assigned_to' => $this->agent->id, 'max_attempts' => 3,
        ]);
    }

    protected function lead(array $attributes = []): Lead
    {
        $client = Client::firstOrCreate(['name' => 'Opdrachtgever BV']);
        $campaign = Campaign::firstOrCreate(['name' => 'Test'], ['client_id' => $client->id, 'max_attempts' => 2, 'retry_after_minutes' => 60]);

        return Lead::create($attributes + ['campaign_id' => $campaign->id, 'phone' => '0201234567', 'assigned_to' => $this->agent->id]);
    }

    public function test_calling_tables_live_only_in_the_company_database(): void
    {
        $this->file();
        $this->assertSame(1, DB::connection('company_sv')->table('collection_files')->count());
        $this->assertSame(0, DB::connection('company_yes')->table('collection_files')->count());
        $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('collection_files'));
        // standaard gespreksresultaten in beide bedrijven
        $this->assertSame(9, DB::connection('company_yes')->table('call_outcomes')->where('module', 'collection')->count());
    }

    public function test_phone_numbers_are_normalized(): void
    {
        $this->assertSame('+31612345678', PhoneNumber::normalize('06-12 34 56 78'));
        $this->assertSame('+31612345678', PhoneNumber::normalize('612345678'));
        $this->assertSame('+31201234567', PhoneNumber::normalize('+31 (0)20 123 4567'));
        $this->assertSame('+31201234567', PhoneNumber::normalize('0031201234567'));
        $this->assertSame('+628123456789', PhoneNumber::normalize('0812 3456 789', 'ID'));
        $this->assertNull(PhoneNumber::normalize('123'));
        $this->assertSame('ct+tel:+31201234567', PhoneNumber::cloudTalkLink('+31201234567'));
    }

    public function test_no_answer_retries_until_max_attempts(): void
    {
        $lead = $this->lead();
        $recorder = app(CallRecorder::class);
        $noAnswer = $this->outcome('telemarketing', 'no_answer');

        $recorder->record($lead, $this->agent, $noAnswer);
        $lead->refresh();
        $this->assertSame(LeadStatus::InProgress, $lead->status);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, $lead->next_call_at->timestamp, 5);

        $recorder->record($lead, $this->agent, $noAnswer);
        $lead->refresh();
        $this->assertSame(LeadStatus::MaxAttempts, $lead->status);
        $this->assertNull($lead->next_call_at);
        $this->assertSame(2, $lead->callAttempts()->count());
    }

    public function test_callback_requires_a_time_and_puts_the_lead_back_at_that_time(): void
    {
        $lead = $this->lead();
        $callback = $this->outcome('telemarketing', 'callback');

        try {
            app(CallRecorder::class)->record($lead, $this->agent, $callback);
            $this->fail('Terugbellen zonder tijd mag niet.');
        } catch (InvalidArgumentException) {
        }

        $when = now()->addDay()->setTime(10, 0);
        app(CallRecorder::class)->record($lead, $this->agent, $callback, 'Na tienen', $when);
        $lead->refresh();
        $this->assertSame(LeadStatus::Callback, $lead->status);
        $this->assertSame($when->toDateTimeString(), $lead->next_call_at->toDateTimeString());
    }

    public function test_appointment_converts_the_lead(): void
    {
        $lead = $this->lead();
        app(CallRecorder::class)->record($lead, $this->agent, $this->outcome('telemarketing', 'appointment'));
        $this->assertSame(LeadStatus::Converted, $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->converted_at);
    }

    public function test_payment_arrangement_is_recorded_with_terms_but_never_marked_paid(): void
    {
        $file = $this->file();
        app(CallRecorder::class)->record($file, $this->agent, $this->outcome('collection', 'arrangement'), null, null, [
            'type' => 'arrangement', 'total_amount' => '100', 'installments' => 3, 'interval' => 'monthly', 'first_payment_date' => '2026-01-31',
        ]);

        $file->refresh();
        $this->assertSame(CollectionStatus::Arrangement, $file->status);
        $this->assertSame(PaymentStatus::Unknown, $file->payment_status);
        $agreement = $file->agreements()->with('terms')->sole();
        $this->assertSame(['33.33', '33.33', '33.34'], $agreement->terms->pluck('amount')->all());
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $agreement->terms->map(fn ($t) => $t->due_date->toDateString())->all());

        // Nieuwe afspraak vervangt de oude
        app(CallRecorder::class)->record($file, $this->agent, $this->outcome('collection', 'promise'), null, null, [
            'type' => 'promise', 'total_amount' => '100', 'first_payment_date' => '2026-02-15',
        ]);
        $this->assertSame(['replaced', 'active'], $file->agreements()->oldest('id')->pluck('status')->all());
        $this->assertSame(CollectionStatus::Promise, $file->fresh()->status);
    }

    public function test_queue_gives_only_own_assigned_records_and_locks_them(): void
    {
        $own = $this->file(['file_number' => 'EIGEN']);
        $this->file(['file_number' => 'ANDER', 'assigned_to' => null]);
        $later = $this->file(['file_number' => 'LATER', 'next_call_at' => now()->addHour()]);

        $queue = app(CallQueue::class);
        $this->assertSame(1, $queue->count($this->agent, CallQueue::COLLECTION));
        $next = $queue->next($this->agent, CallQueue::COLLECTION);
        $this->assertTrue($next->is($own));
        $this->assertSame($this->agent->id, $next->fresh()->locked_by);

        // een supervisor ziet ook niet-toegewezen dossiers, maar niet wat vergrendeld is
        $supervisor = $this->makeUser(companyRoles: ['sv' => Roles::SUPERVISOR]);
        $this->actingAs($supervisor);
        $this->inCompany($this->sv);
        $this->assertSame(['ANDER'], $queue->query($supervisor, CallQueue::COLLECTION)->pluck('file_number')->all());
        $this->assertFalse($queue->query($supervisor, CallQueue::COLLECTION)->whereKey($later->id)->exists());
    }

    public function test_agents_only_see_their_own_files(): void
    {
        $own = $this->file();
        $other = $this->file(['assigned_to' => null]);

        $this->assertTrue($this->agent->can('view', $own));
        $this->assertFalse($this->agent->can('view', $other));
        $this->assertSame([$own->id], CollectionFile::visibleTo($this->agent)->pluck('id')->all());
        $this->assertFalse($this->agent->can('create', CollectionFile::class));
    }

    public function test_lead_import_detects_duplicates_and_keeps_extra_columns(): void
    {
        $client = Client::create(['name' => 'Opdrachtgever']);
        $campaign = Campaign::create(['client_id' => $client->id, 'name' => 'Import']);
        Storage::disk('local')->put('i/leads.csv', "Bedrijf;Telefoon;Branche\nA;020-1234567;Bouw\nB;+31 20 123 4567;Zorg\nC;;Horeca\n");

        $import = (new LeadImporter($campaign))->run('i/leads.csv', 'leads.csv', ['campaign_id' => $campaign->id]);

        $this->assertSame([1, 1, 1], [$import->imported, $import->duplicates, $import->failed]);
        $this->assertSame(['Branche' => 'Bouw'], $campaign->leads()->sole()->extra);
    }

    public function test_payment_import_marks_only_fully_paid_files_as_successful_and_calculates_commission(): void
    {
        $client = Client::create(['name' => 'Energie BV']);
        $path = Storage::disk('local')->path('i/dossiers.xlsx');
        @mkdir(dirname($path), 0777, true);
        Spreadsheet::write($path, ['Dossiernummer', 'Debiteur', 'Telefoon', 'Openstaand bedrag'], [
            ['A1', 'Volledig', '0611111111', '200,00'], ['A2', 'Deels', '0622222222', '500'], ['A3', 'Met afspraak', '0633333333', '1000'],
        ]);
        $import = (new CollectionFileImporter($client))->run('i/dossiers.xlsx', 'dossiers.xlsx');
        $this->assertSame(3, $import->imported);

        // Bij A3 is een lager bedrag afgesproken: dat bedrag telt
        $a3 = CollectionFile::where('file_number', 'A3')->first();
        app(CallRecorder::class)->record($a3, $this->agent, $this->outcome('collection', 'promise'), null, null,
            ['type' => 'promise', 'total_amount' => 800, 'first_payment_date' => '2026-12-01']);

        CommissionModel::create(['client_id' => $client->id, 'name' => '10%', 'type' => 'percent_paid', 'rate' => 10]);
        Storage::disk('local')->put('i/betaald.csv', "Dossiernummer;Betaald bedrag;Betaaldatum\nA1;200;01-12-2026\nA2;250;01-12-2026\nA3;800;02-12-2026\nX9;10;\n");

        $result = (new PaymentImporter($client))->run('i/betaald.csv', 'betaald.csv');
        $this->assertSame([3, 1], [$result->imported, $result->failed]);

        $files = CollectionFile::all()->keyBy('file_number');
        $this->assertSame(PaymentStatus::Paid, $files['A1']->payment_status);
        $this->assertSame('20.00', $files['A1']->commission_amount);
        $this->assertSame(PaymentStatus::Partial, $files['A2']->payment_status);
        $this->assertNull($files['A2']->commission_amount);
        $this->assertSame(PaymentStatus::Paid, $files['A3']->payment_status);
        $this->assertSame('80.00', $files['A3']->commission_amount);

        // Hetzelfde bestand nog een keer: niets dubbel
        $again = (new PaymentImporter($client))->run('i/betaald.csv', 'betaald.csv');
        $this->assertSame(3, $again->duplicates);
        $this->assertSame('200.00', CollectionFile::where('file_number', 'A1')->value('paid_amount'));
    }

    public function test_commission_models(): void
    {
        $file = $this->file(['original_amount' => 400, 'outstanding_amount' => 300]);
        $file->forceFill(['paid_amount' => 300])->save();

        $calc = fn (string $type, float $rate) => (new CommissionModel(['type' => $type, 'rate' => $rate]))->calculate($file);
        $this->assertSame(45.0, $calc('percent_paid', 15));
        $this->assertSame(25.0, $calc('fixed_per_success', 25));
        $this->assertSame(40.0, $calc('percent_original', 10));
    }
}
