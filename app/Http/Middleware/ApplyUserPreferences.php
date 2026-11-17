<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Taal en tijdzone per gebruiker.
 * Tijden staan in UTC in de database en worden getoond in de tijdzone van de
 * gebruiker: die uit het profiel, anders die van de computer (doorgegeven via
 * een cookie), anders de standaardtijdzone.
 */
class ApplyUserPreferences
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locales = array_keys(config('crm.locales'));

        $locale = $user?->locale ?? config('app.locale');
        App::setLocale(in_array($locale, $locales, true) ? $locale : 'nl');

        $timezone = $user?->timezone ?: $request->cookie('crm_tz');
        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = config('crm.fallback_timezone');
        }
        FilamentTimezone::set($timezone);

        return $next($request);
    }
}
