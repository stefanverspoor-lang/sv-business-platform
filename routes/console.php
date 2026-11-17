<?php

use Illuminate\Support\Facades\Schedule;

/*
| Geplande taken. Op Hostinger draait elke minuut een cron-taak:
|   php /home/u683887547/domains/svbusinesssupport.com/crm-app/artisan schedule:run
*/

// Dagelijkse back-up van de drie databases (tijden in UTC).
Schedule::command('crm:backup')->dailyAt('18:00')->withoutOverlapping();

// Wachtrij verwerken zonder continu draaiend proces (gedeelde hosting).
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// Oude sessies en verlopen wachtwoord-links opruimen.
Schedule::command('auth:clear-resets')->daily();
