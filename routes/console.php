<?php

/*
| Geplande taken lopen via php artisan crm:tick (zie App\Console\Commands\Tick).
| Op Hostinger draait daarvoor elke minuut een cron-taak:
|   /opt/alt/php83/usr/bin/php /home/u683887547/domains/svbusinesssupport.com/crm-app/artisan crm:tick
| De standaard Laravel-planner (schedule:run) werkt daar niet, omdat Hostinger
| het starten van andere programma's vanuit PHP niet toestaat.
*/
