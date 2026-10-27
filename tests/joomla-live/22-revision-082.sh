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
#   S      lib/opciones-sensibles.php (0.9.1) core.options sin core.admin no cambia email_base_url ni el resto de opciones sensibles (panel moderno y com_config); auditoria; vista previa solo con core.admin
#   N      lib/listas-navegador.js   (0.9.2) cada formulario de lista del backend en Chromium (la tarea vacia y «browse» no pueden volver a dar 403)
#   N2     lib/admin-navegador.js    (0.9.3) rediseno .eg-admin de Comentarios, Plantillas de email y Edicion en Chromium: claro/oscuro x escritorio/movil, sin desbordamiento, sin estilos en linea, contraste, capturas en docs/img
#   R      lib/rastreo-http.php, lib/rastreo-navegador.js (0.9.4/0.9.5, con nginx + php-fpm: lib/nginx-fpm.sh) rastreo de robots con SEF y sin SEF, con y sin live_site / opcion / base aprendida: URL base indexable con su canonical; variantes akengage_* con noindex, follow (meta y X-Robots-Tag), canonical a la base y rel nofollow en todos los enlaces; modulo; opcion apagada; clic de personas; semaforo del panel
#   AV     lib/avatar-generico-navegador.js, lib/avatar-opciones.php (0.9.11) opcion «Avatar generico en los comentarios sin foto»: Gravatar preguntar/desactivado/siempre x Mostrar/No mostrar, escritorio claro y movil oscuro (requiere lib/importados-datos.php crear)
#   PA     lib/panel-acceso-navegador.js (0.9.11/0.9.12) semaforo del panel «Plugins de fotos y acceso»: verde, ambar con plugin y nivel, enlace de arreglo, email/akismet sin aviso
#   AU     lib/auditoria-0912.php (0.9.12) DoS de BBCode, tope de longitud, baja de avisos (token de formulario no basta; enlace firmado y usuario con su correo), enlaces firmados de moderacion, re-suscripcion y limitador de comment.save
#   PR     lib/privacidad-0913.php (0.9.13) retencion de IP y navegador (1200 comentarios por lotes), informacion y enlace de privacidad (valido y hostil), Gravatar decidido por el servidor (invitado / registrado / consentimiento / revocar) y adopcion de comentarios con correo verificado por activacion (registro y activacion reales con el SMTP de captura)
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
echo "== opciones sensibles (0.9.1): core.options sin core.admin"
php "$AQUI/lib/opciones-sensibles.php" || rc=1
php "$AQUI/lib/auditoria-0912.php" || rc=1
php "$AQUI/lib/privacidad-0913.php" || rc=1
PW="${PW_MODULE:-/opt/node-tools/node_modules/playwright}"
if [ -d "$PW" ] && [ -x "${CHROMIUM:-/opt/pw-browsers/chromium}" ]; then
	export PW_MODULE="$PW" CHROMIUM="${CHROMIUM:-/opt/pw-browsers/chromium}"
	echo "== Navegador (Chromium): H3 y H9"
	node "$AQUI/lib/revision-082-navegador.js" || rc=1
	echo "== Navegador (Chromium): formularios de lista del backend (filtros, busqueda, orden, paginacion, limpiar, lote) - 0.9.2"
	php "$AQUI/lib/filtro-datos.php" crear >/dev/null && node "$AQUI/lib/listas-navegador.js" || rc=1
	echo "== Navegador (Chromium): rediseno de Comentarios, Plantillas de email y Edicion, claro/oscuro x escritorio/movil (capturas en docs/img) - 0.9.3"
	node "$AQUI/lib/admin-navegador.js" || rc=1
	echo "== Rastreo de robots (0.9.4): URL base indexable, variantes con noindex/canonical/nofollow (HTTP) y las personas siguen pudiendo usarlo todo (Chromium)"
	# 0.9.5: nginx + php-fpm (php -S hace que Joomla se crea en CLI y sin live_site no resuelve las rutas amigables): SEF con reescritura, con y sin live_site
	bash "$AQUI/lib/nginx-fpm.sh" arrancar >/dev/null || rc=2
	php "$AQUI/lib/rastreo-http.php" || rc=1
	node "$AQUI/lib/rastreo-navegador.js" || rc=1
	bash "$AQUI/lib/nginx-fpm.sh" parar >/dev/null 2>&1 || true
	php "$AQUI/lib/filtro-datos.php" borrar >/dev/null
	echo "== Navegador (Chromium): correos HTML en claro y oscuro, escritorio y movil (capturas en docs/img)"
	node "$AQUI/lib/correos-navegador.js" || rc=1
else
	echo "NO PROBADO: falta Playwright o Chromium (PW_MODULE / CHROMIUM); no se ejecuto la parte del navegador"; rc=2
fi
exit $rc
