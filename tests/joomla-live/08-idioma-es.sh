#!/usr/bin/env bash
# 0.6.20: textos es-ES de Engage en un Joomla REAL sin el paquete oficial de idioma (la red externa puede estar bloqueada).
# Crea (si no existen) dos esqueletos de idioma es-ES (solo langmetadata.xml) en el Joomla de pruebas: asi el instalador copia
# los .ini propios de Engage a language/es-ES y administrator/language/es-ES. Pone es-ES como idioma del sitio y de la
# administracion, comprueba (1) con el analizador y la clase Language de Joomla que cada .ini instalado se carga entero y que
# cada cadena servida es la espanola, y (2) por HTTP las pantallas del panel, los formularios de plugins/modulo y el frontend.
# Al terminar restaura el idioma en-GB. Requiere 02 y 03 ya ejecutados e instalado el paquete actual. Sale con 1 si algo FALLA.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
S="$WORK/$SITE_DIR"
for c in "language:site" "administrator/language:administrator"; do
	d="${c%%:*}"; cl="${c##*:}"
	if [ ! -f "$S/$d/es-ES/langmetadata.xml" ]; then
		mkdir -p "$S/$d/es-ES"
		sed -e "s/client=\"site\"/client=\"$cl\"/" -e 's/English (en-GB)/Spanish (es-ES)/; s/English (United Kingdom)/Español (España)/g' \
			-e 's/en-GB site language/es-ES (esqueleto de prueba)/; s#<tag>en-GB</tag>#<tag>es-ES</tag>#; s/en_GB[^<]*/es_ES.utf8, es_ES.UTF-8, es_ES, es/' \
			"$S/language/en-GB/langmetadata.xml" > "$S/$d/es-ES/langmetadata.xml"
		echo "AVISO: esqueleto es-ES creado en $d; reinstala el paquete (02-instalar-engage.sh) para que se copien los .ini"
	fi
done
[ -f "$S/language/es-ES/com_engage.ini" ] || { echo "Faltan los .ini es-ES instalados: ejecuta 02-instalar-engage.sh con el esqueleto ya creado"; exit 1; }
PREV="$(mysql "$DB_NAME" -N -e "SELECT params FROM jos_extensions WHERE element='com_languages'")"
restaurar() { mysql "$DB_NAME" -e "UPDATE jos_extensions SET params='${PREV//\'/\'\'}' WHERE element='com_languages'"; rm -rf "$S/cache/"* "$S/administrator/cache/"* 2>/dev/null; }
trap restaurar EXIT
mysql "$DB_NAME" -e "UPDATE jos_extensions SET params='{\"administrator\":\"es-ES\",\"site\":\"es-ES\"}' WHERE element='com_languages'"
rm -rf "$S/cache/"* "$S/administrator/cache/"* 2>/dev/null
rc=0
php "$AQUI/lib/idioma-es-joomla.php" || rc=1
php "$AQUI/lib/idioma-es-http.php" || rc=1
exit $rc
