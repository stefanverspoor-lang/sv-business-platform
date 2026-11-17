<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use App\Support\Permissions\Roles;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    protected Company $sv;

    protected Company $yes;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['central', 'company_sv', 'company_yes'] as $connection) {
            $file = base_path(config("database.connections.{$connection}.database"));
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, '');
            config(["database.connections.{$connection}.database" => $file]);
        }

        Artisan::call('crm:install', ['--force' => true]);

        $this->sv = Company::where('slug', 'sv')->firstOrFail();
        $this->yes = Company::where('slug', 'yes')->firstOrFail();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->deactivate();
        parent::tearDown();
    }

    protected function makeUser(array $attributes = [], array $companyRoles = [], bool $superadmin = false): User
    {
        $user = User::factory()->create($attributes);
        $user->is_superadmin = $superadmin;
        $user->save();

        foreach ($companyRoles as $slug => $role) {
            $company = $slug === 'sv' ? $this->sv : $this->yes;
            $user->companies()->syncWithoutDetaching([$company->id]);
            setPermissionsTeamId($company->id);
            $user->unsetRelation('roles');
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    protected function inCompany(Company $company): void
    {
        app(TenantManager::class)->activate($company);
        setPermissionsTeamId($company->id);
        auth()->user()?->unsetRelation('roles')->unsetRelation('permissions');
    }

    protected function companyRole(string $role): string
    {
        return constant(Roles::class.'::'.$role);
    }
}
