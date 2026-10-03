# Engage (fork comunitario)

Engage es un sistema de comentarios para los artículos de Joomla. Este repositorio es un fork comunitario del Akeeba Engage 3.4.2, cuyo desarrollo se detuvo en 2025. El objetivo es mantenerlo funcionando en Joomla 5 y 6 y en versiones actuales de PHP, sin cambiar su comportamiento ni romper las instalaciones existentes. No es un producto de Akeeba Ltd ni está respaldado por ella. El código original conserva los avisos de copyright de sus autores y se distribuye bajo GPL v3 o posterior.

---

*English:* Unofficial community fork of Akeeba Engage — comments for Joomla articles, being adapted for Joomla 6. The original project is discontinued. GPL v3 or later. Not affiliated with Akeeba Ltd.

## Estado

Fase 1: la fuente está reorganizada en `src/` y hay un script que genera los ZIP instalables y el paquete.

Fase 3 (versiones del fork 0.3.0 a 0.5.3): compatibilidad con Joomla 5 y 6 y PHP 8.1 a 8.4, correcciones de seguridad y HTMLPurifier 4.19.1, aplicando `docs/INFORME-FASE2-JOOMLA6.md`. Cada cambio consta en `CHANGELOG.md` con sus archivos y su impacto. Los archivos modificados deliberadamente respecto a la referencia 3.4.2 los lista `php build/verificar.php`. La versión propia de los manifiestos es la 3.4.2.1 (desde la 0.6.0). Nada de esto se ha probado en un Joomla real: solo hay pruebas con stubs (`php tests/run.php`).

## Estructura

| Ruta | Contenido |
|---|---|
| `upstream/3.4.2-instalado/` | Copia intacta de Engage 3.4.2 tal como está instalado. **No se modifica nunca**; es la referencia. |
| `src/component/` | `engage.xml` + `backend/`, `frontend/`, `media/` (cada uno con su `language/<tag>/`). |
| `src/modules/site/engage_latest/` | Módulo de sitio `mod_engage_latest` (con `language/`). |
| `src/plugins/<grupo>/<nombre>/` | Los 10 plugins (con `language/`). |
| `src/package/` | `pkg_engage.xml`, `script.engage.php` y `language/<tag>/pkg_engage.sys.ini` (es-ES y en-GB). |
| `build/build.php` | Genera los ZIP en `dist/` (ignorado por git). |
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

- **URL de actualizaciones**: `pkg_engage.xml` sigue apuntando a `https://cdn.akeeba.com/updates/pkgengage.xml`. Se cambiará en la Fase 4 (sin tocar de momento).
- **Idiomas**: los manifiestos de upstream declaraban 97 archivos `de-DE`, `el-GR`, `fr-FR` y `nl-NL` que no existen. Desde la 0.5.2 ya no se declaran y el paquete declara también `es-ES/pkg_engage.sys.ini`. Idiomas incluidos: en-GB y es-ES.
- No se ha instalado en Joomla: solo se verifica la estructura de los ZIP frente a los manifiestos.

## Extensiones del paquete (`pkg_engage`)

- Componente: `com_engage`
- Módulo de sitio: `mod_engage_latest`
- Plugins: `content/engage`, `system/engagecache`, `user/engage`, `privacy/engage`, `actionlog/engage`, `datacompliance/engage`, `console/engage`, `engage/akismet`, `engage/email`, `engage/gravatar`

## Licencia

GNU General Public License v3 o posterior. Ver [`LICENSE`](LICENSE) y [`NOTICE.md`](NOTICE.md).
