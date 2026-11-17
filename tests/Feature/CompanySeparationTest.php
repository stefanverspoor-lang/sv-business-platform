<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * De belangrijkste eis: gegevens van SV en YES lopen nooit door elkaar.
 */
class CompanySeparationTest extends TestCase
{
    public function test_a_client_of_sv_is_stored_only_in_the_sv_database(): void
    {
        $this->inCompany($this->sv);
        Client::create(['name' => 'Klant van SV']);

        $this->assertSame(1, DB::connection('company_sv')->table('clients')->count());
        $this->assertSame(0, DB::connection('company_yes')->table('clients')->count());
    }

    public function test_switching_company_shows_only_that_companys_clients(): void
    {
        $this->inCompany($this->sv);
        Client::create(['name' => 'Alleen SV']);

        $this->inCompany($this->yes);
        Client::create(['name' => 'Alleen YES']);

        $this->assertSame(['Alleen YES'], Client::pluck('name')->all());

        $this->inCompany($this->sv);
        $this->assertSame(['Alleen SV'], Client::pluck('name')->all());
    }

    public function test_without_active_company_no_client_data_is_reachable(): void
    {
        $this->inCompany($this->sv);
        Client::create(['name' => 'Geheim']);

        app(TenantManager::class)->deactivate();

        $this->expectException(\Illuminate\Database\QueryException::class);
        Client::count(); // lege in-memory database: tabel bestaat niet
    }

    public function test_combined_view_counts_both_companies_without_mixing(): void
    {
        $tenants = app(TenantManager::class);
        $tenants->runFor($this->sv, fn () => Client::create(['name' => 'A']));
        $tenants->runFor($this->yes, fn () => Client::create(['name' => 'B']));
        $tenants->runFor($this->yes, fn () => Client::create(['name' => 'C']));

        $this->assertSame(1, $tenants->runFor($this->sv, fn () => Client::count()));
        $this->assertSame(2, $tenants->runFor($this->yes, fn () => Client::count()));
        $this->assertNull($tenants->current());
    }

    public function test_company_databases_have_the_same_structure(): void
    {
        $sv = collect(DB::connection('company_sv')->getSchemaBuilder()->getTableListing(schemaQualified: false))->sort()->values();
        $yes = collect(DB::connection('company_yes')->getSchemaBuilder()->getTableListing(schemaQualified: false))->sort()->values();

        $this->assertEquals($sv, $yes);
        $this->assertContains('clients', $sv->all());
        $this->assertNotContains('users', $sv->all());
    }
}
