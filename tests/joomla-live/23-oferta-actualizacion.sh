#!/usr/bin/env bash
# 0.9.11: la OFERTA de actualizacion desde el servidor de actualizaciones, en Joomla REAL (Sistema > Actualizar > Extensiones): instala una version ANTERIOR del paquete,
# comprueba la fila de #__update_sites (la URL de <updateservers>), sirve en local una copia de updates/pkgengage.xml (descarga local) y comprueba que
#   1) la busqueda de actualizaciones guarda la oferta en #__updates con el client_id del paquete (0) y su extension_id (sin <client> Joomla usa 1 y dice «No hay actualizaciones»);
#   2) la pantalla de Joomla la muestra (Chromium); 3) con una huella (sha256) mal puesta la actualizacion se RECHAZA y la version no cambia;
#   4) con la huella buena se descarga, se comprueba y se instala: la version pasa a la nueva; no queda ningun registro raro.
# Uso: PKG_ANTES=ruta/pkg_engage-anterior.zip [XML_FUENTE=ruta/updates.xml] ./23-oferta-actualizacion.sh   (el ZIP nuevo es dist/pkg_engage-*.zip de mayor version)
set -uo pipefail
source "$(dirname "$0")/config.sh"
: "${PKG_ANTES:?define PKG_ANTES con un ZIP anterior del paquete}"; XML="${XML_FUENTE:-$REPO/updates/pkgengage.xml}"
NUEVO="$(ls "$REPO"/dist/pkg_engage-*.zip | sort -V | tail -1)"; U="$WORK/upd"; PU=8099; M="mysql -N $DB_NAME -e"; rc=0; n=0
ok() { n=$((n+1)); if [ "$2" = 1 ]; then echo "O-$n PASA  $1"; else echo "O-$n FALLA $1  [$3]"; rc=1; fi; }
export WORK BASE_URL
sirve() { pkill -f "php -S 127.0.0.1:$PU" 2>/dev/null; rm -rf "$U"; mkdir -p "$U"; cp "$NUEVO" "$U/"; python3 - "$XML" "$U/pkgengage.xml" "$(basename "$NUEVO")" "$PU" "$1" <<'P'
import re,sys
x=open(sys.argv[1]).read()
x=re.sub(r'<downloadurl([^>]*)>[^<]*</downloadurl>',lambda m:'<downloadurl%s>http://127.0.0.1:%s/%s</downloadurl>'%(m.group(1),sys.argv[4],sys.argv[3]),x)
if sys.argv[5]!='-': x=re.sub(r'<sha256>[^<]*</sha256>','<sha256>%s</sha256>'%sys.argv[5],x)
open(sys.argv[2],'w').write(x)
P
	(cd "$U" && setsid nohup php -S 127.0.0.1:$PU -t "$U" >/dev/null 2>&1 &); sleep 1; }
instala_antes() { bash "$AQUI/02-instalar-engage.sh" "$PKG_ANTES" >/dev/null 2>&1; bash "$AQUI/lib/servidor.sh"; }
version() { $M "SELECT JSON_VALUE(manifest_cache,'\$.version') FROM jos_extensions WHERE element='pkg_engage' AND type='package'"; }
busca() { $M "DELETE FROM jos_updates WHERE element='pkg_engage'; UPDATE jos_update_sites SET last_check_timestamp=0 WHERE name LIKE 'Engage (fork%'"; (cd "$WORK/$SITE_DIR" && php cli/joomla.php update:extensions:check >/dev/null 2>&1); }
VNUEVO="$(unzip -p "$NUEVO" pkg_engage.xml | grep -o '<version>[^<]*' | head -1 | sed 's/<version>//')"
instala_antes; VANTES="$(version)"
URL_SRC="$(grep -o 'https://[^<]*pkgengage.xml' "$REPO/src/package/pkg_engage.xml" | head -1)"
FILAS="$($M "SELECT COUNT(*) FROM jos_update_sites us JOIN jos_update_sites_extensions x USING(update_site_id) JOIN jos_extensions e USING(extension_id) WHERE e.element='pkg_engage' AND e.type='package'")"
LOC="$($M "SELECT us.location FROM jos_update_sites us JOIN jos_update_sites_extensions x USING(update_site_id) JOIN jos_extensions e USING(extension_id) WHERE e.element='pkg_engage' AND e.type='package' LIMIT 1")"
ok "al instalar la version $VANTES se crea UNA fila de update site del paquete con la URL de <updateservers> ($URL_SRC)" "$([ "$FILAS" = 1 ] && [ "$LOC" = "$URL_SRC" ] && echo 1 || echo 0)" "filas=$FILAS loc=$LOC"
SHA_ZIP="$(sha256sum "$NUEVO" | cut -d' ' -f1)"; SHA_XML="$(grep -o '<sha256>[0-9a-f]\{64\}' "$XML" | sed 's/<sha256>//')"
ok "el sha256 del updates/pkgengage.xml es el del ZIP nuevo ($(basename "$NUEVO"))" "$([ "$SHA_ZIP" = "$SHA_XML" ] && echo 1 || echo 0)" "zip=$SHA_ZIP xml=$SHA_XML"
LOC0="$LOC"; trap '$M "UPDATE jos_update_sites SET location='"'"'$LOC0'"'"' WHERE name LIKE '"'"'Engage (fork%'"'"'"; pkill -f "php -S 127.0.0.1:'$PU'" 2>/dev/null' EXIT
# 1) huella MALA: se rechaza
sirve "$(printf '0%.0s' $(seq 1 64))"; $M "UPDATE jos_update_sites SET location='http://127.0.0.1:$PU/pkgengage.xml' WHERE name LIKE 'Engage (fork%'"
busca
OF="$($M "SELECT CONCAT(version,'|',client_id,'|',extension_id) FROM jos_updates WHERE element='pkg_engage'")"; EID="$($M "SELECT extension_id FROM jos_extensions WHERE element='pkg_engage' AND type='package'")"
ok "la busqueda de actualizaciones guarda la oferta $VNUEVO con client_id 0 y el extension_id del paquete" "$([ "$OF" = "$VNUEVO|0|$EID" ] && echo 1 || echo 0)" "oferta=$OF esperada=$VNUEVO|0|$EID"
R1="$(node "$AQUI/lib/actualizar-navegador.js" actualiza 2>&1)"
ok "la pantalla de Joomla muestra Engage y, con una huella MALA, la actualizacion se rechaza" "$(echo "$R1" | grep -q '"filas":1' && ! echo "$R1" | grep -qi 'successful' && echo 1 || echo 0)" "$R1"
ok "con la huella mala la version instalada no cambia ($VANTES)" "$([ "$(version)" = "$VANTES" ] && echo 1 || echo 0)" "version=$(version)"
# 2) huella BUENA: se actualiza
sirve -; busca
R2="$(node "$AQUI/lib/actualizar-navegador.js" ofrece 2>&1)"
ok "con la huella buena la pantalla ofrece «Engage» $VNUEVO (Sistema > Actualizar > Extensiones)" "$(echo "$R2" | grep -q '"filas":1' && echo "$R2" | grep -q "$VNUEVO" && echo 1 || echo 0)" "$R2"
R3="$(node "$AQUI/lib/actualizar-navegador.js" actualiza 2>&1)"
ok "descarga + comprobacion de la huella + instalacion: Joomla dice que se actualizo" "$(echo "$R3" | grep -qi 'successful' && echo 1 || echo 0)" "$R3"
ok "la version instalada del paquete pasa de $VANTES a $VNUEVO" "$([ "$(version)" = "$VNUEVO" ] && echo 1 || echo 0)" "version=$(version)"
ok "no queda ninguna oferta pendiente ni un update site duplicado" "$([ "$($M "SELECT COUNT(*) FROM jos_updates WHERE element='pkg_engage'")" = 0 ] && [ "$($M "SELECT COUNT(*) FROM jos_update_sites WHERE name LIKE 'Engage (fork%'")" = 1 ] && echo 1 || echo 0)" ""
ok "las extensiones del paquete quedan en la version nueva" "$([ "$($M "SELECT COUNT(*) FROM jos_extensions WHERE (element LIKE '%engage%') AND JSON_VALUE(manifest_cache,'\$.version')<>'$VNUEVO' AND type<>'file' AND element NOT LIKE 'pkg_%'")" = 0 ] && echo 1 || echo 0)" "$($M "SELECT GROUP_CONCAT(element,'=',JSON_VALUE(manifest_cache,'\$.version')) FROM jos_extensions WHERE element LIKE '%engage%' AND JSON_VALUE(manifest_cache,'\$.version')<>'$VNUEVO'")"
echo "RESUMEN oferta-actualizacion: $n comprobaciones, rc=$rc"; exit $rc
