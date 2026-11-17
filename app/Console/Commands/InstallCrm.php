<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Permissions\Roles;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Eerste installatie en latere updates: migraties, bedrijven, rollen en rechten.
 * Kan veilig vaker worden uitgevoerd; bestaande gegevens blijven staan.
 */
class InstallCrm extends Command
{
    protected $signature = 'crm:install {--force : Uitvoeren in productie zonder vraag}';

    protected $description = 'Databases bijwerken en bedrijven, rollen en rechten aanmaken';

    public function handle(): int
    {
        $force = $this->option('force') || ! app()->isProduction();

        $this->components->info('Centrale database');
        $this->call('migrate', ['--database' => 'central', '--force' => $force]);

        $this->call('crm:migrate-companies', ['--force' => $force]);

        $this->components->info('Bedrijven');
        foreach (config('crm.companies') as $data) {
            $company = Company::firstOrCreate(['slug' => $data['slug']], $data);
            $this->line("  {$company->name}");
        }

        $this->components->info('Rollen en rechten');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Roles::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (Roles::defaults() as $roleName => $permissions) {
            // Rollen zijn algemeen (team_id leeg) en worden per bedrijf toegekend.
            $role = Role::query()->whereNull(config('permission.column_names.team_foreign_key'))
                ->where(['name' => $roleName, 'guard_name' => 'web'])->first()
                ?? Role::create(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $this->line('  '.Roles::label($roleName));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->components->info('Klaar. Maak zo nodig de superadmin aan met: php artisan crm:create-superadmin');

        return self::SUCCESS;
    }
}
