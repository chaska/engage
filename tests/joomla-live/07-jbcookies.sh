#!/usr/bin/env bash
# 0.6.19: Gravatar gobernado por el modulo JBCookies (mod_jbcookies, de JoomBall) en un Joomla real + Chromium.
# El ZIP del modulo NO esta en el repositorio (su licencia es ambigua: GPL v3 en LICENSE/XML, "Non-Commercial" en su README):
# se pasa por variable de entorno y solo se usa para instalarlo en el sitio de pruebas desechable.
#   JBCOOKIES_ZIP=/ruta/JBCookies-6.0.2.zip bash 07-jbcookies.sh
# Requisitos: 02 y 03 ya ejecutados (sitio con Engage y articulos sembrados), Playwright + /opt/pw-browsers/chromium
# (PW_MODULE, CHROMIUM, PLAYWRIGHT_BROWSERS_PATH). Sale con 1 si algo FALLA y con 2 si no se pudo probar (NO PROBADO).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
: "${JBCOOKIES_ZIP:=}"
if [ -z "$JBCOOKIES_ZIP" ] || [ ! -f "$JBCOOKIES_ZIP" ]; then
	echo "NO PROBADO: define JBCOOKIES_ZIP con la ruta al ZIP de JBCookies (mod_jbcookies); no se incluye en el repositorio"; exit 2
fi
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ ! -d "$PW" ] || [ ! -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	echo "NO PROBADO: no hay Playwright/Chromium (PW_MODULE, CHROMIUM)"; exit 2
fi
export PW_MODULE="$PW"
echo "ZIP de JBCookies: $(basename "$JBCOOKIES_ZIP")  sha256=$(sha256sum "$JBCOOKIES_ZIP" | cut -d' ' -f1)"
rc=0
SITE="$WORK/$SITE_DIR"
MQ="mysql -uroot $DB_NAME -N -e"

# 1. Instalar el modulo. Si ya esta instalado se reutiliza: reinstalarlo encima falla con Joomla 6.1.4 porque su script.php
#    (rama de actualizacion, linea ~119) usa la clase retirada Joomla\CMS\Filesystem\File. La instalacion limpia si funciona.
cp "$JBCOOKIES_ZIP" "$WORK/jbcookies.zip"
if [ -n "$($MQ "SELECT extension_id FROM jos_extensions WHERE element='mod_jbcookies' AND type='module'")" ]; then
	echo "mod_jbcookies ya instalado en el sitio de pruebas: se reutiliza"
elif ! (cd "$SITE" && php cli/joomla.php extension:install --path="$WORK/jbcookies.zip" 2>&1 | tail -4 | tee "$WORK/jbcookies-instalacion.txt" | grep -q "installed successfully"); then
	# El instalador de Joomla acepta normalmente un ZIP con una carpeta raiz unica. Si no, se reempaqueta con el manifiesto en la raiz (en $WORK).
	echo "Instalacion directa fallida; se reempaqueta con mod_jbcookies.xml en la raiz"
	rm -rf "$WORK/jbc-raiz" && mkdir -p "$WORK/jbc-raiz" && unzip -q "$JBCOOKIES_ZIP" -d "$WORK/jbc-raiz"
	INNER="$(dirname "$(find "$WORK/jbc-raiz" -name mod_jbcookies.xml | head -1)")"
	(cd "$INNER" && rm -f "$WORK/jbcookies-raiz.zip" && zip -qr "$WORK/jbcookies-raiz.zip" .)
	(cd "$SITE" && php cli/joomla.php extension:install --path="$WORK/jbcookies-raiz.zip" 2>&1 | tail -4)
else
	echo "mod_jbcookies instalado directamente desde el ZIP original (carpeta raiz JBCookies-x.y.z/ incluida)"
fi
rm -f "$SITE/cache/autoload_psr4.php" "$SITE/administrator/cache/autoload_psr4.php"
$MQ "SELECT CONCAT('modulo instalado: ', element, ' v', JSON_VALUE(manifest_cache,'\$.version')) FROM jos_extensions WHERE element='mod_jbcookies'"
MID="$($MQ "SELECT id FROM jos_modules WHERE module='mod_jbcookies' ORDER BY id LIMIT 1")"
[ -n "$MID" ] || { echo "FALLA: el modulo no quedo instalado"; exit 1; }

# 2. Publicarlo en una posicion del tema (Cassiopeia: footer), en todas las paginas
$MQ "UPDATE jos_modules SET position='footer', published=1, access=1, language='*', showtitle=0 WHERE id=$MID; INSERT IGNORE INTO jos_modules_menu (moduleid, menuid) VALUES ($MID, 0)"
bash "$AQUI/lib/servidor.sh"

# 3. Comentarios de prueba (2: invitado y registrado) y navegador real
php "$AQUI/lib/gravatar-http.php" >/dev/null || { echo "FALLA: no se pudieron sembrar los comentarios"; exit 1; }
node "$AQUI/lib/jbcookies-navegador.js" || rc=1

# 4. Con la cache de pagina de Joomla (plugin system/cache + caching=1) y engagecache
CFG="$SITE/configuration.php"; CACHE_PAGE="$SITE/administrator/cache/page"
cp "$CFG" "$WORK/configuration.php.pre-cache"
sed -i 's/public \$caching = 0;/public $caching = 1;/' "$CFG"
mysql -uroot "$DB_NAME" -e "UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element IN ('cache','engagecache')"
rm -rf "$CACHE_PAGE"
CACHE_PAGINA=1 node "$AQUI/lib/jbcookies-navegador.js" || rc=1
cp "$WORK/configuration.php.pre-cache" "$CFG"
mysql -uroot "$DB_NAME" -e "UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='system' AND element IN ('cache','engagecache')"
rm -rf "$CACHE_PAGE"

# 5. Limpieza: el modulo se despublica (su aviso emergente estorbaria a las demas pruebas) y el plugin vuelve a los ajustes por defecto
$MQ "UPDATE jos_modules SET published=0 WHERE id=$MID"
mysql -uroot "$DB_NAME" -e "UPDATE jos_extensions SET params='{\"mode\":\"ask\"}' WHERE type='plugin' AND folder='engage' AND element='gravatar'"
echo "Modulo JBCookies despublicado (sigue instalado en el sitio de pruebas). Regresion del modo engage: bash 05-gravatar.sh"
exit $rc
