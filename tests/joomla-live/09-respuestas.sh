#!/usr/bin/env bash
# 0.6.21: posicion, sangria y cita de las respuestas. Requiere 03-sembrar-y-probar.sh ya ejecutado.
#  1) HTTP (lib/respuestas-http.php): matriz comments_ordering x max_level sobre el HTML servido, enviando por el flujo del formulario.
#     Con --mostrar solo registra el orden (sirve para ver el comportamiento ANTES del arreglo).
#  2) Navegador (lib/respuestas-navegador.js): Chromium headless mide la sangria (px) y el estilo de cada opcion y hace el flujo Responder -> enviar.
#     Variables: PW_MODULE (ruta de playwright), CHROMIUM (por defecto /opt/pw-browsers/chromium). Sin ellos: NO PROBADO (sale 2).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
php "$AQUI/lib/respuestas-http.php" "$@"; rc=$?
[ "${1:-}" = "--mostrar" ] && exit $rc
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/respuestas-navegador.js"; rc2=$?
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la parte de navegador"; rc2=2
fi
[ $rc -ne 0 ] && exit $rc
exit $rc2
