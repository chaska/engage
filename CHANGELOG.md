# Registro de cambios del fork

## 0.4.0 — 2026-10-03 (Fase 3, paso 2: lista blanca de ordenación, S1)
- Qué: la columna y la dirección de `ORDER BY` del listado de comentarios pasan por una lista blanca. Nuevo `Helper/ListOrdering.php`; `CommentsModel::getListQuery` (punto único por el que pasan todas las consultas de lista, también `commentIDTreeSliceWithDepth` y `commentTreeSlice`) ya no usa el valor del estado tal cual; el controlador público solo acepta `c.created` o `c.id` de la petición y la dirección exacta `ASC`/`DESC` (insensible a mayúsculas, sin recortar espacios ni NUL); cualquier otra cosa da `c.created DESC`.
- Por qué: `?akengage_order=u.password` (visitante anónimo) llegaba a `quoteName()`, que no inyecta SQL pero acepta cualquier columna de las tablas unidas (`#__users`, artículos, categorías): oráculo de ordenación para deducir contraseñas/correos de los autores, o error SQL 500. La dirección en el modelo (`$db->escape`) tampoco estaba acotada (solo el controlador público la comprobaba): ahora se valida también en el modelo, que es lo que usan el panel de administración y los módulos.
- Causa raíz / mismo patrón: revisado todo `src/` (`->order(`, `list.ordering`, `list.direction`): solo hay un punto donde la ordenación venía de fuera; los demás `order()` son fijos. El listado del panel (`filter_fields` histórico: `id`, `name`, `created`...) se resuelve ahora a la tabla `c` (antes una columna sin calificar como `id` daba "ambiguous column"); el listado del panel sigue ordenando por `user_name`, `c.id`, `c.created`, `c.enabled` igual que antes.
- Archivos: `src/component/backend/src/Helper/ListOrdering.php` (nuevo), `src/component/backend/src/Model/CommentsModel.php`, `src/component/frontend/src/Controller/CommentsController.php`.
- Impacto colateral a revisar: un visitante que enviara a mano `akengage_order` distinto de `c.created`/`c.id` ahora ve el orden por fecha (no hay interfaz pública que lo ofrezca; el plugin solo fija la dirección). Observado y NO tocado: el filtro de fechas del modelo usa `c.created_on`, columna que no existe (la tabla tiene `created`); no es del alcance de esta versión, queda anotado para decidir.
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
