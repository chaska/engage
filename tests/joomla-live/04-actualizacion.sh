#!/usr/bin/env bash
# Prueba de ACTUALIZACION en sitio: instala el 3.4.2 original (reconstruido desde upstream/3.4.2-instalado), crea datos
# reales y ajustes, instala encima el paquete del fork y compara tablas, ajustes, extensiones, ficheros y update site.
# Usa un segundo Joomla (SITE_DIR=site2, DB joomla_upg, puerto 8081). Requisitos: 00-entorno.sh y 01-montar-joomla.sh con esas variables.
# 0.7.0: PKG_ANTES=/ruta/pkg_engage-3.4.2.1.zip instala ese paquete (p. ej. el PUBLICADO, descargado de la release v3.4.2.1) en lugar del 3.4.2 original
# y actualiza desde el. Sin la variable se parte del 3.4.2 original reconstruido desde upstream/. En ambos casos, tras actualizar se comprueba que la
# tabla de reacciones se crea, que el esquema pasa a 3.4.3-20261007 y que los comentarios y ajustes previos quedan intactos.
set -euo pipefail
export SITE_DIR="${SITE_DIR:-site2}" DB_NAME="${DB_NAME:-joomla_upg}" PORT="${PORT:-8081}" IDS_FILE="${IDS_FILE:-ids2.json}"
source "$(dirname "$0")/config.sh"
OUT="$WORK/upgrade"; mkdir -p "$OUT"
php "$REPO/build/build.php" >/dev/null
if [ -n "${PKG_ANTES:-}" ]; then
	echo "== 1. Paquete de partida: $PKG_ANTES (sha256 $(sha256sum "$PKG_ANTES" | cut -c1-16)...)"
	ANTES_ZIP="$PKG_ANTES"
else
	echo "== 1. Paquete del 3.4.2 original (reconstruido desde upstream/3.4.2-instalado, upstream/ no se modifica)"
	php "$AQUI/lib/empaquetar-upstream.php" "$REPO/upstream/3.4.2-instalado" "$WORK/upstream-pkg" "$REPO/build/build.php" | tail -2
	ANTES_ZIP="$WORK/upstream-pkg/dist/pkg_engage-3.4.2.zip"
fi
echo "== 2. Instalacion del paquete de partida"
# Empieza SIEMPRE de un sitio sin Engage (Joomla no baja el esquema de #__schemas: si una pasada anterior dejo 3.4.3-20261007 la actualizacion no tendria nada que ejecutar)
WORK="$WORK" SITE_DIR="$SITE_DIR" DB_NAME="$DB_NAME" php "$AQUI/lib/desinstalar.php" > "$OUT/desinstalacion-previa.txt" 2>&1 || true
mysql "$DB_NAME" -e "DROP TABLE IF EXISTS jos_engage_reactions" 2>/dev/null || true
bash "$AQUI/02-instalar-engage.sh" "$ANTES_ZIP" > "$OUT/instalacion-antes.txt" 2>&1; head -3 "$OUT/instalacion-antes.txt"
echo "   tabla de reacciones ANTES de actualizar: $(mysql -N "$DB_NAME" -e "SHOW TABLES LIKE 'jos_engage_reactions'" | wc -l) (0 = no existe, como debe ser)"
echo "   esquema ANTES: $(mysql -N "$DB_NAME" -e "SELECT version_id FROM jos_schemas WHERE extension_id=(SELECT extension_id FROM jos_extensions WHERE element='com_engage' AND type='component')")"
echo "== 3. Datos reales y ajustes"
mysql "$DB_NAME" -e "DELETE FROM jos_engage_comments" 2>/dev/null || true   # reejecutable: sin comentarios de pasadas anteriores
JOOMLA_SITE="$WORK/$SITE_DIR" BASE_URL="$BASE_URL" php "$AQUI/lib/seed.php" > "$WORK/$IDS_FILE"
WORK="$WORK" IDS_FILE="$IDS_FILE" DB_NAME="$DB_NAME" BASE_URL="$BASE_URL" php "$AQUI/lib/datos-reales.php"
snap() { # $1 = prefijo del fichero
	mysqldump --skip-comments --skip-extended-insert --order-by-primary "$DB_NAME" jos_engage_comments jos_engage_unsubscribe > "$OUT/$1-tablas.sql"
	mysql -N "$DB_NAME" -e "SELECT element,folder,type,enabled,access,ordering,params FROM jos_extensions WHERE element LIKE '%engage%' OR folder='engage' ORDER BY type,folder,element" > "$OUT/$1-extensiones.txt"
	mysql -N "$DB_NAME" -e "SELECT name,rules FROM jos_assets WHERE name='com_engage'" > "$OUT/$1-reglas.txt"
	mysql -N "$DB_NAME" -e "SELECT id,title,alias,link,parent_id,menutype,client_id,component_id,published,level,path,img FROM jos_menu WHERE client_id=1 AND (link LIKE '%com_engage%' OR component_id=(SELECT extension_id FROM jos_extensions WHERE element='com_engage' AND type='component')) ORDER BY lft" > "$OUT/$1-menu.txt"
	mysql -N "$DB_NAME" -e "SELECT template_id,extension,language,subject FROM jos_mail_templates WHERE template_id LIKE 'com_engage%' ORDER BY 1,3" > "$OUT/$1-plantillas-correo.txt"
	mysql -t "$DB_NAME" -e "SELECT update_site_id,name,location,enabled FROM jos_update_sites WHERE name LIKE '%ngage%'; SELECT us.update_site_id, e.element FROM jos_update_sites_extensions us JOIN jos_extensions e USING (extension_id) WHERE e.element LIKE '%engage%'; SELECT * FROM jos_schemas WHERE extension_id=(SELECT extension_id FROM jos_extensions WHERE element='com_engage' AND type='component'); SELECT type,element,folder,JSON_VALUE(manifest_cache,'\$.version') AS version FROM jos_extensions WHERE element LIKE '%engage%' OR folder='engage'" > "$OUT/$1-estado.txt"
	(cd "$WORK/$SITE_DIR" && find components/com_engage administrator/components/com_engage media/com_engage modules/mod_engage_latest plugins/*/engage plugins/engage plugins/system/engagecache -type f 2>/dev/null | sort | xargs sha256sum) > "$OUT/$1-ficheros.txt"
}
snap antes
echo "   comentarios antes: $(mysql -N "$DB_NAME" -e 'SELECT COUNT(*) FROM jos_engage_comments')"
echo "== 4. Actualizacion encima con el paquete del fork"
bash "$AQUI/02-instalar-engage.sh" > "$OUT/instalacion-fork.txt" 2>&1; head -3 "$OUT/instalacion-fork.txt"
snap despues
echo "== 5. Comparacion"
cmp -s "$OUT/antes-tablas.sql" "$OUT/despues-tablas.sql" && echo "TABLAS engage_*: identicas (volcado completo)" || { echo "TABLAS engage_*: DIFERENCIAS"; diff "$OUT/antes-tablas.sql" "$OUT/despues-tablas.sql" | head -20; }
cmp -s "$OUT/antes-reglas.txt" "$OUT/despues-reglas.txt" && echo "PERMISOS (asset com_engage): identicos" || { echo "PERMISOS: DIFERENCIAS"; diff "$OUT/antes-reglas.txt" "$OUT/despues-reglas.txt"; }
cmp -s "$OUT/antes-plantillas-correo.txt" "$OUT/despues-plantillas-correo.txt" && echo "PLANTILLAS de correo: identicas" || { echo "PLANTILLAS de correo: DIFERENCIAS"; diff "$OUT/antes-plantillas-correo.txt" "$OUT/despues-plantillas-correo.txt" | head; }
echo "-- menu de administracion (#__menu) antes vs despues (0.6.24: el elemento 'Akeeba Engage' debe seguir igual y abrir el panel):"; echo "   antes:"; sed 's/^/     /' "$OUT/antes-menu.txt"; echo "   despues:"; sed 's/^/     /' "$OUT/despues-menu.txt"
echo "-- extensiones (enabled/params) antes vs despues:"; diff "$OUT/antes-extensiones.txt" "$OUT/despues-extensiones.txt" && echo "(sin diferencias)"
echo "-- estado (update site, schemas, versiones) antes vs despues:"; diff "$OUT/antes-estado.txt" "$OUT/despues-estado.txt" || true
echo "-- ficheros: sobrantes del 3.4.2 que ya no estan en el fork:"
comm -23 <(awk '{print $2}' "$OUT/antes-ficheros.txt") <(awk '{print $2}' "$OUT/despues-ficheros.txt") | sed 's/^/   /' | head -20
echo "-- ficheros nuevos del fork:"; comm -13 <(awk '{print $2}' "$OUT/antes-ficheros.txt") <(awk '{print $2}' "$OUT/despues-ficheros.txt") | sed 's/^/   /' | head -20
echo "-- ficheros modificados: $(comm -13 <(sort "$OUT/antes-ficheros.txt") <(sort "$OUT/despues-ficheros.txt") | awk '{print $2}' | sort -u | wc -l) (incluye los nuevos)"
echo "== 5b. Reacciones (0.7.0): tabla nueva, esquema y datos previos"
N="$(mysql -N "$DB_NAME" -e "SHOW TABLES LIKE 'jos_engage_reactions'" | wc -l)"; echo "   tabla jos_engage_reactions creada: $N (1 = si)"
SCH="$(mysql -N "$DB_NAME" -e "SELECT version_id FROM jos_schemas WHERE extension_id=(SELECT extension_id FROM jos_extensions WHERE element='com_engage' AND type='component')")"; echo "   esquema DESPUES: $SCH (debe ser 3.4.3-20261007)"
echo "   columnas: $(mysql -N "$DB_NAME" -e "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='$DB_NAME' AND table_name='jos_engage_reactions'")"
echo "   indices: $(mysql -N "$DB_NAME" -e "SELECT GROUP_CONCAT(DISTINCT index_name ORDER BY index_name) FROM information_schema.statistics WHERE table_schema='$DB_NAME' AND table_name='jos_engage_reactions'")"
echo "   comentarios despues: $(mysql -N "$DB_NAME" -e 'SELECT COUNT(*) FROM jos_engage_comments') (identicos a los de antes: lo dice la comparacion de tablas de arriba)"
[ "$N" = "1" ] && [ "$SCH" = "3.4.3-20261007" ] && echo "RESULTADO reacciones: PASA" || { echo "RESULTADO reacciones: FALLA"; exit 1; }
echo "== 6. Humo web tras actualizar"
ART="$(python3 -c "import json;d=json.load(open('$WORK/$IDS_FILE'));print('%s&catid=%s' % (d['art_publico'], d['cat_publica']))")"
for u in "/" "/administrator/" "/index.php?option=com_content&view=article&id=$ART"; do printf '%s -> HTTP %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL$u")"; done
DB_NAME="$DB_NAME" php "$AQUI/lib/humo-post-actualizacion.php" "$WORK" "$BASE_URL" "$IDS_FILE" | tee "$OUT/humo.txt"
echo "== 7. Registro de errores de PHP durante la actualizacion y el humo:"; wc -c < "$WORK/php-errors-${SITE_DIR}.log"
