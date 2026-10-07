#!/usr/bin/env bash
# 0.8.0 (tanda 2): selector de orden, «solo mis favoritos», copiar enlace e insignias contra el Joomla REAL:
#   HTTP (curl; invitado / Registered / Manager / Editor / administrador): barra, orden esperado (calculo independiente) con comments_ordering x max_level,
#     paginacion, akengage_cid, valores de ataque, favoritos (privacidad), opciones y su validacion en el panel, insignias, boton Copiar con Host hostil,
#     cache de pagina y cache conservadora con engagecache;
#   Rendimiento: 5.000 comentarios y 20.000 reacciones, consulta con y sin «Mas valorados» y correccion del orden;
#   HTML IDENTICO a la 0.7.1 con las herramientas apagadas (construye la 0.7.1 desde el commit COMMIT_071 con `git archive`, la instala, guarda la referencia,
#     reinstala la version actual y compara byte a byte; si no hay git o el commit, se salta con aviso);
#   Navegador (Chromium): 5 variantes de tema x pagina clara/oscura x 390/768/1280, contraste, solapes, teclado, portapapeles real, aviso, insignias, cache.
# Requisitos: 03-sembrar-y-probar.sh y el paquete actual instalado (02). Variables: PW_MODULE, CHROMIUM, OUT, CAPTURAS=1 (imagenes de docs/img), COMMIT_071.
# Sale con 1 si algo FALLA y con 2 si no se pudo ejecutar el navegador (NO PROBADO). Contrasenas al azar en $WORK/tanda2pass.txt (no en el repo).
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL DB_USER
export OUT="${OUT:-$WORK/tanda2-capturas}"
rc=0
echo "== HTTP (invitado, Registered, Manager, Editor, administrador)"; php "$AQUI/lib/tanda2-http.php" | tail -n "${LINEAS:-6}" || rc=1
echo "== Rendimiento (5.000 comentarios, 20.000 reacciones)"; php "$AQUI/lib/tanda2-rendimiento.php" | tail -n 30 || rc=1
echo "== HTML identico a la 0.7.1 con las herramientas apagadas"
COMMIT_071="${COMMIT_071:-bb1913b}"
if command -v git >/dev/null && git -C "$REPO" cat-file -e "$COMMIT_071^{commit}" 2>/dev/null; then
	TMP71="$(mktemp -d)"; git -C "$REPO" archive "$COMMIT_071" | tar -x -C "$TMP71"
	if php "$TMP71/build/build.php" >/dev/null 2>&1 && [ -f "$TMP71/dist/pkg_engage-3.4.3.zip" ]; then
		bash "$AQUI/02-instalar-engage.sh" "$TMP71/dist/pkg_engage-3.4.3.zip" >/dev/null 2>&1
		FASE=antes php "$AQUI/lib/tanda2-identico.php" | tail -n 2 || rc=1
		bash "$AQUI/02-instalar-engage.sh" >/dev/null 2>&1
		FASE=despues php "$AQUI/lib/tanda2-identico.php" | tail -n 12 || rc=1
	else echo "AVISO: no se pudo construir la 0.7.1 desde $COMMIT_071 (NO PROBADO)"; rc=1; fi
	rm -rf "$TMP71"
else echo "AVISO: falta git o el commit $COMMIT_071 (NO PROBADO)"; rc=1; fi
PW="${PW_MODULE:-/opt/node-tools/node_modules/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW"; echo "== Navegador"; node "$AQUI/lib/tanda2-navegador.js" || rc=1
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la parte del navegador"; rc=2
fi
exit $rc
