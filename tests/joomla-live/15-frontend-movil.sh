#!/usr/bin/env bash
# 0.6.27: aviso de Gravatar (texto dentro de la caja redondeada, 390/1280 px, 4 temas) e iconos del nombre del comentarista (estrella, usuario,
# invitado y nombre de usuario visibles en 360/390/430/1280 px, 4 temas, con y sin respuestas, con y sin Font Awesome) en Chromium real.
# ANTES=1 solo informa. Requiere 03-sembrar-y-probar.sh, el paquete instalado (02) y el esqueleto es-ES de 08-idioma-es.sh. Variables: PW_MODULE, CHROMIUM, OUT, ANTES.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
export OUT="${OUT:-$WORK/frontend-movil-capturas}"
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/frontend-movil.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la prueba"; exit 2
fi
