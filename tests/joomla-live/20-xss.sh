#!/usr/bin/env bash
# 0.8.1: correcciones de la revision de seguridad independiente de la 0.8.0, contra el Joomla REAL:
#   - XSS reflejado y almacenado por la cache (el PoC exacto `x="><script>alert(document.domain)</script>` y 16 variantes: x, p, akengage_sort, akengage_fav,
#     limitstart, akengage_limitstart, akengage_limit, akengage_cid, cid, comillas dobles y simples, <svg onload>, javascript:, entidades, saltos de linea, //host)
#     en 10 configuraciones (sin cache / cache de paginas / cache conservadora, con y sin engagecache, con y sin live_site) por HTTP (lib/xss-http.php);
#   - envenenamiento por la cabecera Host y por la consulta del primer visitante; crecimiento de la cache; favoritos y reacciones con padre sin publicar/spam;
#     returnurl; aviso del semaforo con la cache global y sin engagecache (lib/xss-http.php);
#   - Chromium: ningun dialogo, ningun script inyectado, ningun nodo ajeno, enlaces de fecha y boton Copiar (lib/xss-navegador.js, con lib/xss-config.php).
# Requisitos: 03-sembrar-y-probar.sh y el paquete instalado (02). Variables: PW_MODULE, CHROMIUM. Restaura la configuracion al acabar.
# Sale con 1 si algo FALLA y con 2 si no se pudo ejecutar el navegador (NO PROBADO).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL DB_USER
rc=0
echo "== HTTP (XSS, Host, cache, favoritos, returnurl, semaforo)"; php "$AQUI/lib/xss-http.php" | tail -n "${LINEAS:-60}" || rc=1
PW="${PW_MODULE:-/opt/node-tools/node_modules/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW" CHROMIUM="${CHROMIUM:-/opt/pw-browsers/chromium}"
	echo "== Navegador (Chromium)"; node "$AQUI/lib/xss-navegador.js" || rc=1
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la parte del navegador"; rc=2
fi
exit $rc
