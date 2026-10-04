# Engage (fork comunitario)

Engage es un sistema de comentarios para los artículos de Joomla. Este repositorio es un fork comunitario del Akeeba Engage 3.4.2, cuyo desarrollo se detuvo en 2025. El objetivo es mantenerlo funcionando en Joomla 5 y 6 y en versiones actuales de PHP, sin cambiar su comportamiento ni romper las instalaciones existentes. No es un producto de Akeeba Ltd ni está respaldado por ella. El código original conserva los avisos de copyright de sus autores y se distribuye bajo GPL v3 o posterior.

---

*English:* Unofficial community fork of Akeeba Engage — comments for Joomla articles, being adapted for Joomla 6. The original project is discontinued. GPL v3 or later. Not affiliated with Akeeba Ltd.

## Estado

Fase 1: la fuente está reorganizada en `src/` y hay un script que genera los ZIP instalables y el paquete.

Fase 3 (versiones del fork 0.3.0 a 0.5.3): compatibilidad con Joomla 5 y 6 y PHP 8.1 a 8.4, correcciones de seguridad y HTMLPurifier 4.19.1, aplicando `docs/INFORME-FASE2-JOOMLA6.md`. Cada cambio consta en `CHANGELOG.md` con sus archivos y su impacto. Los archivos modificados deliberadamente respecto a la referencia 3.4.2 los lista `php build/verificar.php`. La versión propia de los manifiestos es la 3.4.2.1 (desde la 0.6.0). Nada de esto se ha probado en un Joomla real: solo hay pruebas con stubs (`php tests/run.php`).

## Temas visuales (0.6.23)

En **Componentes > Engage > Opciones > Apariencia** se elige el aspecto de los comentarios sin pegar CSS: `Clásico` (por defecto, no cambia nada), `Moderno`, `Minimalista` u `Oscuro`. Cada tema fija siempre fondo y color de texto, así que se lee sobre plantillas claras y oscuras, y sus colores y medidas son variables `--eg-*` que se pueden sobrescribir desde el CSS de la plantilla. Guía, capturas y lista de variables: [`docs/TEMAS.md`](docs/TEMAS.md).

## Estructura

| Ruta | Contenido |
|---|---|
| `upstream/3.4.2-instalado/` | Copia intacta de Engage 3.4.2 tal como está instalado. **No se modifica nunca**; es la referencia. |
| `src/component/` | `engage.xml` + `backend/`, `frontend/`, `media/` (cada uno con su `language/<tag>/`). |
| `src/modules/site/engage_latest/` | Módulo de sitio `mod_engage_latest` (con `language/`). |
| `src/plugins/<grupo>/<nombre>/` | Los 10 plugins (con `language/`). |
| `src/package/` | `pkg_engage.xml`, `script.engage.php` y `language/<tag>/pkg_engage.sys.ini` (es-ES y en-GB). |
| `build/build.php` | Genera los ZIP en `dist/` (ignorado por git). |
| `updates/pkgengage.xml` | Servidor de actualizaciones de Joomla (el `<sha256>` lo escribe `build/build.php`). |
| `docs/PUBLICAR-RELEASE.md` | Cómo publicar la release a mano. |
| `build/verificar.php` | Comprobaciones: manifiestos frente a ZIP, `php -l`, comparación con upstream, XML, reproducibilidad. |
| `LICENSE`, `NOTICE.md`, `CHANGELOG.md` | Licencia GPL v3, atribución y registro de cambios. |

## Compilar

Requiere PHP 8 en línea de comandos con `zip` y `simplexml`; no hay otras dependencias.

```
php build/build.php       # genera dist/*.zip y dist/pkg_engage-<versión>.zip
php build/verificar.php   # comprueba el resultado y ejecuta las pruebas (opcional)

composer install --working-dir=tests   # una vez: librerías reales de joomla/* usadas por las pruebas
php tests/run.php                      # pruebas ejecutables con stubs mínimos de Joomla
```

La versión se lee de `src/package/pkg_engage.xml`; los nombres de los ZIP, de los `<file>` de ese manifiesto. Los ZIP son reproducibles (orden fijo, fecha fija, rutas con `/`).

## Pendiente y limitaciones conocidas

- **Servidor de actualizaciones**: desde la 0.6.1 `pkg_engage.xml` apunta a `updates/pkgengage.xml` de este repositorio (vía raw.githubusercontent.com). La release `v3.4.2.1` y su ZIP **aún no están publicados en GitHub**: ver `docs/PUBLICAR-RELEASE.md`. El update site antiguo de `cdn.akeeba.com` no se desactiva por código (Joomla lo reubica solo al actualizar); comprobación manual en ese documento.
- **Respuestas** (0.6.21): cada respuesta se muestra anidada bajo su comentario con la línea «En respuesta a <nombre>» y una sangría visible en cualquier plantilla Bootstrap; pestaña «Respuestas» en las opciones (cita, sangría, marca visual). Ver `docs/RESPUESTAS-DISENO.md`.
- **Idiomas**: los manifiestos de upstream declaraban 97 archivos `de-DE`, `el-GR`, `fr-FR` y `nl-NL` que no existen. Desde la 0.5.2 ya no se declaran y el paquete declara también `es-ES/pkg_engage.sys.ini`. Idiomas incluidos: en-GB y es-ES. Desde la 0.6.20 el es-ES cubre **todas** las cadenas de todas las extensiones (componente, módulo y plugins); `tests/25-idiomas-es-ES.php` vigila que cada es-ES tenga las mismas claves, marcadores, etiquetas HTML y URLs que su en-GB.
- No se ha instalado en Joomla: solo se verifica la estructura de los ZIP frente a los manifiestos.

## Extensiones del paquete (`pkg_engage`)

- Componente: `com_engage`
- Módulo de sitio: `mod_engage_latest`
- Plugins: `content/engage`, `system/engagecache`, `user/engage`, `privacy/engage`, `actionlog/engage`, `datacompliance/engage`, `console/engage`, `engage/akismet`, `engage/email`, `engage/gravatar`
- Gravatar: desde 0.6.17 el plugin pide consentimiento al visitante antes de contactar con gravatar.com (modo `ask` por defecto; `off` / `always`). Desde 0.6.19 el consentimiento puede gobernarlo el módulo de cookies JBCookies (`consent_source=jbcookies`; JBCookies no se distribuye con el fork). Ver [`docs/GRAVATAR-CONSENTIMIENTO.md`](docs/GRAVATAR-CONSENTIMIENTO.md).

## Licencia

GNU General Public License v3 o posterior. Ver [`LICENSE`](LICENSE) y [`NOTICE.md`](NOTICE.md).
