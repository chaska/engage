#!/usr/bin/env bash
# Genera (si hace falta) e instala dist/pkg_engage-*.zip con `extension:install`, y vuelca el estado resultante.
# Uso: ./02-instalar-engage.sh [ruta/al/paquete.zip]
set -euo pipefail
source "$(dirname "$0")/config.sh"
PKG="${1:-}"
if [ -z "$PKG" ]; then
	[ -n "$(ls "$REPO"/dist/pkg_engage-*.zip 2>/dev/null)" ] || php "$REPO/build/build.php" >/dev/null
	PKG="$(ls "$REPO"/dist/pkg_engage-*.zip | head -1)"
fi
cp "$PKG" "$WORK/pkg-actual.zip"
cd "$WORK/$SITE_DIR"
php cli/joomla.php extension:install --path="$WORK/pkg-actual.zip" 2>&1 | tail -3
# Tras instalar hay que reiniciar el servidor `php -S` (opcache/cache de rutas del proceso) y borrar los mapas de clases.
rm -f cache/autoload_psr4.php administrator/cache/autoload_psr4.php
bash "$AQUI/lib/servidor.sh"
M="mysql $DB_NAME -t -e"
$M "SELECT extension_id, type, element, folder, enabled, JSON_VALUE(manifest_cache,'\$.version') AS version FROM jos_extensions WHERE element LIKE '%engage%' OR folder='engage' ORDER BY type, folder, element"
$M "SHOW TABLES LIKE 'jos_engage%'"
$M "SELECT update_site_id, name, type, location, enabled, last_check_timestamp FROM jos_update_sites WHERE name LIKE '%ngage%'"
$M "SELECT us.update_site_id, e.element FROM jos_update_sites_extensions us JOIN jos_extensions e USING (extension_id) WHERE e.element LIKE '%engage%' OR e.folder='engage'"
$M "SELECT * FROM jos_schemas WHERE extension_id = (SELECT extension_id FROM jos_extensions WHERE element='com_engage' AND type='component')"
php cli/joomla.php list 2>&1 | grep -i engage || echo "AVISO: el comando engage:* no aparece en 'list'"
