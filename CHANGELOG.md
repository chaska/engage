# Registro de cambios del fork

## 0.4.3 — 2026-10-03 (Fase 3, paso 3c: enlaces firmados atados al comentario, S5)
- Qué:
  1. `SignedURL::getToken()` firma ahora también el ID del comentario y usa HMAC-SHA-256 (antes SHA-1 sin `cid`). `verifyToken()` recibe el `cid` (parámetro nuevo al final; si falta o es <= 0 el token no vale). Se corrige además que `verifyToken` pasaba `''` a un parámetro `int` cuando faltaba `expires` (TypeError).
  2. `ControllerFrontendCommentsTrait::checkToken()`: el enlace firmado solo se acepta para UN comentario. Antes se verificaba el primer `cid[]`, pero los controladores actúan sobre todos los `cid[]` de la petición (y `unsubscribe` prefiere `id` sobre `cid`), de modo que `&cid[]=otro` o `&id=otro` añadidos a un enlace válido quedaban autorizados. Ahora cualquier ID distinto o adicional (en GET o en la petición mezclada, valores no numéricos o anidados incluidos) descalifica el enlace y se pasa a la comprobación del token de formulario (CSRF), que para un GET falla.
  3. `Email.php`: los enlaces PUBLISH, UNPUBLISH, DELETE, POSSIBLESPAM, SPAM y UNSPAM solo se firman para destinatarios con `core.edit.state` (y `core.delete` para DELETE) en `com_engage`; los demás destinatarios reciben el enlace público del comentario en su lugar. El enlace de baja (UNSUBSCRIBE) se firma para todos, como antes.
- Por qué: el enlace de un correo para un comentario valía 24 h para cualquier otro comentario del mismo artículo y sustituía al token CSRF en acciones que cambian estado por GET; además se enviaba a destinatarios que no son moderadores.
- Archivos: `src/component/frontend/src/Helper/SignedURL.php`, `src/component/frontend/src/Mixin/ControllerFrontendCommentsTrait.php`, `src/plugins/engage/email/src/Extension/Email.php`.
- Impacto colateral a revisar: los enlaces ya enviados por correo (válidos 24 h) dejan de funcionar tras actualizar; el moderador pasará por la pantalla de inicio de sesión/token. Los permisos se evalúan con `authorise(..., 'com_engage')` (el mismo nivel que usan los modelos). Plantillas de correo personalizadas que muestran un botón "Publicar/Eliminar/Spam" a destinatarios sin permiso verán ahora el enlace del comentario. No probado en Joomla real ni con un correo real.
- Corrección de herramientas de prueba: el stub de `Uri` se movió a la prueba 05 (las demás pruebas no cargan joomla/uri).
- Pruebas: `tests/05-enlaces-firmados.php` (clase `SignedURL` y trait reales con Uri/Crypt del framework o stubs): token del comentario 5 en el 6, `cid[]` y `id` adicionales, cid anidado o no numérico, token del formato antiguo, tarea/correo/asset/caducidad/clave alterados, y el caso legítimo.

## 0.4.2 — 2026-10-03 (Fase 3, paso 3b: mapeo de filtros de Joomla a HTML Purifier, S4, y mayúsculas del autoload, S7)
- Qué (todo en `HtmlFilter.php`):
  1. S7: `HTMLPUrifier.auto.php` / `HTMLPUrifier.includes.php` -> `HTMLPurifier.auto.php` / `HTMLPurifier.includes.php`. Con las opciones de carga `auto` y `all` (Configuración > Avanzado) se producía un `require_once` fatal en sistemas de archivos que distinguen mayúsculas (Linux).
  2. S4 (solo con "usar la configuración de filtros de texto de Joomla" activada): la lista blanca recibía un booleano (`$whiteList`) en vez de las etiquetas, lo que daba `TypeError` en PHP 8; los atributos permitidos se calculaban a partir de las etiquetas; los atributos prohibidos de la lista negra se pasaban como si fueran etiquetas; con "lista negra personalizada" los atributos se guardaban en la lista de etiquetas; y en la lista negra por defecto la lista de atributos pisaba la de etiquetas. Ahora cada lista va a su sitio.
  3. Lista negra por defecto: la lista efectiva es la de Joomla por defecto + la configurada - lo explícitamente permitido (igual que `ComponentHelper::filterText`); antes las etiquetas configuradas se ignoraban salvo en el caso de la lista blanca. Es más restrictivo, no menos.
  4. Las listas se pasan a HTML Purifier con `array_values` (`array_unique`/`array_diff` dejan huecos en los índices y HTML Purifier trata esos arrays como tabla de búsqueda, lo que provocaba un aviso/valor inválido).
- Por qué / causa raíz: la ruta de configuración "Joomla" de HTML Purifier nunca se probó; los errores eran de nombres de variable copiados entre bloques. Revisados los demás sitios que construyen listas de este tipo (`getHTMLPurifier`, `getJoomlaFilterSettings`): no hay más. El panel (`HtmlView`) y el correo (`Email.php`) construyen su propio purificador con lista fija y no se ven afectados.
- Archivo: `src/component/backend/src/Helper/HtmlFilter.php`.
- Impacto colateral a revisar: si había una instalación con "usar los filtros de Joomla" activado, antes (con lista blanca de Joomla) fallaba con error; con lista negra las etiquetas/atributos configurados ahora también se eliminan. Por defecto la opción está desactivada y no cambia nada.
- Pruebas: `tests/04-htmlfilter-mapeo.php` (HTML Purifier real; stubs de Joomla con la forma de joomla/filter 4.x): carga en modos composer/auto/all en procesos aparte, y mapeo CBL, BL+WL y WL (también con duplicados y sin atributos) comprobando listas y HTML resultante. Con el código anterior la prueba da 12 fallos/fatales; con el nuevo pasa.

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
