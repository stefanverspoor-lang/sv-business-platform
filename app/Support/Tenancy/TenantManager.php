<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Koppelt de databaseverbinding "tenant" aan de database van het actieve bedrijf.
 *
 * Alle bedrijfsmodellen (klanten, contactpersonen, ...) gebruiken de verbinding
 * "tenant". Zolang er geen bedrijf actief is, wijst die verbinding naar een
 * lege in-memory database, zodat er nooit per ongeluk gegevens van het
 * verkeerde bedrijf getoond worden.
 */
class TenantManager
{
    protected ?Company $company = null;

    public function activate(Company $company): void
    {
        $config = config("database.connections.{$company->connection}");

        if (! is_array($config)) {
            throw new RuntimeException("Onbekende databaseverbinding [{$company->connection}] voor bedrijf {$company->id}.");
        }

        if ($this->company?->is($company)) {
            return;
        }

        DB::purge('tenant');
        config(['database.connections.tenant' => $config]);
        DB::reconnect('tenant');

        $this->company = $company;
    }

    public function deactivate(): void
    {
        DB::purge('tenant');
        config(['database.connections.tenant' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        $this->company = null;
    }

    public function current(): ?Company
    {
        return $this->company;
    }

    public function currentOrFail(): Company
    {
        return $this->company ?? throw new RuntimeException('Er is geen bedrijf actief.');
    }

    /**
     * Voer code uit in de context van een bepaald bedrijf en zet daarna het
     * vorige bedrijf terug. Gebruikt voor het gecombineerde dashboard.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runFor(Company $company, callable $callback): mixed
    {
        $previous = $this->company;
        $this->activate($company);

        try {
            return $callback();
        } finally {
            $previous ? $this->activate($previous) : $this->deactivate();
        }
    }
}
