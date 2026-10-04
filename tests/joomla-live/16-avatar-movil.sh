#!/usr/bin/env bash
# 0.6.28: opcion «Avatar en movil» (mobile_avatar: auto / show / hide) en Chromium real, 4 temas x 3 valores x anchos 320/360/390/430/575/576/768/1280.
# FASE=antes (con la 0.6.27 instalada) guarda la referencia de classic y cuenta los avatares visibles en movil; FASE=despues (por defecto) hace la matriz completa
# y deja la captura en $OUT (avatar-movil.png). Requiere 03-sembrar-y-probar.sh, el paquete instalado (02) y el esqueleto es-ES de 08-idioma-es.sh.
# Variables: PW_MODULE, CHROMIUM, OUT, FASE, VERBOSO=1 (muestra tambien lo que pasa).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
export OUT="${OUT:-$WORK/avatar-movil-capturas}"
export FASE="${FASE:-despues}"
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/avatar-movil.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la prueba"; exit 2
fi
