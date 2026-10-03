# Registro de cambios del fork

## 0.4.1 — 2026-10-03 (Fase 3, paso 3a: purificación al mostrar, S2 y S3)
- Qué:
  1. `HtmlFilter::purify()` ya no devuelve el texto crudo cuando el filtro de Joomla del espectador es "Sin filtrar" (`none`, el valor por defecto de Super Usuarios): HTML Purifier se ejecuta siempre en los modos `strict` y `htmlpurifier`. Con "usar la configuración de filtros de Joomla" activada, el caso `none` usa la lista blanca estricta en vez de dejar HTML Purifier sin restricciones.
  2. Nuevo `HtmlFilter::filterTextForDisplay()`; `Engage::processFlatComment()` (que usan el listado público, el panel, el módulo y el correo mediante `processCommentTextForDisplay`) lo aplica ahora también a los comentarios que ya son HTML. Antes solo se purificaban los comentarios "planos" (estilo WordPress/BBCode): el HTML se mostraba tal como lo dejó el filtro de guardado de Joomla. En modo `joomla` se mantiene el comportamiento anterior (sin cambios).
  3. `mod_engage_latest` (S3): el cuerpo pasa por `processCommentTextForDisplay` (más `textExcerpt` sobre el texto ya purificado) en lugar de imprimirse tal cual; `user_name` y las URL (`href`) se escapan con `htmlspecialchars` antes de entrar en las cadenas de idioma que contienen HTML.
- Por qué: el XSS almacenado afecta sobre todo a quien tiene más privilegios (el filtro "Sin filtrar" se saltaba la purificación justo para ellos), y la causa raíz era más amplia que `none`: el HTML de los comentarios solo dependía del filtro de guardado del autor.
- Archivos: `src/component/backend/src/Helper/HtmlFilter.php`, `src/component/backend/src/Service/Html/Engage.php`, `src/modules/site/engage_latest/tmpl/default.php`. Se restauran además los finales de línea CRLF originales de `CommentsModel.php` y `CommentsController.php` (la 0.4.0 los había convertido a LF por error: el contenido no cambia).
- Impacto colateral a revisar (cambio visible): con `filter_mode` = `strict` (valor por defecto) o `htmlpurifier`, el HTML de comentarios existentes se muestra ahora limitado a la lista blanca de la configuración (`p,b,a[href],i,u,strong,em,small,big,span[style],font[size],font[color],ul,ol,li,br,img[src],img[width],img[height],code,pre,blockquote` por defecto): etiquetas como `h1-h6`, `div`, `table`, `s`, `del` o atributos como `alt`/`class` dejan de verse en comentarios que las traían. Para quien prefiera el comportamiento anterior queda el modo `joomla` (menos seguro: depende del filtro de guardado y, con "Sin filtrar", muestra el HTML tal cual). El correo y el panel ya purificaban con su propia lista y siguen igual.
- Pruebas: `tests/03-htmlfilter-none.php` con HTML Purifier real (carga por `includeHTMLPurifier`, modo composer): 5 pares modo/configuración × 5 tipos de filtro de espectador con `<script>`, `onerror`, `onclick`, `javascript:`, `<iframe>`, `<svg onload>` y CSS con `javascript:`; el camino completo `processCommentTextForDisplay`; y comprobación estática de la plantilla del módulo. Comprobado que la prueba falla contra el código anterior (5 fallos) y pasa con el nuevo. No se ha renderizado el módulo en Joomla (no hay Joomla).

## 0.4.0 — 2026-10-03 (Fase 3, paso 2: lista blanca de ordenación, S1)
- Qué: la columna y la dirección de `ORDER BY` del listado de comentarios pasan por una lista blanca. Nuevo `Helper/ListOrdering.php`; `CommentsModel::getListQuery` (punto único por el que pasan todas las consultas de lista, también `commentIDTreeSliceWithDepth` y `commentTreeSlice`) ya no usa el valor del estado tal cual; el controlador público solo acepta `c.created` o `c.id` de la petición y la dirección exacta `ASC`/`DESC` (insensible a mayúsculas, sin recortar espacios ni NUL); cualquier otra cosa da `c.created DESC`.
- Por qué: `?akengage_order=u.password` (visitante anónimo) llegaba a `quoteName()`, que no inyecta SQL pero acepta cualquier columna de las tablas unidas (`#__users`, artículos, categorías): oráculo de ordenación para deducir contraseñas/correos de los autores, o error SQL 500. La dirección en el modelo (`$db->escape`) tampoco estaba acotada (solo el controlador público la comprobaba): ahora se valida también en el modelo, que es lo que usan el panel de administración y los módulos.
- Causa raíz / mismo patrón: revisado todo `src/` (`->order(`, `list.ordering`, `list.direction`): solo hay un punto donde la ordenación venía de fuera; los demás `order()` son fijos. El listado del panel (`filter_fields` histórico: `id`, `name`, `created`...) se resuelve ahora a la tabla `c` (antes una columna sin calificar como `id` daba "ambiguous column"); el listado del panel sigue ordenando por `user_name`, `c.id`, `c.created`, `c.enabled` igual que antes.
- Archivos: `src/component/backend/src/Helper/ListOrdering.php` (nuevo), `src/component/backend/src/Model/CommentsModel.php`, `src/component/frontend/src/Controller/CommentsController.php`.
- Impacto colateral a revisar: un visitante que enviara a mano `akengage_order` distinto de `c.created`/`c.id` ahora ve el orden por fecha (no hay interfaz pública que lo ofrezca; el plugin solo fija la dirección). Observado y NO tocado: el filtro de fechas del modelo usa `c.created_on`, columna que no existe (la tabla tiene `created`); no es del alcance de esta versión, queda anotado para decidir.
- `build/verificar.php`: los archivos que no existen en upstream se informan como "nuevo del fork" en lugar de fallo.
- Pruebas: `tests/02-orden.php` (ListOrdering con 24 valores de ataque: `u.password`, `c.created; DROP TABLE x`, backticks, NUL, arrays, objetos...; y `getListQuery` real con stubs de Joomla comprobando el ORDER BY generado).

## 0.3.0 — 2026-10-03 (Fase 3, paso 1: Filesystem)
- Qué: `Joomla\CMS\Filesystem\{File,Folder,Path}` sustituido por `Joomla\Filesystem\{File,Folder,Path}` (framework joomla/filesystem).
- Por qué: esas clases del CMS se eliminaron en Joomla 6 (solo sobreviven como alias en el plugin `behaviour/compat6`, apagado por defecto). Sin ellas, `ViewLoadAnyTemplateTrait` (cada carga de plantilla de las vistas) y la actualización (`UpgradeModel`, `UpdatesModel`) darían error fatal "Class not found". La clase del framework existe en Joomla 4.3, 5 y 6 con las mismas firmas.
- Archivos: `src/component/backend/src/Mixin/ViewLoadAnyTemplateTrait.php`, `.../Model/UpgradeModel.php`, `.../Model/UpdatesModel.php` (solo las líneas `use`). Causa raíz revisada: no quedan más usos de `Joomla\CMS\Filesystem`, `JFile`, `JFolder` ni `JPath` en `src/`.
- Impacto colateral a revisar: el `File::delete/write` y `Folder::delete/move` del framework lanzan `FilesystemException` si fallan (el envoltorio antiguo del CMS lo hacía de forma equivalente); las llamadas de `UpgradeModel` ya van dentro de bloques que capturan `Throwable`. Probar instalar y actualizar desde 3.4.2 con `compat6` apagado (no hecho: no hay Joomla).
- Pruebas: nuevo `tests/` (`php tests/run.php`; dependencias de prueba con `composer install --working-dir=tests`). `tests/01-filesystem.php` ejecuta las mismas operaciones contra joomla/filesystem 4.2.1 real.
- `build/verificar.php`: ya no exige identidad byte a byte con upstream; lista como "modificados deliberadamente" los archivos que difieren, y ejecuta `tests/run.php`.

## 0.2.0 — 2026-10-03 (Fase 2)
- Nuevo `docs/INFORME-FASE2-JOOMLA6.md`: análisis de solo lectura de compatibilidad con Joomla 5/6 y PHP 8.4 (verificado contra joomla-cms 5.4-dev y 6.1-dev), causa probable del aviso "no compatible", revisión de seguridad, requisitos oficiales y plan de cambios por versión.
- Sin cambios en `src/` ni en `upstream/`.

## 0.1.0 — 2026-10-03 (Fase 1)
- Fuente reorganizada en `src/` (componente, módulo, 10 plugins y paquete), con los `.ini` en `language/<tag>/` según los manifiestos. Archivos copiados de `upstream/3.4.2-instalado/` sin modificar su contenido.
- Nuevos `pkg_engage.sys.ini` (en-GB y es-ES) con `PKG_ENGAGE_XML_DESCRIPTION`.
- `build/build.php`: genera los ZIP de cada extensión y `pkg_engage-<versión>.zip` en `dist/`.
- `build/verificar.php`: comprobaciones de manifiestos, sintaxis PHP, identidad con upstream, XML y reproducibilidad.
- Añadido `.gitignore` (`dist/`).
- Sin cambios de código ni de manifiestos. Pendiente: URL de actualizaciones (Fase 4) e idiomas declarados que no existen (ver README).

## 0.0.1 — 2026-10-03 (Fase 0)
- Incorporada la referencia original Engage 3.4.2 en `upstream/3.4.2-instalado/`, sin modificar.
- Añadidos `LICENSE` (GPL v3), `README.md`, `NOTICE.md` y este `CHANGELOG.md`.
- Sin cambios de código.

## 0.0.2 — 2026-10-03
- `NOTICE.md`: enlaces a los perfiles GitHub de nikosdion y akeeba, licencias tal como constan en 3.4.2, nota sobre la fuente Akeeba-Products.woff.
- Sin cambios de código.
