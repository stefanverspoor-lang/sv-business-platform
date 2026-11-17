#!/usr/bin/env bash
# =============================================================
# SV Business Platform - bijwerken op de server (Hostinger)
# Gebruik (via SSH, in de map crm-app):  bash deploy.sh
# =============================================================
set -euo pipefail
cd "$(dirname "$0")"

echo "==> Onderhoudsmodus aan"
php artisan down --retry=15 || true

echo "==> Back-up van de drie databases"
STAMP=$(date +%Y%m%d-%H%M%S)
php artisan crm:backup --stamp="$STAMP" || echo "Let op: back-up mislukt, controleer dit voor je verdergaat."

echo "==> Nieuwste versie ophalen"
git pull --ff-only

echo "==> Afhankelijkheden installeren"
if [ -f composer.lock ]; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  composer update --no-dev --optimize-autoloader --no-interaction
fi

echo "==> Databases bijwerken, rollen en rechten"
php artisan crm:install --force

echo "==> Bestanden van het beheerscherm"
php artisan filament:assets
php artisan storage:link || true

echo "==> Caches opbouwen"
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize || true

echo "==> Onderhoudsmodus uit"
php artisan up
echo "Klaar."
