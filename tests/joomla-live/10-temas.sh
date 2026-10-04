#!/usr/bin/env bash
# 0.6.23: temas visuales (opcion theme) en un navegador real: hojas cargadas, contraste WCAG medido de todo el texto sobre pagina clara,
# oscura y plantillas "hostiles" (color forzado con !important), en escritorio (1100 px) y movil (400 px), botones con el raton encima,
# reply_style/reply_indent y "classic" sin cambios. Capturas PNG en $OUT.
# Requiere 03-sembrar-y-probar.sh ya ejecutado y el paquete instalado (02-instalar-engage.sh). Variables: PW_MODULE, CHROMIUM, OUT.
# Sin Playwright o Chromium: NO PROBADO (sale 2).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
export OUT="${OUT:-$WORK/temas-capturas}"
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/temas-navegador.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la prueba de temas"; exit 2
fi
