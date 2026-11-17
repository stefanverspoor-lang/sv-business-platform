<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\TenantManager;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Koppelt bij elk verzoek de database van het gekozen bedrijf en zet de
 * rechten (rollen) op dat bedrijf. Draait ook bij Livewire-verzoeken.
 */
class ActivateCompanyDatabase
{
    public function __construct(protected TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = Filament::getTenant();

        if ($company instanceof Company) {
            $this->tenants->activate($company);
            setPermissionsTeamId($company->getKey());
            $request->user()?->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $next($request);
    }
}
