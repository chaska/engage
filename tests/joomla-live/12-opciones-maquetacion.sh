#!/usr/bin/env bash
# 0.6.26: maquetacion de las filas de la pantalla de Opciones en un navegador real (Chromium): para CADA fila de CADA categoria, a 1920, 1280,
# 768 y 390 px x 3 disenos (Claro/Medio/Oscuro) en es-ES: sin solapes, columna de texto >= 12 rem (o apilada a todo el ancho), <= 12 lineas,
# sin desbordes horizontales, segmentos alcanzables con Tab/flechas y contraste. ANTES=1 solo informa (para reproducir el fallo de la 0.6.25).
# Requiere 03-sembrar-y-probar.sh y el paquete instalado (02) y el esqueleto es-ES de 08-idioma-es.sh. Variables: PW_MODULE, CHROMIUM, OUT, ANTES.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
export OUT="${OUT:-$WORK/maquetacion-capturas}"
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/maquetacion-navegador.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la prueba de maquetacion"; exit 2
fi
