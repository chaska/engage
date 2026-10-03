#!/usr/bin/env bash
# 0.6.17: pruebas de Gravatar con consentimiento previo: HTTP (curl/PHP), navegador real (Playwright + Chromium) y
# navegador con la cache de pagina de Joomla activada. Requiere 03-sembrar-y-probar.sh ya ejecutado. Sale con 1 si algo FALLA.
# Variables: PW_MODULE (ruta de node_modules/playwright), CHROMIUM (ejecutable; por defecto /opt/pw-browsers/chromium),
# PLAYWRIGHT_BROWSERS_PATH. Si no hay navegador, la parte de navegador se marca NO PROBADO y el script sale con 2.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
rc=0
CACHE_PAGE="${WORK:?}/${SITE_DIR:?}/administrator/cache/page"
php "$AQUI/lib/gravatar-http.php" || rc=1
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"
	node "$AQUI/lib/gravatar-navegador.js" || rc=1
	# Con la cache de pagina de Joomla (plugin system/cache + caching=1) y el plugin engagecache
	CFG="$WORK/$SITE_DIR/configuration.php"; cp "$CFG" "$WORK/configuration.php.pre-cache"
	sed -i 's/public \$caching = 0;/public $caching = 1;/' "$CFG"
	mysql -uroot "$DB_NAME" -e "UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element IN ('cache','engagecache')"
	rm -rf "$CACHE_PAGE"
	CACHE_PAGINA=1 node "$AQUI/lib/gravatar-navegador.js" || rc=1
	cp "$WORK/configuration.php.pre-cache" "$CFG"
	mysql -uroot "$DB_NAME" -e "UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='system' AND element IN ('cache','engagecache')"
	rm -rf "$CACHE_PAGE"
else
	echo "NO PROBADO: no hay Playwright/Chromium (PW_MODULE, CHROMIUM)"; rc=2
fi
exit $rc
