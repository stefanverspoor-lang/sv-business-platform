#!/usr/bin/env bash
# =============================================================
# SV Business Platform - bijwerken op de server (Hostinger)
# Gebruik (via SSH, in de map crm-app):  bash deploy.sh
# =============================================================
set -euo pipefail
cd "$(dirname "$0")"

echo "==> Onderhoudsmodus aan"
php artisan down --retry=15 || true
# Gaat er onderweg iets mis, dan gaat het CRM weer online en zie je waar het stopte.
trap 'echo ""; echo "!! Bijwerken is gestopt bij een fout (zie hierboven). Het CRM gaat weer online met de vorige instellingen."; php artisan up || true' ERR

echo "==> Back-up van de drie databases"
STAMP=$(date +%Y%m%d-%H%M%S)
php artisan crm:backup --stamp="$STAMP" || echo "Let op: back-up mislukt, controleer dit voor je verdergaat."

echo "==> Nieuwste versie ophalen"
git pull --ff-only

echo "==> Afhankelijkheden installeren"
# --no-scripts: Hostinger staat proc_open niet toe; package:discover doen we zelf.
# Komt er een nieuw onderdeel bij, dan klopt composer.lock niet meer: dan 'update'.
if [ -f composer.lock ] && composer install --no-dev --optimize-autoloader --no-interaction --no-scripts; then
  :
else
  composer update --no-dev --optimize-autoloader --no-interaction --no-scripts
fi
php artisan package:discover

echo "==> Databases bijwerken, rollen en rechten"
php artisan crm:install --force

echo "==> Bestanden van het beheerscherm"
php artisan filament:assets

echo "==> Caches opbouwen"
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize || true

echo "==> Onderhoudsmodus uit"
php artisan up
echo "Klaar."
