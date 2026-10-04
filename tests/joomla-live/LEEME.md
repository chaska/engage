# Pruebas en un Joomla real (`tests/joomla-live/`)

Montan un Joomla 6 desechable en un contenedor, instalan el paquete del fork y ejecutan pruebas funcionales, de seguridad y de actualización por HTTP (sin navegador; solo `05-gravatar.sh` usa un Chromium headless). Resultados y análisis: [`docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md`](../../docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md).

**No se incluye ningún binario de Joomla ni ninguna contraseña.** Joomla se descarga del paquete oficial de GitHub Releases (`https://github.com/joomla/joomla-cms/releases/download/<versión>/Joomla_<versión>-Stable-Full_Package.zip`; la versión probada es 6.1.4, SHA-256 `817d2fa37c7f8a7ecbd1d028d6be9362dc6e5fd693f9e07e7fdb9998431e7196`). Las contraseñas de la BD, del administrador y de los usuarios de prueba se generan al azar y se guardan solo en `$WORK` (por defecto `/tmp/engage-joomla-live`, permisos 600), fuera del repositorio.

Requisitos: contenedor Debian/Ubuntu como root (los scripts instalan `mariadb-server` con `apt`), PHP 8.1+ con `mysqli`, `curl`, `zip`, `xml`, `mbstring`, `gd`, `intl`, Python 3 y salida HTTPS a github.com.

## Uso

```bash
cd tests/joomla-live
export WORK=/ruta/de/trabajo            # opcional
bash 00-entorno.sh                      # MariaDB, usuario/BD de pruebas, SMTP de captura (2525)
bash 01-montar-joomla.sh                # descarga e instala Joomla; sirve en http://127.0.0.1:8080
bash 02-instalar-engage.sh              # genera dist/ si falta e instala el paquete; vuelca extensiones, tablas, update site
bash 03-sembrar-y-probar.sh             # crea categorias/articulos con los modelos de Joomla y ejecuta pruebas.php (sale 1 si algo FALLA)
bash 05-gravatar.sh                     # (0.6.17) Gravatar con consentimiento: HTTP + Chromium/Playwright (sin red externa) + cache de pagina; necesita Playwright y /opt/pw-browsers/chromium (PW_MODULE, CHROMIUM)

bash 06-rel-ugc.sh                      # (0.6.18) rel="nofollow ugc noreferrer" en los enlaces de los comentarios (HTML servido)

# (0.6.19) Gravatar gobernado por JBCookies. El ZIP del modulo NO esta en el repo (licencia ambigua): se indica por variable
JBCOOKIES_ZIP=/ruta/JBCookies-6.0.2.zip bash 07-jbcookies.sh   # lo instala, lo publica en 'footer', pulsa sus botones reales en Chromium (NO PROBADO / sale 2 si falta el ZIP o el navegador)

bash 09-respuestas.sh                   # (0.6.21) posicion, sangria y cita de las respuestas: HTTP (matriz orden x max_level) + Chromium (mide px de cada opcion; PW_MODULE, CHROMIUM). --mostrar solo registra el orden

bash 10-temas.sh                        # (0.6.23) temas visuales: hojas cargadas, contraste WCAG de todo el texto sobre pagina clara/oscura/"hostil", escritorio y movil, modo claro/oscuro del dispositivo, hover de botones, capturas en $OUT (por defecto $WORK/temas-capturas); PW_MODULE, CHROMIUM

bash 12-ajustes.sh                      # (0.6.25) opciones modernas: 3 disenos x escritorio/movil x 8 categorias con contraste medido, teclado, guardado instantaneo comprobado en la BD y en el frontend, errores con la red interceptada, seguridad por HTTP, Manager sin core.admin y registro de acciones; capturas en $OUT (por defecto $WORK/ajustes-capturas)
bash 12-opciones-maquetacion.sh         # (0.6.26) maquetacion de CADA fila de CADA categoria de Opciones a 1920/1280/768/390 px x 3 disenos en es-ES: solapes, ancho de la columna de texto, lineas, desbordes, teclado y contraste (ANTES=1 solo informa; SOLO_MEDIDAS=1 se salta las flechas); capturas en $OUT
bash 13-plugins-persistencia.sh         # (0.6.26) cada ajuste de plugin (Gravatar, Akismet, email) cambiado por la interfaz moderna: BD, ficha clasica del plugin, recarga y URL de Gravatar con d=<imagen por defecto>
bash 14-opciones-segmentados.sh          # (0.6.27) controles segmentados de Opciones a 1920/1280/768/390 px x 3 disenos en es-ES: los cortos (p. ej. nivel maximo 1 a 6) en UNA fila con segmentos de igual anchura, los largos sin solapes, ayudas acortadas con forma de verlas enteras con el teclado, 0 solapes/desbordes y contraste (ANTES=1 solo informa); capturas antes/despues en $OUT (por defecto $WORK/segmentados-capturas)
bash 15-frontend-movil.sh                # (0.6.27) frontend en Chromium: aviso de Gravatar dentro de su caja redondeada (390/1280 px, 4 temas, antes y despues de aceptar) e iconos del nombre del comentarista visibles (360/390/430/1280 px, 4 temas, con y sin respuestas, con y sin Font Awesome); ANTES=1 solo informa
bash 11-panel.sh                        # (0.6.24) panel de control: 3 disenos x escritorio/movil, contraste WCAG medido, teclado, acciones rapidas con comprobacion en la BD, CSRF, usuario Manager sin core.admin, semaforos con la configuracion real, XSS y cero peticiones externas; capturas en $OUT (por defecto $WORK/panel-capturas); PW_MODULE, CHROMIUM
WORK=$WORK PW_MODULE=... node lib/capturas-docs.js   # regenera docs/img/panel-*.png (datos de ejemplo en espanol; PNG de paleta GD < 200 KB)

bash 08-idioma-es.sh                    # (0.6.20) textos es-ES con un esqueleto de idioma (sin descargar el paquete oficial): Language de Joomla + pantallas del panel, plugins, frontend y correo

# Prueba de actualizacion 3.4.2 -> paquete del fork, en un segundo Joomla (puerto 8081, BD joomla_upg)
SITE_DIR=site2 DB_NAME=joomla_upg PORT=8081 IDS_FILE=ids2.json bash 01-montar-joomla.sh
bash 04-actualizacion.sh   # (desde 0.6.24 comprueba ademas la tabla #__menu y el menu renderizado)
```

Variables (todas con valor por defecto, ver `config.sh`): `WORK`, `JOOMLA_VERSION`, `JOOMLA_URL`, `JOOMLA_SHA256` (si se define, se verifica la descarga), `SITE_DIR`, `DB_NAME`, `DB_USER`, `PORT`, `SMTP_PORT`, `IDS_FILE`.

## Qué hay

| Fichero | Función |
|---|---|
| `pruebas.php` | Batería de ~70 comprobaciones (F-xx funcionales, PS-xx de `docs/PRUEBAS-SEGURIDAD-JOOMLA6.md`, CP-06). Escribe `$WORK/resultados.json` |
| `05-gravatar.sh`, `lib/gravatar-http.php`, `lib/gravatar-navegador.js` | Consentimiento previo de Gravatar (0.6.17): HTML en los 3 modos (curl) y navegador real con las peticiones a gravatar.com interceptadas (antes/despues del clic, persistencia, revocar, API, almacenamiento bloqueado, cache de pagina). Resultados en la seccion 7 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md` |
| `06-rel-ugc.sh`, `lib/rel-ugc-http.php` | 0.6.18: inserta comentarios con enlaces en el articulo publico y comprueba el `rel` del HTML servido. Seccion 8 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md` |
| `08-idioma-es.sh`, `lib/idioma-es-joomla.php`, `lib/idioma-es-http.php` | 0.6.20: crea (si faltan) esqueletos de idioma es-ES (solo `langmetadata.xml`, sin red) para que el instalador copie los `.ini` es-ES de Engage, pone es-ES como idioma del sitio y del panel (y lo restaura al acabar) y comprueba: (1) con el analizador y la clase `Language` de Joomla que cada `.ini` instalado se lee entero y se sirve la cadena española; (2) por HTTP 10 pantallas del panel/plugins/módulo y el frontend: sin claves `COM_/MOD_/PLG_ENGAGE` visibles, con cadenas españolas esperadas y sin texto inglés de Engage (Joomla usa en-GB de respaldo, por eso se busca también el inglés); (3) un correo real capturado en español. Si el esqueleto se crea después de instalar Engage hay que reinstalar el paquete. Sección 11 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md` |
| `07-jbcookies.sh`, `lib/jbcookies-navegador.js` | 0.6.19: instala JBCookies desde `JBCOOKIES_ZIP` (si ya esta instalado lo reutiliza: reinstalarlo encima falla en su `script.php` con Joomla 6), lo publica y comprueba con el modulo real: 0 peticiones antes de decidir, Aceptar, Rechazar, Guardar seleccion con el grupo activado/desactivado, persistencia, cookies hostiles puestas a mano, modo `engage` sin cambios y cache de pagina. Al terminar despublica el modulo. Seccion 10 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md` |
| `09-respuestas.sh`, `lib/respuestas-http.php`, `lib/respuestas-navegador.js` | 0.6.21: crea hilos por el mismo flujo del formulario (lee el boton Responder del HTML servido) y registra posicion, nivel y cita de cada comentario para `comments_ordering` asc/desc x `max_level` 1, 2, 3 y 6; cita escapada, padre despublicado/borrado, opcion apagada; y en Chromium mide la sangria (px) de cada opcion `reply_indent`, el estilo de `reply_style`, la variable `--akengage-reply-indent` del usuario, pantalla estrecha, "Cargar CSS propio" y el flujo Responder -> enviar. Seccion 12 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md` |
| `11-panel.sh`, `lib/panel-navegador.js` | 0.6.24: el panel de control en Chromium sobre el Joomla real. Siembra 25 comentarios (con nombres y textos con HTML/JS), crea un usuario Manager (`mgrpanel`, contrasena al azar en `$WORK/managerpass.txt`) y mide, en claro, medio, oscuro y automatico (dispositivo claro y oscuro) a 1280 y 390 px: contraste WCAG de todo el texto (4,5:1) y de iconos, grafico, glifos sobre degradados y anillo de foco (3:1), desbordamiento, teclado (flechas, Inicio, Fin, foco visible en todos los controles, dialogo con foco en Cancelar y Escape), persistencia, `localStorage` bloqueado, acciones rapidas con comprobacion en la BD, CSRF sin token y con token inventado, Manager con y sin permisos, semaforos cambiando la configuracion real, XSS, estilos/scripts en linea y peticiones a otros hosts. Restaura parametros, permisos y plugins al acabar |
| `12-ajustes.sh`, `lib/ajustes-navegador.js`, `lib/contraste-pagina.js` | 0.6.25: la pantalla de opciones modernas. Mide (compartido con `11-panel.sh`) el contraste de todo el texto y de los controles (borde de campos y segmentados, pista y bola del interruptor, tarjeta de tema elegida) en las 8 categorias; comprueba con el teclado cada tipo de control contra la BD (componente y plugins), el efecto en el HTML del frontend, la fusion sin perdida (parametro centinela `zz_keep`, reglas de permisos), la idempotencia, los errores con la red interceptada (500, 403, 422, HTML, red caida: el control vuelve al valor anterior), 31 peticiones no validas por HTTP (sin token, token falso, GET, ambitos y claves ajenas, valores, arrays, NUL, XSS, 2 MB) que no cambian ningun dato, Manager sin `core.admin` (403 y pantalla denegada), admin del componente sin `core.edit` de `com_plugins` (403 solo en plugins) y el registro de acciones sin el valor. Crea los usuarios `mgrpanel` y `mgradmin` (contrasena al azar en `$WORK/managerpass.txt`) y restaura todo al acabar |
| `12-opciones-maquetacion.sh`, `lib/maquetacion-navegador.js` | 0.6.26: mide las filas de Opciones (solapes de cajas y de texto real, columna de texto >= 12 rem, <= 12 lineas, desbordes, segmentos por teclado, contraste) a 1920/1280/768/390 px en Claro/Medio/Oscuro |
| `13-plugins-persistencia.sh`, `lib/plugins-persistencia.js` | 0.6.26: persistencia por la interfaz de todos los ajustes de plugin (BD, ficha clasica, recarga, `d=` de Gravatar en el HTML servido) |
| `14-opciones-segmentados.sh`, `lib/segmentados-navegador.js` | 0.6.27: mide cada control segmentado de cada categoria (corto en una fila e igual anchura, largo sin solapes, ayuda acortada con control accesible por teclado, solapes, desbordes, contraste) a 1920/1280/768/390 px en Claro/Medio/Oscuro |
| `15-frontend-movil.sh`, `lib/frontend-movil.js` | 0.6.27: mide el aviso de Gravatar (texto y boton contra la curva real de las esquinas, desborde, radio) y los iconos del nombre (estrella, usuario, invitado: existencia, tamano, dentro de la cabecera y del viewport, sin recorte, glifo pintado, con Font Awesome bloqueada) en los 4 temas |
| `lib/capturas-docs.js`, `lib/optimizar-png.php` | Capturas de `docs/img` con datos de ejemplo en espanol; PNG de paleta (GD) por debajo de 200 KB |
| `10-temas.sh`, `lib/temas-navegador.js` | 0.6.23: hilo de 3 niveles + sin publicar + spam como administrador; para cada tema (modern, minimal, dark) mide el contraste WCAG de TODO el texto visible del contenedor contra su fondo efectivo y los botones con el raton encima, sobre pagina clara, oscura y dos plantillas "hostiles" (color forzado con `!important`), a 1100 y 400 px, con `reply_style`, "Cargar CSS personalizado" y `colorScheme` claro/oscuro; comprueba que `classic` no carga hojas y es identico, y que `reply_indent` no cambia. Falla si algun texto < 4,5:1 (3:1 grande y bordes). Usa la contrasena del admin de `$WORK` (no se guarda en el repo) |
| `04-actualizacion.sh` | Instala el 3.4.2 original, crea datos y ajustes reales, instala encima el paquete del fork y compara tablas, ajustes, ficheros y update site |
| `lib/empaquetar-upstream.php` | Reconstruye `pkg_engage-3.4.2.zip` desde `upstream/3.4.2-instalado` (copia temporal; `upstream/` no se toca) |
| `lib/seed.php` | Crea categorías y artículos con los modelos reales de Joomla (idempotente) |
| `lib/datos-reales.php`, `lib/humo-post-actualizacion.php` | Datos de la prueba de actualización y comprobaciones posteriores |
| `lib/Cliente.php` | Cliente HTTP con cookies (invitado, registrado, editor, administrador) |
| `lib/router.php`, `lib/servidor.sh`, `lib/smtp-sink.php` | Servidor `php -S`, su reinicio y el SMTP de captura |

## Avisos

- Reinicia `php -S` (lo hace `02-instalar-engage.sh`) después de instalar o actualizar: es un solo proceso con opcache y caché de rutas y, si arrancó antes de instalar Engage, devuelve `Class ... not found`.
- `pruebas.php` borra los comentarios del sitio de pruebas, cambia permisos y parámetros de `com_engage` y los de `com_mails`: no lo apuntes a nada que no sea desechable.
- Es un sitio HTTP en `127.0.0.1` como root: no sustituye a probar en un alojamiento real.
