#!/usr/bin/env bash
# 0.8.2: comentarios en el contenido de OTROS componentes (docs/COMENTARIOS-EN-OTROS-COMPONENTES.md) contra el Joomla REAL:
#   - instala tres plugins de PRUEBA que hacen de terceros (lib/tercero-prueba: el ejemplo bien hecho de la documentacion; lib/tercero-hostil: responde con
#     tipos raros, texto con HTML/JS y URLs de otro sitio; lib/tercero-vista: hace de vista de un componente de terceros y dispara onContentAfterDisplay).
#     NO forman parte del paquete de Engage;
#   - HTTP (lib/otros-http.php): matriz de 14 elementos x invitado / Registered / Manager x 4 configuraciones de cache, 12 fichas hostiles, asset_id en bruto
#     invalidos, contexto falsificado, la peticion no decide el activo, vista inalcanzable por URL directa, vista `comments` ajena en la cache estatica de
#     Joomla, layout hostil (LFI), escritura, orden de visitas con cache de pagina y registro de PHP;
#   - borrado (lib/otros-borrado-cli.php): Meta::deleteAssetData() con MariaDB real;
#   - HTML del ARTICULO identico a la 0.8.1 (construye la 0.8.1 desde COMMIT_081 con `git archive`, la instala, guarda la referencia, reinstala la actual y compara);
#   - Chromium (lib/otros-navegador.js): sin dialogos ni nodos inyectados con texto hostil, con y sin cache de pagina, formulario operativo.
# Requisitos: 03-sembrar-y-probar.sh y el paquete actual instalado (02). Variables: PW_MODULE, CHROMIUM, COMMIT_081.
# Sale con 1 si algo FALLA y con 2 si no se pudo ejecutar el navegador (NO PROBADO). Al acabar quita los plugins, la tabla y los usuarios de prueba.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL DB_USER
rc=0
PREP="$(mktemp -d)"
instalar() { # $1 = carpeta del plugin, $2 = nombre del ZIP
	(cd "$AQUI/lib/$1" && zip -qr "$PREP/$2.zip" .) && (cd "$WORK/$SITE_DIR" && php cli/joomla.php extension:install --path="$PREP/$2.zip" 2>&1 | tail -1)
}
limpiar() {
	php "$AQUI/lib/xss-config.php" restore >/dev/null 2>&1
	php "$AQUI/lib/otros-datos.php" borrar >/dev/null 2>&1
	for e in terceroprueba tercerohostil; do
		mysql "$DB_NAME" -e "DELETE FROM jos_extensions WHERE type='plugin' AND folder='content' AND element='$e'" 2>/dev/null
		rm -rf "$WORK/$SITE_DIR/plugins/content/$e"
	done
	mysql "$DB_NAME" -e "DELETE FROM jos_extensions WHERE type='plugin' AND folder='system' AND element='tercerovista'" 2>/dev/null
	rm -rf "$WORK/$SITE_DIR/plugins/system/tercerovista"
	rm -rf "$WORK/$SITE_DIR/cache/"*/ "$WORK/$SITE_DIR/administrator/cache/"*/ 2>/dev/null
	rm -f "$WORK/$SITE_DIR/cache/autoload_psr4.php" "$WORK/$SITE_DIR/administrator/cache/autoload_psr4.php"
	rm -rf "$PREP"; bash "$AQUI/lib/servidor.sh"
}
trap limpiar EXIT
echo "== Plugins de prueba (terceros)"
instalar tercero-prueba terceroprueba; instalar tercero-hostil tercerohostil; instalar tercero-vista tercerovista
php "$AQUI/lib/otros-datos.php" crear || exit 1
rm -f "$WORK/$SITE_DIR/cache/autoload_psr4.php" "$WORK/$SITE_DIR/administrator/cache/autoload_psr4.php"; bash "$AQUI/lib/servidor.sh"
echo "== HTTP"; php "$AQUI/lib/otros-http.php" | tail -n "${LINEAS:-400}" || rc=1
echo "== Lista de comentarios del administrador (0.8.5)"; php "$AQUI/lib/otros-admin-lista.php" || rc=1
echo "== Borrado por activo (MariaDB real)"; php "$AQUI/lib/otros-borrado-cli.php" || rc=1
echo "== HTML del articulo identico a la 0.8.1"
COMMIT_081="${COMMIT_081:-5c9ca63}"
if command -v git >/dev/null && git -C "$REPO" cat-file -e "$COMMIT_081^{commit}" 2>/dev/null; then
	TMP81="$(mktemp -d)"; git -C "$REPO" archive "$COMMIT_081" | tar -x -C "$TMP81"
	if php "$TMP81/build/build.php" >/dev/null 2>&1 && ZIP81="$(ls "$TMP81"/dist/pkg_engage-*.zip | head -1)" && [ -f "$ZIP81" ]; then
		bash "$AQUI/02-instalar-engage.sh" "$ZIP81" >/dev/null 2>&1
		FASE=antes php "$AQUI/lib/otros-identico.php" | tail -n 2 || rc=1
		bash "$AQUI/02-instalar-engage.sh" >/dev/null 2>&1
		FASE=despues php "$AQUI/lib/otros-identico.php" | tail -n 12 || rc=1
	else echo "AVISO: no se pudo construir la 0.8.1 desde $COMMIT_081 (NO PROBADO)"; rc=1; fi
	rm -rf "$TMP81"
else echo "AVISO: falta git o el commit $COMMIT_081 (NO PROBADO)"; rc=1; fi
PW="${PW_MODULE:-/opt/node-tools/node_modules/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW" CHROMIUM="${CHROMIUM:-/opt/pw-browsers/chromium}"
	echo "== Navegador (Chromium)"; node "$AQUI/lib/otros-navegador.js" || rc=1
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la parte del navegador"; rc=2
fi
exit $rc
