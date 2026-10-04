#!/usr/bin/env bash
# 0.6.25: pantalla de opciones modernas en un navegador real: 3 disenos x escritorio/movil x 8 categorias con contraste WCAG medido, teclado,
# guardado instantaneo comprobado en la BD y en el frontend, errores con la red interceptada (el control vuelve al valor anterior),
# seguridad por HTTP (token, ambitos, claves, valores, tipos raros, XSS), Manager sin core.admin, admin sin permiso de plugins y registro
# de acciones. Requiere 03-sembrar-y-probar.sh y el paquete instalado (02). Variables: PW_MODULE, CHROMIUM, OUT. Sin Playwright: NO PROBADO (2).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
export OUT="${OUT:-$WORK/ajustes-capturas}"
PW="${PW_MODULE:-$(npm root -g 2>/dev/null)/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; node "$AQUI/lib/ajustes-navegador.js"
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la prueba de opciones"; exit 2
fi
