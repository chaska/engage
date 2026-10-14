#!/usr/bin/env bash
# 0.8.3: los hallazgos de la revision independiente de la 0.8.2 contra el Joomla REAL (PoC por HTTP como invitado, Registered ajeno, Registered autor, Manager,
# Administrator y Super User; Chromium para Gravatar y BBCode; con y sin cache de pagina donde importa):
#   H1/H5  lib/revision-082-h1.php   lectura de comentarios por ID y tareas desconocidas (matriz, barrido de tareas, flujo legitimo, panel); task=comments.display = 403
#   H2     lib/revision-082-h2.php   Host hostil en los correos (4 configuraciones de live_site / force_ssl)
#   H4     lib/revision-082-h4.php   Gravatar: HTML sin hash del correo, endpoint avatar.token / avatar.resolve (CSRF, ACL, limite), modos always y off
#   H3/H9  lib/revision-082-navegador.js   BBCode javascript: y estilos del purificador en Chromium (3 filter_mode x invitado y Super User)
#   H8     lib/revision-082-h8.php   ACL de updateEmails / resetEmails
#   H10    lib/revision-082-h10.php  opcion «Incluir enlaces en los correos» (email_include_links): ACL y validacion del guardado, correos capturados a moderador y a suscriptor con 1 y con 0, plantilla por defecto y personalizada, sin live_site
#   C      lib/correos-html.php      (0.9.0) correos con diseño HTML enriquecido y aviso de confidencialidad: 4 combinaciones de opciones x es/en x moderador/suscriptor, plantilla por defecto y personalizada hostil, vista previa y correo de prueba; lib/correos-navegador.js los renderiza en Chromium
#   H6/H7  no tienen script propio: se comprueban con tests/41 (ficha del activo) y con 21-otros-componentes.sh (terceros con activos en #__assets); ejecute 21 aparte.
# Ademas de este script hay que pasar: 05 (Gravatar con cache de pagina y Chromium), 03, 17, 19, 20, 21 y 04 (actualizacion).
# Requisitos: 03-sembrar-y-probar.sh y el paquete actual instalado (02); MariaDB, SMTP de captura (00). Variables: PW_MODULE, CHROMIUM.
# Sale con 1 si algo FALLA y con 2 si no se pudo ejecutar el navegador (NO PROBADO). Quita los usuarios y comentarios de prueba al acabar.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL DB_USER
rc=0
for s in h1 h2 h4 h8 h10 h11; do
	echo "== $s"
	php "$AQUI/lib/revision-082-$s.php" || rc=1
done
echo "== correos HTML (0.9.0)"
php "$AQUI/lib/correos-html.php" || rc=1
PW="${PW_MODULE:-/opt/node-tools/node_modules/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW" CHROMIUM="${CHROMIUM:-/opt/pw-browsers/chromium}"
	echo "== Navegador (Chromium): H3 y H9"
	node "$AQUI/lib/revision-082-navegador.js" || rc=1
	echo "== Navegador (Chromium): correos HTML en claro y oscuro, escritorio y movil (capturas en docs/img)"
	node "$AQUI/lib/correos-navegador.js" || rc=1
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la parte del navegador"; rc=2
fi
exit $rc
