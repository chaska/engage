#!/usr/bin/env bash
# 0.6.24: panel de control en un navegador real (3 disenos x escritorio/movil, contraste WCAG medido, teclado, acciones rapidas con
# comprobacion en la BD, CSRF, usuario Manager sin core.admin, semaforos con la configuracion real, XSS, cero peticiones externas).
# Requiere 03-sembrar-y-probar.sh ya ejecutado y el paquete instalado (02-instalar-engage.sh). Variables: PW_MODULE, CHROMIUM, OUT, PANEL_LANG=es.
# Sin Playwright o Chromium: NO PROBADO (sale 2).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
export OUT="${OUT:-$WORK/panel-capturas}"
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/panel-navegador.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la prueba del panel"; exit 2
fi
