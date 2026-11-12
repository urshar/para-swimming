#!/usr/bin/env bash
# Update auf dem Produktionsserver (netcup Webhosting, docs/deployment.md). Auf dem Server im Projektordner:
#
#   bash scripts/deploy.sh
#
# Vorher lokal "scripts/upload-build.ps1" ausführen, wenn sich CSS/JS geändert haben — der Server baut keine Assets.
# PHP- und Composer-Pfad lassen sich per Umgebungsvariable überschreiben (PHP=..., COMPOSER=...).
set -euo pipefail

PHP="${PHP:-/usr/local/php84/bin/php}"
COMPOSER="${COMPOSER:-$HOME/tools/composer.phar}"

cd "$(dirname "$0")/.."

if [ ! -f public/build/manifest.json ]; then
    echo "public/build/manifest.json fehlt — zuerst lokal scripts/upload-build.ps1 ausführen." >&2
    exit 1
fi

"$PHP" artisan down --retry=60
trap '"$PHP" artisan up' EXIT

git pull --ff-only
"$PHP" -d memory_limit=1G "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear
# Kein "optimize"/"config:cache": Der Config-Cache enthält absolute Pfade, und die SSH-Sitzung (chroot,
# /paraswim.at/...) sieht andere als der Webserver (/var/www/vhosts/...). Routen- und Event-Cache enthalten keine
# Pfade und sind unbedenklich.
"$PHP" artisan route:cache
"$PHP" artisan event:cache
"$PHP" artisan queue:restart

echo "Deployment fertig: $(git log -1 --format='%h %s')"
