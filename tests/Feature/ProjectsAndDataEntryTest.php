<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\DataJob;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\DomainExpiryReminders;
use App\Support\Permissions\Roles;
use App\Support\Reports\WorkReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 4: websiteprojecten, data-entry en urenregistratie.
 */
class ProjectsAndDataEntryTest extends TestCase
{
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inCompany($this->sv);
        $this->client = Client::create(['name' => 'Klant BV']);
    }

    public function test_tables_live_only_in_the_company_database(): void
    {
        Project::create(['client_id' => $this->client->id, 'name' => 'Site']);
        $this->assertSame(1, DB::connection('company_sv')->table('projects')->count());
        $this->assertSame(0, DB::connection('company_yes')->table('projects')->count());
        $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('time_entries'));
    }

    public function test_hosting_credentials_are_encrypted_and_never_serialized(): void
    {
        $project = Project::create(['client_id' => $this->client->id, 'name' => 'Site']);
        $hosting = $project->hostings()->create(['provider' => 'Hostinger', 'username' => 'u123', 'password' => 'Geheim-1']);

        $raw = DB::connection('company_sv')->table('project_hostings')->first();
        $this->assertNotSame('Geheim-1', $raw->password);
        $this->assertStringNotContainsString('Geheim', $raw->password);
        $this->assertSame('Geheim-1', $hosting->fresh()->password);
        $this->assertArrayNotHasKey('password', $hosting->fresh()->toArray());
        $this->assertArrayNotHasKey('username', $hosting->fresh()->attributesToArray());
    }

    public function test_project_members_only_see_their_own_projects(): void
    {
        $member = $this->makeUser(companyRoles: ['sv' => Roles::PROJECT]);
        $this->actingAs($member);
        $this->inCompany($this->sv);
        $own = Project::create(['client_id' => $this->client->id, 'name' => 'Eigen', 'assigned_to' => $member->id]);
        $other = Project::create(['client_id' => $this->client->id, 'name' => 'Ander']);

        $this->assertSame(['Eigen'], Project::visibleTo($member)->pluck('name')->all());
        $this->assertTrue($member->can('view', $own));
        $this->assertFalse($member->can('view', $other));
        $this->assertFalse($member->can('create', Project::class));
        $this->assertTrue($member->can('credentials.view'));
    }

    public function test_status_dates_are_set_automatically(): void
    {
        $project = Project::create(['client_id' => $this->client->id, 'name' => 'Site']);
        $project->update(['status' => ProjectStatus::Delivered]);
        $this->assertNotNull($project->fresh()->delivered_at);

        $request = $project->requests()->create(['title' => 'Logo groter']);
        $request->update(['status' => RequestStatus::Done]);
        $this->assertNotNull($request->fresh()->completed_at);
        $request->update(['status' => RequestStatus::Open]);
        $this->assertNull($request->fresh()->completed_at);
    }

    public function test_monthly_costs_add_up_maintenance_hosting_and_domains(): void
    {
        $project = Project::create(['client_id' => $this->client->id, 'name' => 'Site', 'maintenance_fee' => 49]);
        $project->hostings()->create(['provider' => 'Hostinger', 'monthly_cost' => 6.99]);
        $project->domains()->create(['domain' => 'klant.nl', 'yearly_cost' => 12]);

        $this->assertSame(56.99, $project->monthlyTotal());
    }

    public function test_domain_expiry_reminder_is_sent_once_and_again_after_a_new_date(): void
    {
        $admin = $this->makeUser(superadmin: true);
        $project = Project::create(['client_id' => $this->client->id, 'name' => 'Site']);
        $domain = $project->domains()->create(['domain' => 'klant.nl', 'expires_at' => now()->addDays(10)]);
        $project->domains()->create(['domain' => 'later.nl', 'expires_at' => now()->addDays(90)]);

        $this->assertSame(1, app(DomainExpiryReminders::class)->send($this->sv));
        $this->assertSame(0, app(DomainExpiryReminders::class)->send($this->sv));

        $domain->update(['expires_at' => now()->addDays(20)]);
        $this->assertSame(1, app(DomainExpiryReminders::class)->send($this->sv));
        $this->assertSame(2, DB::connection('central')->table('notifications')->where('notifiable_id', $admin->id)->count());
    }

    public function test_data_entry_workers_only_see_assigned_jobs(): void
    {
        $worker = $this->makeUser(companyRoles: ['sv' => Roles::DATA_ENTRY]);
        $this->actingAs($worker);
        $this->inCompany($this->sv);
        $own = DataJob::create(['client_id' => $this->client->id, 'name' => 'Eigen']);
        $own->syncAssignees([$worker->id]);
        DataJob::create(['client_id' => $this->client->id, 'name' => 'Ander']);

        $this->assertSame(['Eigen'], DataJob::visibleTo($worker)->pluck('name')->all());
        $this->assertTrue($worker->can('logProgress', $own));
        $this->assertFalse($worker->can('update', $own));
    }

    public function test_progress_productivity_and_value_per_pricing_model(): void
    {
        $user = $this->makeUser(companyRoles: ['sv' => Roles::DATA_ENTRY]);
        $service = ClientService::create(['client_id' => $this->client->id, 'service' => 'data_entry', 'pricing_model' => 'per_record', 'price' => 0.15, 'currency' => 'EUR']);
        $job = DataJob::create(['client_id' => $this->client->id, 'client_service_id' => $service->id, 'name' => 'Adressen', 'total_records' => 1000]);
        $job->timeEntries()->create(['user_id' => $user->id, 'date' => '2026-11-10', 'minutes' => 150, 'records_done' => 250, 'errors_found' => 5]);
        $job->timeEntries()->create(['user_id' => $user->id, 'date' => '2026-11-11', 'minutes' => 90, 'records_done' => 150]);

        $this->assertSame(400, $job->recordsDone());
        $this->assertSame(40.0, $job->progress());
        $this->assertSame(4.0, $job->hours());
        $this->assertSame(60.0, $job->value());

        $service->update(['pricing_model' => 'per_hour', 'price' => 25]);
        $this->assertSame(100.0, $job->fresh()->value());
        $service->update(['pricing_model' => 'monthly']);
        $this->assertNull($job->fresh()->value());

        $report = (new WorkReport(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')->endOfDay()))->dataEntry(null);
        $this->assertSame('400', $report['summary'][__('Records verwerkt')]);
        $this->assertSame('100,0', $report['summary'][__('Records per uur')]);
        $this->assertSame('1,3%', $report['summary'][__('Foutpercentage')]);
    }

    public function test_durations_are_parsed(): void
    {
        $this->assertSame(90, TimeEntry::parseDuration('1:30'));
        $this->assertSame(90, TimeEntry::parseDuration('1,5'));
        $this->assertSame(45, TimeEntry::parseDuration('45m'));
        $this->assertSame(120, TimeEntry::parseDuration('2u'));
        $this->assertNull(TimeEntry::parseDuration('veel'));
    }

    public function test_employees_only_see_their_own_hours(): void
    {
        $a = $this->makeUser(companyRoles: ['sv' => Roles::PROJECT]);
        $b = $this->makeUser(companyRoles: ['sv' => Roles::PROJECT]);
        $project = Project::create(['client_id' => $this->client->id, 'name' => 'Site']);
        $mine = $project->timeEntries()->create(['user_id' => $a->id, 'date' => now(), 'minutes' => 60]);
        $project->timeEntries()->create(['user_id' => $b->id, 'date' => now(), 'minutes' => 30]);

        $this->actingAs($a);
        $this->inCompany($this->sv);
        $this->assertSame([60], TimeEntry::visibleTo($a)->pluck('minutes')->all());
        $this->assertTrue($a->can('update', $mine));

        $mine->update(['date' => now()->subDays(30)]);
        $this->assertFalse($a->can('update', $mine->fresh()), 'oude uren niet meer zelf aanpassen');
    }
}
