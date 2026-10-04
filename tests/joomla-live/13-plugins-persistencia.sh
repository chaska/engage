#!/usr/bin/env bash
# 0.6.26: persistencia de CADA ajuste de plugin (Gravatar, Akismet, email) cambiado por la pantalla de Opciones modernas: BD, ficha clasica del plugin,
# recarga y, para la imagen por defecto de Gravatar, la URL final del HTML servido. Requiere 03-sembrar-y-probar.sh, el paquete (02) y el esqueleto es-ES (08).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/plugins-persistencia.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM)"; exit 2
fi
