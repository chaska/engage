#!/usr/bin/env bash
# 0.6.27: controles segmentados de la pantalla de Opciones en un navegador real (Chromium): los de etiquetas cortas (p. ej. «Nivel maximo de
# anidacion», 1 a 6) en UNA sola fila y con segmentos de igual anchura, los largos (Modo de Gravatar, Clasificacion) sin solapes, y ninguna ayuda
# recortada sin forma de verla entera. 1920/1280/768/390 px x 3 disenos en es-ES. ANTES=1 solo informa (reproduce el fallo de la 0.6.26).
# Requiere 03-sembrar-y-probar.sh, el paquete instalado (02) y el esqueleto es-ES de 08-idioma-es.sh. Variables: PW_MODULE, CHROMIUM, OUT, ANTES.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
export OUT="${OUT:-$WORK/segmentados-capturas}"
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/segmentados-navegador.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la prueba de segmentados"; exit 2
fi
