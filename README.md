# Engage (fork comunitario)

Engage es un sistema de comentarios para los artículos de Joomla. Este repositorio es un fork comunitario del Akeeba Engage 3.4.2, cuyo desarrollo se detuvo en 2025. El objetivo es mantenerlo funcionando en Joomla 5 y 6 y en versiones actuales de PHP, sin cambiar su comportamiento ni romper las instalaciones existentes. No es un producto de Akeeba Ltd ni está respaldado por ella. El código original conserva los avisos de copyright de sus autores y se distribuye bajo GPL v3 o posterior.

**Fork iniciado y dirigido por ChasKa.**

*English:* Unofficial community fork of Akeeba Engage — comments for Joomla articles, adapted for Joomla 5 and 6. The original project is discontinued. GPL v3 or later. Not affiliated with Akeeba Ltd. Started and directed by ChasKa.

## Estado

- **Versión del fork:** 0.7.1. **Versión del paquete:** 3.4.3 (mayor que la 3.4.2 original y que la 3.4.2.1 ya publicada, para que Joomla la trate como una actualización en el mismo sitio).
- **Requisitos:** Joomla 5.0 o superior (incluido Joomla 6), PHP 8.1 o superior, MySQL 8.0.13 / MariaDB 10.4. No admite PostgreSQL (el esquema es solo MySQL/MariaDB).
- **Probado en:** Joomla 6.1.4 con PHP 8.3 y MariaDB 10.11, instalando el paquete limpio y **actualizando encima de una 3.4.2 original y del paquete 3.4.2.1 ya publicado, con datos de ejemplo** (comentarios, ajustes y permisos idénticos antes y después; la tabla de reacciones se crea sola). Resultados en [`docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md`](docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md).
- **No probado:** Joomla 5.x, PHP 8.1/8.4/8.5, MySQL, otros navegadores distintos de Chromium y otros temas de administración distintos de Atum.
- **Releases en GitHub:** la **v3.4.2.1 está publicada** (inmutable). La **v3.4.3** (reacciones a los comentarios) todavía **no está publicada**: el servidor de actualizaciones de esta rama (`updates/pkgengage.xml`) ya apunta a ella, pero su descarga solo existirá cuando se publique (ver [`docs/PUBLICAR-RELEASE.md`](docs/PUBLICAR-RELEASE.md)); `main` no debe actualizarse antes. Hasta entonces se instala subiendo el ZIP a mano.

## Novedades respecto a Engage 3.4.2

| Área | Qué trae el fork |
|---|---|
| **Seguridad** | Lista fija para ordenar comentarios (cerraba un fallo que permitía deducir datos de usuarios), HTML de los comentarios purificado siempre al mostrarlo, enlaces firmados de los correos limitados a un comentario, validación de los campos del formulario y de la paginación, enlaces `nofollow ugc`, HTML Purifier 4.19.1. Informe y pruebas en [`docs/INFORME-FASE2-JOOMLA6.md`](docs/INFORME-FASE2-JOOMLA6.md) y [`docs/PRUEBAS-SEGURIDAD-JOOMLA6.md`](docs/PRUEBAS-SEGURIDAD-JOOMLA6.md). |
| **Compatibilidad** | Joomla 5 y 6, PHP 8.1 a 8.4, mínimos aplicados por el instalador, servidor de actualizaciones propio. |
| **Privacidad** | Gravatar solo se carga si el visitante lo acepta (modo `ask` por defecto), con aviso propio o gobernado por el gestor de cookies JBCookies. Ver [`docs/GRAVATAR-CONSENTIMIENTO.md`](docs/GRAVATAR-CONSENTIMIENTO.md). |
| **Idioma** | Español completo (se tradujeron 241 cadenas que faltaban), con trato de usted, y erratas corregidas. |
| **Respuestas** | Anidadas bajo su comentario, con sangría visible, «En respuesta a <nombre>» y opciones de diseño. Ver [`docs/RESPUESTAS-DISENO.md`](docs/RESPUESTAS-DISENO.md). |
| **Temas visuales** | `Clásico` (por defecto, sin cambios), `Moderno`, `Minimalista` y `Oscuro`, legibles en cualquier plantilla clara u oscura. Ver [`docs/TEMAS.md`](docs/TEMAS.md). |
| **Panel de control** | Pantalla de entrada con accesos rápidos, números, gráfico de 30 días, últimos comentarios con acciones, semáforo de estado y tres diseños (Claro, Medio, Oscuro). Sin conexiones externas. Ver [`docs/PANEL.md`](docs/PANEL.md). |
| **Opciones modernas** | Categorías, interruptores y botones de opción que se guardan al instante; la pantalla clásica de Joomla sigue como respaldo. |
| **Móvil** | Avatar visible y reducido en pantallas estrechas con los temas nuevos (opción «Avatar en móvil»). |
| **Reacciones** | Botones de **me gusta**, **no me gusta** (con contador) y **favorito** (estrella; el comentario favorito se ve en amarillo suave, solo para quien lo marca), de contorno y rellenos al pulsarlos, en la fila de «Responder». Solo usuarios con sesión, no sobre el propio comentario, con límite de frecuencia, compatibles con la caché de página y con exportación y supresión por RGPD. Tabla nueva `#__engage_reactions`. Ver [`docs/REACCIONES.md`](docs/REACCIONES.md). |
| **Fallos del original** | Filtro de fechas del panel, comando `engage:cleanspam`, enlace de baja de correos en texto plano, varios avisos de PHP. |

![Panel de control en el diseño Claro](docs/img/panel-claro.png)

![Opciones modernas](docs/img/config-claro.png)

El detalle de cada cambio, con sus archivos e impacto, está en [`CHANGELOG.md`](CHANGELOG.md).

## Instalación

1. **Haga copia de seguridad** del sitio y de la base de datos.
2. En Joomla: *Sistema → Instalar → Extensiones → Subir archivo* y suba `pkg_engage-3.4.3.zip`. Se instala **encima** de una instalación existente de Engage, sin desinstalarla antes.
3. Pulse **Akeeba Engage** en el menú de administración (abre el panel de control).
4. Revise el **semáforo de estado** del panel y las **Opciones** (tema visual, Gravatar, antispam, moderación).

Para generar el ZIP usted mismo, vea «Compilar» más abajo.

## Estructura del repositorio

| Ruta | Contenido |
|---|---|
| `upstream/3.4.2-instalado/` | Copia intacta de Engage 3.4.2 tal como estaba instalado. **No se modifica nunca**; es la referencia. |
| `src/component/` | `engage.xml` + `backend/`, `frontend/`, `media/` (cada uno con su `language/<tag>/`). |
| `src/modules/site/engage_latest/` | Módulo de sitio `mod_engage_latest`. |
| `src/plugins/<grupo>/<nombre>/` | Los 10 plugins. |
| `src/package/` | `pkg_engage.xml`, `script.engage.php` y los archivos de idioma del paquete. |
| `build/` | `build.php` genera los ZIP en `dist/` (ignorado por git); `verificar.php` los comprueba. |
| `updates/pkgengage.xml` | Servidor de actualizaciones de Joomla (el `<sha256>` lo escribe `build/build.php`). |
| `tests/` | Pruebas ejecutables (`run.php`) y pruebas contra un Joomla real (`joomla-live/`). |
| `docs/` | Informes, guías y capturas (ver abajo). |
| `LICENSE`, `NOTICE.md`, `CHANGELOG.md` | Licencia GPL v3, atribución y registro de cambios. |

### Documentación

- [`docs/PANEL.md`](docs/PANEL.md): panel de control y opciones modernas.
- [`docs/TEMAS.md`](docs/TEMAS.md): temas visuales y variables CSS.
- [`docs/RESPUESTAS-DISENO.md`](docs/RESPUESTAS-DISENO.md): respuestas anidadas y su diseño.
- [`docs/REACCIONES.md`](docs/REACCIONES.md): me gusta, no me gusta y favorito: uso, opciones, privacidad, caché, seguridad y texto para la política de privacidad.
- [`docs/GRAVATAR-CONSENTIMIENTO.md`](docs/GRAVATAR-CONSENTIMIENTO.md): consentimiento de Gravatar y conexión con JBCookies.
- [`docs/INFORME-FASE2-JOOMLA6.md`](docs/INFORME-FASE2-JOOMLA6.md) y [`docs/PRUEBAS-SEGURIDAD-JOOMLA6.md`](docs/PRUEBAS-SEGURIDAD-JOOMLA6.md): compatibilidad, seguridad y pruebas.
- [`docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md`](docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md): resultados reales de las pruebas.
- [`docs/PUBLICAR-RELEASE.md`](docs/PUBLICAR-RELEASE.md): cómo publicar una release.

## Extensiones del paquete (`pkg_engage`)

- Componente: `com_engage`
- Módulo de sitio: `mod_engage_latest`
- Plugins: `content/engage`, `system/engagecache`, `user/engage`, `privacy/engage`, `actionlog/engage`, `datacompliance/engage`, `console/engage`, `engage/akismet`, `engage/email`, `engage/gravatar`

Se conservan los identificadores técnicos del original (`com_engage`, `plg_*_engage`, el espacio de nombres `Akeeba\...`) para que la actualización en el mismo sitio no rompa nada ni obligue a migrar la base de datos.

## Compilar y probar

Requiere PHP 8 en línea de comandos con `zip` y `simplexml`; no hay otras dependencias para compilar.

```
php build/build.php       # genera dist/*.zip y dist/pkg_engage-<versión>.zip
php build/verificar.php   # comprueba el resultado y ejecuta las pruebas

composer install --working-dir=tests   # una vez: librerías reales de joomla/* usadas por las pruebas
php tests/run.php                      # pruebas ejecutables con piezas mínimas de Joomla
```

La versión se lee de `src/package/pkg_engage.xml`. Los ZIP son reproducibles (orden fijo, fecha fija, rutas con `/`). Las pruebas contra un Joomla real (`tests/joomla-live/`) están explicadas en su `LEEME.md`.

## Limitaciones conocidas

- La release v3.4.3 en GitHub no está publicada todavía (ver «Estado»).
- Las reacciones necesitan JavaScript (sin él los botones no se muestran) y vienen activadas por defecto; se desactivan en Opciones > Reacciones.
- Gravatar, el aviso de cookies de JBCookies y otros servicios de terceros no se distribuyen con el fork. JBCookies tiene una inconsistencia de licencia (README «Non-Commercial» frente a GPL v3 en su archivo de licencia) documentada en [`docs/GRAVATAR-CONSENTIMIENTO.md`](docs/GRAVATAR-CONSENTIMIENTO.md).
- Con el tema `Clásico`, los iconos junto al nombre dependen de la fuente de iconos de la plantilla, como en la 3.4.2 original.

## Créditos y licencia

- **Fork iniciado y dirigido por ChasKa** (2026).
- **Obra original:** Akeeba Engage, de Nicholas K. Dionysopoulos / Akeeba Ltd. Se conservan todos los avisos de copyright del original. Ver [`NOTICE.md`](NOTICE.md).
- **Licencia:** GNU General Public License v3 o posterior. Ver [`LICENSE`](LICENSE).
