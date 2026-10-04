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

# Prueba de actualizacion 3.4.2 -> paquete del fork, en un segundo Joomla (puerto 8081, BD joomla_upg)
SITE_DIR=site2 DB_NAME=joomla_upg PORT=8081 IDS_FILE=ids2.json bash 01-montar-joomla.sh
bash 04-actualizacion.sh
```

Variables (todas con valor por defecto, ver `config.sh`): `WORK`, `JOOMLA_VERSION`, `JOOMLA_URL`, `JOOMLA_SHA256` (si se define, se verifica la descarga), `SITE_DIR`, `DB_NAME`, `DB_USER`, `PORT`, `SMTP_PORT`, `IDS_FILE`.

## Qué hay

| Fichero | Función |
|---|---|
| `pruebas.php` | Batería de ~70 comprobaciones (F-xx funcionales, PS-xx de `docs/PRUEBAS-SEGURIDAD-JOOMLA6.md`, CP-06). Escribe `$WORK/resultados.json` |
| `05-gravatar.sh`, `lib/gravatar-http.php`, `lib/gravatar-navegador.js` | Consentimiento previo de Gravatar (0.6.17): HTML en los 3 modos (curl) y navegador real con las peticiones a gravatar.com interceptadas (antes/despues del clic, persistencia, revocar, API, almacenamiento bloqueado, cache de pagina). Resultados en la seccion 7 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md` |
| `06-rel-ugc.sh`, `lib/rel-ugc-http.php` | 0.6.18: inserta comentarios con enlaces en el articulo publico y comprueba el `rel` del HTML servido. Seccion 8 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md` |
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
