# Engage (fork comunitario)

Engage es un sistema de comentarios para los artículos de Joomla. Este repositorio es un fork comunitario del Akeeba Engage 3.4.2, cuyo desarrollo se detuvo en 2025. El objetivo es mantenerlo funcionando en Joomla 5 y 6 y en versiones actuales de PHP, sin cambiar su comportamiento ni romper las instalaciones existentes. No es un producto de Akeeba Ltd ni está respaldado por ella. El código original conserva los avisos de copyright de sus autores y se distribuye bajo GPL v3 o posterior.

---

*English:* Unofficial community fork of Akeeba Engage — comments for Joomla articles, being adapted for Joomla 6. The original project is discontinued. GPL v3 or later. Not affiliated with Akeeba Ltd.

## Estado

Fase 1: la fuente está reorganizada en `src/` y hay un script que genera los ZIP instalables y el paquete. Los archivos de las extensiones son idénticos byte a byte a los de la referencia 3.4.2; solo cambia su ubicación (más los `pkg_engage.sys.ini`, que son nuevos).

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
php build/verificar.php   # comprueba el resultado (opcional)
```

La versión se lee de `src/package/pkg_engage.xml`; los nombres de los ZIP, de los `<file>` de ese manifiesto. Los ZIP son reproducibles (orden fijo, fecha fija, rutas con `/`).

## Pendiente y limitaciones conocidas

- **URL de actualizaciones**: `pkg_engage.xml` sigue apuntando a `https://cdn.akeeba.com/updates/pkgengage.xml`. Se cambiará en la Fase 4 (sin tocar de momento).
- **Idiomas declarados que no existen**: los manifiestos (heredados de upstream) declaran archivos `de-DE`, `el-GR`, `fr-FR` y `nl-NL` (96 en las extensiones, más `de-DE/pkg_engage.sys.ini` en el paquete), y algunos `es-ES`, que la referencia instalada no incluye (solo trae en-GB y es-ES). Los ZIP se generan sin ellos y los manifiestos no se han tocado. No se ha probado cómo reacciona Joomla; hay que decidirlo en una fase posterior (retirar las referencias o aportar los archivos).
- No se ha instalado en Joomla: solo se verifica la estructura de los ZIP frente a los manifiestos.

## Extensiones del paquete (`pkg_engage`)

- Componente: `com_engage`
- Módulo de sitio: `mod_engage_latest`
- Plugins: `content/engage`, `system/engagecache`, `user/engage`, `privacy/engage`, `actionlog/engage`, `datacompliance/engage`, `console/engage`, `engage/akismet`, `engage/email`, `engage/gravatar`

## Licencia

GNU General Public License v3 o posterior. Ver [`LICENSE`](LICENSE) y [`NOTICE.md`](NOTICE.md).
