<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\Client;
use App\Support\Permissions\Roles;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    public function test_user_of_sv_cannot_access_yes(): void
    {
        $user = $this->makeUser(companyRoles: ['sv' => Roles::SUPERVISOR]);

        $this->assertTrue($user->canAccessTenant($this->sv));
        $this->assertFalse($user->canAccessTenant($this->yes));
    }

    public function test_superadmin_can_access_both_companies(): void
    {
        $admin = $this->makeUser(superadmin: true);

        $this->assertTrue($admin->canAccessTenant($this->sv));
        $this->assertTrue($admin->canAccessTenant($this->yes));
    }

    public function test_inactive_company_is_not_accessible(): void
    {
        $admin = $this->makeUser(superadmin: true);
        $this->yes->update(['is_active' => false]);

        $this->assertFalse($admin->canAccessTenant($this->yes->fresh()));
    }

    public function test_role_applies_only_in_its_own_company(): void
    {
        $user = $this->makeUser(companyRoles: ['sv' => Roles::COMPANY_MANAGER, 'yes' => Roles::CALLCENTER]);
        $this->actingAs($user);

        $this->inCompany($this->sv);
        $this->assertTrue($user->can('users.manage'));

        $this->inCompany($this->yes);
        $this->assertFalse($user->can('users.manage'));
        $this->assertTrue($user->can('clients.view'));
    }

    public function test_callcenter_agent_sees_only_assigned_clients(): void
    {
        $agent = $this->makeUser(companyRoles: ['sv' => Roles::CALLCENTER]);
        $this->actingAs($agent);
        $this->inCompany($this->sv);

        $assigned = Client::create(['name' => 'Toegewezen']);
        Client::create(['name' => 'Niet toegewezen']);
        AccessGrant::create(['user_id' => $agent->id, 'grantable_type' => Client::class, 'grantable_id' => $assigned->id]);

        $this->assertSame(['Toegewezen'], Client::query()->visibleTo($agent)->pluck('name')->all());
        $this->assertTrue($agent->can('view', $assigned));
        $this->assertFalse($agent->can('update', $assigned));
    }

    public function test_supervisor_sees_all_clients_of_own_company(): void
    {
        $supervisor = $this->makeUser(companyRoles: ['sv' => Roles::SUPERVISOR]);
        $this->actingAs($supervisor);
        $this->inCompany($this->sv);

        Client::create(['name' => 'Een']);
        Client::create(['name' => 'Twee']);

        $this->assertSame(2, Client::query()->visibleTo($supervisor)->count());
        $this->assertFalse($supervisor->can('delete', Client::first()));
    }

    public function test_changes_are_written_to_the_audit_log(): void
    {
        $manager = $this->makeUser(companyRoles: ['sv' => Roles::COMPANY_MANAGER]);
        $this->actingAs($manager);
        $this->inCompany($this->sv);

        $client = Client::create(['name' => 'Oud']);
        $client->update(['name' => 'Nieuw']);

        $log = \App\Models\AuditLog::where('subject_type', Client::class)->where('event', 'updated')->firstOrFail();
        $this->assertSame($this->sv->id, $log->company_id);
        $this->assertSame(['old' => 'Oud', 'new' => 'Nieuw'], $log->changes['name']);
    }
}
