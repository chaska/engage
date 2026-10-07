#!/usr/bin/env bash
# 0.7.0: reacciones a los comentarios (me gusta, no me gusta, favorito) contra el Joomla REAL: HTTP (curl, invitado / Registered / Manager: CSRF, permisos,
# inyeccion, limite de frecuencia, opciones, limpieza), CLI (privacidad RGPD y borrado de usuarios con los modelos y plugins reales) y Chromium
# (4 temas x pagina clara/oscura x 390/768/1280, teclado, lector, persistencia, sin JS, cache de pagina, opciones).
# Requisitos: 03-sembrar-y-probar.sh ($WORK/ids.json) y el paquete 3.4.3 instalado (02). Variables: PW_MODULE, CHROMIUM, OUT, CAPTURAS=1, VERBOSO=1.
# Sale con 1 si algo FALLA y con 2 si no se pudo ejecutar el navegador (NO PROBADO).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL DB_USER
export OUT="${OUT:-$WORK/reacciones-capturas}"
rc=0
echo "== HTTP"; php "$AQUI/lib/reacciones-http.php" | tail -n "${LINEAS:-4}" || rc=1
echo "== RGPD y borrado de usuarios (CLI de Joomla)"; php "$AQUI/lib/reacciones-cli.php" | tail -n "${LINEAS:-4}" || rc=1
PW="${PW_MODULE:-/opt/node-tools/node_modules/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; echo "== Navegador"; node "$AQUI/lib/reacciones-navegador.js" || rc=1
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la parte del navegador"; rc=2
fi
exit $rc
