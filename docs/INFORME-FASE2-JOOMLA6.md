# Informe Fase 2 — Compatibilidad con Joomla 5/6 y PHP 8.4, y revisión de seguridad

Fecha: 2026-10-03. Objetivo: Engage 3.4.2 (fork comunitario) sobre Joomla 5 y 6, PHP 8.4. Fase de solo lectura: no se ha modificado nada de `src/` ni de `upstream/`.

## 0. Cómo se ha verificado y qué NO se ha podido verificar

**Verificado contra código real** (clonado con `git clone --depth 1`):

- `joomla/joomla-cms` rama `6.1-dev` (Version.php: 6.1.5-dev) y rama `5.4-dev`. Las ramas `6.0-dev` y `5.x` anteriores ya no existen; existen los tags 6.0.x y 6.1.x, pero no se han clonado (la API revisada es la misma). Ruta de trabajo: `j6/…` y `j5/…` en el scratchpad; en el informe las rutas se dan relativas a la raíz de joomla-cms.
- `joomla-framework/{database 4.0.0, event 4.0.0, filesystem 4.2.1, utilities 4.0.0, registry 4.0.1}` (versiones que fija `composer.lock` de Joomla 6.1) y `ezyang/htmlpurifier` v4.19.0 (`NEWS`, diff con la 4.17.0 incluida).
- Todas las clases `use Joomla\…` del código propio (135 imports distintos) comprobadas con un script contra J5 y J6: existen todas **salvo** `Joomla\CMS\Filesystem\{File,Folder,Path}` en J6.
- Sintaxis y firmas con PHP 8.3.6 (el entorno).

**No verificado** (marcado "NV" en el texto):

- Contenido real de `https://cdn.akeeba.com/updates/pkgengage.xml`: el proxy del entorno devuelve 403 a `cdn.akeeba.com` (política del sandbox, no del servidor). No se sabe si la URL responde, da 404, o sirve un XML sin `targetplatform` para J6.
- Ejecución real bajo PHP 8.4 (solo hay 8.3.6) y una instalación real en Joomla 5/6: no se ha instalado nada. Los avisos de PHP 8.4 salen de análisis estático (script con `token_get_all`) y de la lectura del NEWS de HTMLPurifier.
- Librerías `vendor/` de Joomla (symfony/console 7.4, joomla/console, joomla/application): `composer.lock` las fija, pero el clon no incluye `vendor/`; lo relativo a ellas es por `composer.lock`, no por lectura de su código.
- Comportamiento exacto en Joomla 4.x (el código declara `minimumJoomla 4.3.0`): se razona por el código de J5; marcado NV donde influye.
- Fechas de fin de vida de Joomla 5.x/6.x: no están en el repositorio; no se afirman.

---

## 1. Por qué Joomla 6 lo marca "no compatible" (causa más probable)

Dónde lo decide Joomla 6: **no** en el Gestor de extensiones (`administrator/components/com_installer` no tiene ninguna comprobación de compatibilidad) sino en la **comprobación previa a la actualización** de `com_joomlaupdate`:

- `administrator/components/com_joomlaupdate/src/Model/UpdateModel.php:1701` `getNonCoreExtensions()` lista las extensiones con `package_id = 0` y que no son del núcleo. Los hijos de un paquete **no** se listan; se lista el paquete `pkg_engage` (y los plugins no son evaluados por separado).
- `UpdateModel.php:1809` `fetchCompatibility()`: busca los *update sites* de esa extensión (`getUpdateSitesInfo`, :1862). Para cada uno descarga el XML (`checkCompatibility`, :1980 → `Update::loadFromXml`, `libraries/src/Updater/Update.php:657`) y se queda con las versiones cuyo `<targetplatform name="joomla" version="…">` casa por regex con la versión de destino (`Update.php:384-386`).
- Resultado: estado 1 (compatible) solo si el XML responde 200, es válido y trae una versión con `targetplatform` que casa con J6 (`^6…`). Si no hay update site → estado 2 (sin etiqueta) → "sin información de compatibilidad"; si el XML da error/404 → estado 3 → grupo 4 "Falló la comprobación" (`Controller/UpdateController.php:519-521`); si el XML carga pero ninguna versión casa → estado 0/1 sin `compatibleVersion` → "No Compatibility Information" (`build/media_source/com_joomlaupdate/js/default.es6.js:449-455`).
- El atributo `version="4.0.0"` del `<extension>` **no se usa** para nada de esto (`libraries/src/Installer/Installer.php:2357` lee el elemento `<version>`, no el atributo).

**Causa más probable** (alta confianza en el mecanismo, NV en el dato): el único update server (`src/package/pkg_engage.xml:56`, `https://cdn.akeeba.com/updates/pkgengage.xml`) ya no sirve una entrada con `targetplatform` de J6 (o ya no existe). Es lo único que Joomla consulta. Arreglo: Fase 4 (servidor de actualizaciones propio con `<targetplatform name="joomla" version="((5\.[0-9])|(6\.[0-9]))"/>` y `<php_minimum>`; el parser de restricciones es `libraries/src/Updater/ConstraintChecker.php`: exige `targetplatform` (:63), comprueba `channel`, `stability`, `php_minimum` y `supported_databases`).

Causa secundaria **real y probada en el código** (no la del cartel, pero rompe en J6): ver §2.1 (clases `Joomla\CMS\Filesystem\*` eliminadas en J6).

Nota sobre el aviso "No compatible" relacionado con PHP: `UpdateModel.php:1583` compara `PHP_VERSION` con el `php_minimum` del *núcleo* de destino (J6: `index.php:13` `JOOMLA_MINIMUM_PHP = 8.3.0`), no con la extensión.

## 2. Cambios necesarios para Joomla 5/6 y PHP 8.4, ordenados por riesgo

Leyenda: Riesgo = alto/medio/bajo; "Rompe J4/5" = si el arreglo propuesto deja de funcionar en J4.3/J5.

### 2.1 ALTO — `Joomla\CMS\Filesystem\{File,Folder,Path}` no existe en J6

- Uso: `src/component/backend/src/Model/UpgradeModel.php:13-14` (`File::delete` :561, `Folder::delete` :592/:602/:620, `File::write` :611/:1109, `Folder::move` :634/:635), `Model/UpdatesModel.php:13` (`File::write` :584), `Mixin/ViewLoadAnyTemplateTrait.php:15` (`Path::find` :196, :202; este se usa en **cada** carga de plantilla de vistas del componente).
- Evidencia: `libraries/src/Filesystem/` no existe en 6.1-dev; solo quedan alias vía el plugin `plugins/behaviour/compat6` (`src/classmap/classmap.php:486-493`, `classes/Filesystem/*`). Ese plugin está deshabilitado por defecto en instalaciones nuevas de J6 (la comprobación de J6 en `UpdateModel.php:1448` lo trata como opción a activar). En J5 sí existen (obsoletas).
- Efecto: con `compat6` apagado, `Class "Joomla\CMS\Filesystem\Path" not found` (fatal) al pintar vistas del componente y en la actualización (`UpgradeModel`, que se ejecuta en `postflight`).
- Arreglo mínimo: cambiar los `use` a `Joomla\Filesystem\File`, `Joomla\Filesystem\Folder`, `Joomla\Filesystem\Path` (framework joomla/filesystem; mismas firmas: `File::delete/write`, `Folder::delete/move`, `Path::find` verificados en `joomla-framework/filesystem` 4.2.1 `src/File.php:171,263`, `src/Folder.php:213,285`, `src/Path.php:276`). Esas clases existen en J4.3, J5 y J6 (J4/J5 NV el detalle de versión del framework, pero `Joomla\Filesystem\*` está en J4.0+). Rompe J4/5: no (NV J4).
- Una sola versión del fork: "0.3.0 — Filesystem".

### 2.2 MEDIO — Plugins: constructor `__construct(&$subject, $config)` (CMSPlugin legado)

- `plugins/user/engage/…/Engage.php:74`, `content/engage/…:115`, `console/engage/…:49` (más los `services/provider.php` de los 10 plugins que hacen `new Engage($subject, $config)`, p. ej. `plugins/user/engage/services/provider.php:36-41`).
- En J6 sigue funcionando pero con `E_USER_DEPRECATED`: `libraries/src/Plugin/CMSPlugin.php` ("Passing an instance of DispatcherInterface … will not be supported in 7.0"). En 7.0 `CMSPlugin` deja de implementar `DispatcherAwareInterface`.
- Arreglo mínimo: aplazable a J7; si se quiere ya: `parent::__construct($config)` y `setDispatcher($subject)` solo cuando `$subject` sea `DispatcherInterface`. Rompe J4: sí (J4 exige `$subject`) → no tocar mientras se declare J4. No urgente.

### 2.3 MEDIO — `$app->triggerEvent(...)` obsoleto

- `frontend/src/Controller/CommentsController.php:218`, `frontend/src/Model/CommentModel.php:311`, `backend/src/Model/CommentModel.php:142,146,157`.
- J6: `libraries/src/Application/EventAware.php:87` `@deprecated 4.0 will be removed in 7.0` (sigue funcionando en 6.x).
- Arreglo mínimo (opcional ahora): el propio `RunPluginsTrait::triggerPluginEventStatic` ya existe en el código (`backend/src/Mixin/RunPluginsTrait.php:165`); reutilizarlo. Rompe J4/5: no. Ojo: ahí hay un bug latente en `triggerPluginEvent` (:~255) que pasa `$dispatcher` (null) en lugar de `$app` a la función estática.

### 2.4 MEDIO — `Factory::getUser()/getMailer()/getLanguage()` estáticos

- `Factory::getUser`: `frontend/src/View/Comments/HtmlView.php:190`, `backend/src/Service/Html/Engage.php:224`, `backend/src/Helper/HtmlFilter.php:300`. `Factory::getMailer`: `backend/src/Helper/TemplateEmails.php:306`, `plugins/engage/email/src/Extension/Email.php:344` (ya con rama `MailerFactoryInterface`). `Factory::getLanguage`: `Mixin/ViewLoadAnyTemplateTrait.php:179`.
- J6: siguen existiendo, `@deprecated … will be removed in 7.0` (`libraries/src/Factory.php:178-557`; en J5 decía 6.0). `getDbo`, `getSession`, `getDocument`, `getCache` igual. El código propio ya usa `UserFetcher` y `Factory::getContainer()` en la mayoría de sitios.
- Arreglo mínimo (opcional): `Factory::getApplication()->getIdentity()`, `->getLanguage()`, `MailerFactoryInterface`. Rompe J4/5: `getIdentity`/`getLanguage()` existen desde 4.2 (OK). Hacer en una versión aparte, bajo riesgo.

### 2.5 MEDIO — Avisos de PHP 8.4: parámetros implícitamente nullable

Análisis con tokenizador de todo `src/` (incluido `vendor/`); 11 casos, todos de la forma `Tipo $x = null`:

- `frontend/src/Exceptions/BlatantSpam.php:23` (`Throwable $previous`), `frontend/src/Form/Rule/TosacceptRule.php:33` (`Registry $input`, `Form $form`), `backend/src/Controller/CommentsController.php:49`, `backend/src/Model/CommentsModel.php:51`, `backend/src/Model/UpdatesModel.php:38` (`MVCFactoryInterface $factory`), `backend/src/Helper/TemplateEmails.php:283` (`User $user`, `string $forceLanguage`, `Mail $mailer`), `backend/src/Table/AbstractTable.php:32` y `Table/CommentTable.php:67` (`DispatcherInterface $dispatcher`).
- PHP 8.4: `Deprecated: Implicitly marking parameter … as nullable is deprecated`. No es fatal; ensucia los logs.
- Arreglo mínimo: anteponer `?` al tipo. Rompe J4/5: no (sintaxis PHP 7.1+). Atención: `TosacceptRule::test` hereda la firma de `FormRule::test` (J6 `libraries/src/Form/FormRule.php`: `?Registry $input = null, ?Form $form = null`) → el `?` es incluso necesario para ser compatible.
- No hay más deprecaciones 8.4 en el código propio detectadas por análisis estático (no hay `E_STRICT`, ni `trigger_error(E_USER_ERROR)`, ni `session.*`, ni `mb_`/`strtolower` obsoletos). NV: ejecución real. Además: `ReflectionProperty::setAccessible()` (`ComponentParameters.php:95,122`; `CliRouting.php:38,66,85`) es un no-op desde PHP 8.1 y estará deprecado en PHP 8.5: eliminar esas líneas (bajo riesgo).

### 2.6 MEDIO/BAJO — `ComponentParameters.php` (`ReflectionClass` sobre `ComponentHelper`/`PluginHelper`)

- `backend/src/Service/ComponentParameters.php:93-116` (componente) y `:119-149` (plugin).
- Verificado en J5 y J6: `ComponentHelper::$components` es `protected static $components = []` (`libraries/src/Component/ComponentHelper.php:42`) y `PluginHelper::$plugins` es `protected static $plugins = null` (`libraries/src/Plugin/PluginHelper.php:35/36`). Con PHP 8.3 comprobé que `getStaticPropertyValue/setStaticPropertyValue` funcionan con propiedades protegidas. O sea: **sigue funcionando en J5/J6**, pero depende de internos no públicos.
- Fallos latentes: (a) `$components[$criteria['element']]->params = $params;` (:106) lanza `Error` si el componente no está en la caché estática (null); (b) `foreach ($plugins as $plugin)` (:133) sobre `null` → warning si no se cargaron plugins; (c) el `setAccessible` es innecesario.
- Arreglo mínimo: proteger con `isset(...)`/`is_array(...)` y eliminar `setAccessible`; alternativa mejor: `ComponentHelper::getComponent($name)->params = $params` (la caché devuelve un objeto por referencia) y dejar de usar reflexión para plugins (basta limpiar la caché `_system`, ya hecha en :83). Rompe J4/5: no.

### 2.7 BAJO — Manifiestos y `script.engage.php`

- `src/package/script.engage.php:29,31`: `$minimumPhp = '7.4.0'`, `$minimumJoomla = '4.3.0'`. `InstallerScript::preflight` de J6 (`libraries/src/Installer/InstallerScript.php:118-126`) compara con `PHP_VERSION`/`JVERSION`. El script **sobrescribe** `preflight` (`:126`) y no llama a `parent::preflight`, así que esos dos mínimos hoy **no se aplican**; solo se comprueba `JVERSION <= 3.999.999` (:137).
- `src/component/backend/src/Dispatcher/Dispatcher.php:58`: `$minPHPVersion = '7.4.0'` (no aplicado a >8).
- Atributo `version="4.0.0"`/`"3.9.0"` en `<extension>` (12 manifiestos): ignorado por Joomla 5/6 (§1). Se puede dejar o normalizar a `5.0` sin efecto.
- `JVERSION` y `JNamespacePsr4Map` siguen existiendo en J6 (`libraries/bootstrap.php:58`, `libraries/namespacemap.php:25`; `create()` y `load()` públicos): el `postflight` es compatible.
- Mínimos propuestos para el fork (A): ver §4.

### 2.8 BAJO — Eventos y firmas de plugins (verificados, funcionan)

- Todos los plugins con `SubscriberInterface` y `Event $event` (`Joomla\Event\Event`). En J6 los eventos reales son subclases tipadas (`libraries/src/Event/CoreEventAware.php` mapea `onContentAfterDisplay → Content\AfterDisplayEvent`, `onUserAfterDelete → User\AfterDeleteEvent`, `onContentBeforeSave → Model\BeforeSaveEvent`, etc.) que extienden `AbstractEvent` → `Joomla\Event\Event`; el tipo `Event` sigue valiendo.
- El patrón `[$a,$b] = array_values($event->getArguments())` y `$event->setArgument('result', array_merge($result, [x]))` sigue funcionando: `ReshapeArgumentsAware` mantiene el orden clásico y `ResultAware::setResult()` (`libraries/src/Event/Result/ResultAware.php`) toma el último elemento del array como el nuevo resultado. Es camino legado soportado hasta J7 (`AbstractEvent.php:202` avisa de acceso numérico, pero no se usa `setArgument(0…)`).
- Riesgo residual: `onUserAfterDelete`/`onUserBeforeDelete` desestructuran 3 valores (`user/engage/…Engage.php:112-113`); si un evento llegara con menos argumentos habría `Undefined array key`. En J6 `User.php:842` pasa los 3. Arreglo (opcional, bajo): usar `$event->getArgument('subject')` etc. con fallback. Rompe J4: ver cuidado; mejor dejar.
- `PrepareDataEvent` de J6 admite `$data` array u objeto (`object|array`); `onContentPrepareData` (`content/engage/…:445`) asume objeto (`isset($data->{$key})`): si llega un array, simplemente sale (sin error). Bajo.
- `getsubscribedevents` en minúsculas (`user/engage/…:98`): funciona (PHP no distingue mayúsculas en métodos) pero es feo; cambiar a `getSubscribedEvents`.

### 2.9 BAJO — Otros puntos comprobados

- `BaseDatabaseModel`, `AdminModel`, `FormController`, `AdminController`, `ListModel`, `HtmlView`, `Table`, `MailTemplate`, `HTMLHelper`, `ToolbarHelper`, `Form`, `FormRule`, `HttpFactory`, `Crypt`, etc.: todas las clases existen en J5 y J6 (script de comprobación de imports). `Joomla\CMS\Table\Table::__construct` en J6 es `($table, $key, DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)` (`libraries/src/Table/Table.php:177`), compatible con `AbstractTable`.
- `HtmlView` expone `_path`, `_template`, `_output` y `_createFileName()` (`libraries/src/MVC/View/HtmlView.php:75-91,552`): `ViewLoadAnyTemplateTrait` (copia de `loadTemplate`) sigue funcionando.
- Base de datos: Joomla 6 usa joomla/database 4.0 (`composer.lock`): se quitó `quoteNameStr`, `LimitableInterface`, `castAsChar`. El código usa `createQuery()` con respaldo `getQuery(true)` y `bind()`; ok. `setQuery`, `quote`, `qn`, `loadColumn` siguen. NV: uso de `getQuery(true)` en J6 (solo es respaldo).
- Plugin de consola: `$this->getApplication()->addCommand($command)` (`plugins/console/engage/…`); dentro de `try/catch(Throwable)`. NV con symfony/console 7.4 (sin vendor).
- `RunPluginsTrait` usa `CoreEventAware` y sus propiedades privadas `$eventNameToConcreteClass`: existe con ese nombre en J5 y J6 (`libraries/src/Event/CoreEventAware.php:34`).
- `ControllerFrontendCommentsTrait::checkToken`/`CommentsController::main` usan `debug_backtrace` para limitar `main` a llamadas desde el plugin (frágil, ver §3).

### 2.10 Idiomas declarados y ausentes (punto 6)

- Resultado del análisis de manifiestos: `en-GB` 26 declarados/26 presentes; `es-ES` 10/10; **`de-DE` 26, `fr-FR` 25, `nl-NL` 25, `el-GR` 21 declarados y ausentes = 97** (96 en extensiones + `de-DE/pkg_engage.sys.ini` del paquete). Además `es-ES/pkg_engage.sys.ini` existe pero **no está declarado** en `pkg_engage.xml`.
- Qué hace Joomla: `libraries/src/Installer/Installer.php:1561` `parseLanguages()`: para `<language tag="xx-XX">` **ignora el archivo si la carpeta `language/xx-XX` del núcleo no existe** (`:1621-1624`, `continue`). Pero si esa carpeta **existe** (sitio con alemán, francés, neerlandés o griego instalado), `copyFiles()` (`:1826-1835`) devuelve error `JLIB_INSTALLER_ERROR_NO_FILE` y **falla la instalación/actualización de esa extensión**. Para un sitio solo con en-GB y es-ES no hay efecto (por eso "funciona"), pero es una bomba de relojería.
- Arreglo mínimo: quitar del XML las referencias a los 4 idiomas ausentes (o aportar los .ini) y declarar `es-ES/pkg_engage.sys.ini`. Rompe J4/5: no. Hacer en una versión aparte.

### 2.11 Cabeceras de copyright (punto 7)

- Todas dicen `Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd` y `GNU General Public License version 3, or later`. Las licencias y atribuciones deben **conservarse**; para cambios del fork se puede añadir una línea `Modificaciones (c) 2026 <colaboradores del fork>` sin borrar la original. No es un bloqueo técnico. `pkg_engage.xml` `<copyright>`/`<author>` seguirán siendo los de Akeeba (autor original) hasta decidir el empaquetado del fork (`<packager>`).

## 3. Seguridad

Puntos verificados correctos: acceso directo (`defined('_JEXEC') or die`) en los PHP; `.htaccess`/`web.config` de denegación en 13 carpetas (+4 `index.html`), con sintaxis para Apache 2.2/2.4 e IIS; consultas con `bind()`/`quote`/`quoteName`; salida con `htmlspecialchars`/`escape` en las plantillas (salvo lo señalado); formularios de escritura con token; `return` URL validada con `Uri::isInternal` (`backend/src/Mixin/ControllerReturnURLTrait.php`); verificación del token firmado en tiempo constante (`SignedURL::verifyToken` con `Crypt::timingSafeCompare`); no hay subida de ficheros de usuarios; correos con `strip_tags` de los campos de usuario y cuerpo purificado (`Email.php:355-370`).

Hallazgos (ordenados por riesgo):

### S1 — MEDIO/ALTO — Ordenación arbitraria (`ORDER BY` controlado por el visitante) → fuga ciega de datos de `#__users`

- `frontend/src/Controller/CommentsController.php:362` `akengage_order` se lee **sin validar** y pasa a `list.ordering`; `backend/src/Model/CommentsModel.php:619-634` hace `$db->quoteName($orderCol)`. `quoteName` evita inyectar SQL, pero acepta **cualquier columna de las tablas unidas**, incluida `u.password`, `u.email`, `u.params` (join con `#__users u` en :395-405).
- Un visitante anónimo, con `?akengage_order=u.password` en cualquier artículo con comentarios (la petición se reenvía al componente: `plugins/content/engage/…:1171` `new Input($app->input->getArray())`), obtiene el orden de los comentarios según el hash de contraseña/correo de sus autores: un oráculo de ordenación para sacar información por inferencia (lento pero real). Además columnas inexistentes producen error SQL → 500.
- Arreglo mínimo: lista blanca (`c.id`, `c.created`, `c.name`, `user_name`, `c.enabled`, …, las del `filter_fields` del modelo) antes de `setState('list.ordering', …)` o en `getListQuery`; si no está, `c.created`. Rompe J4/5: no.

### S2 — MEDIO — Salida sin purificar para ciertos espectadores; `none` del filtro de Joomla se salta HTMLPurifier también en modo `strict`

- `backend/src/Helper/HtmlFilter.php:186-189`: si el filtro de Joomla del **espectador** (`Factory::getUser()`, :300) es "Sin filtrar" (el grupo Super Usuarios lo es por defecto), `purify()` devuelve el texto tal cual, incluso con `filter_mode = strict`. El filtrado se hace **al mostrar** (`Service/Html/Engage.php:171`), no al guardar. Un Super Usuario/administrador que ve los comentarios en frontend o en la lista de comentarios del panel ve el HTML que dejó pasar el filtro **de guardado** (`comment_new.xml:50`, `comment.xml:114`: `ComponentHelper::filterText` con los permisos del *autor*, normalmente lista negra por defecto), no el de HTMLPurifier. Es el escenario típico de XSS almacenado contra administradores.
- Parcialmente mitigado: en el backend `tmpl/comments/default.php:100` aplica `$this->purifier->purify(...)` propio; el frontend y el correo (`Email.php`) también. El hueco queda en frontend con espectador sin filtro y en `mod_engage_latest` (S3).
- Arreglo mínimo: en `strict` (y `htmlpurifier`) no devolver el texto crudo cuando `filterType == 'none'` (purificar siempre al mostrar). Rompe J4/5: no (cambia el comportamiento para super usuarios: documentar).

### S3 — MEDIO — `mod_engage_latest` imprime el comentario sin filtrar al mostrar

- `src/modules/site/engage_latest/tmpl/default.php:101` `<?= $comment->body ?>` (si no hay extracto) y `Service/Html/Engage.php:110` `textExcerpt` devuelve `$text` original cuando es corto (sin `strip_tags`/filtro). No pasa por `processCommentTextForDisplay` ni HtmlPurifier, sin `rel="nofollow"`. Depende solo del filtro de guardado de Joomla.
- `:92` `$comment->user_name` entra en `Text::sprintf` con una cadena que contiene HTML (`MOD_ENGAGE_LATEST_LBL_COMMENTED_ON="%s commented on <a href=…>"`) sin escapar. El campo `name` no tiene `filter` en `comment_new.xml:27`; Joomla aplica el filtro por defecto (`FormField.php:1179` → `InputFilter::clean($value, '')`, elimina etiquetas), así que el riesgo es bajo, pero conviene escapar.
- Arreglo mínimo: usar `HTMLHelper::_('engage.processCommentTextForDisplay', …)` + `textExcerpt` sobre texto purificado y `htmlspecialchars($comment->user_name)`. Rompe J4/5: no.

### S4 — MEDIO/BAJO — Errores en el mapeo de filtros de Joomla a HTMLPurifier (opción `htmlpurifier_config_joomla`)

- `HtmlFilter.php:~451` `blacklistTags` recibe `$customListAttributes`; `~469-473` recibe `array_diff($blackListAttributes, …)`; `~485` `whitelistTags` recibe `$whiteList` (un **bool**, no `$whiteListTags`) → `array_map` en :263 recibe un bool: `TypeError` en PHP 8 y lista blanca vacía. Solo aplica si se activa la opción de usar la configuración de Joomla.
- Arreglo mínimo: usar `$customListTags`/`$whiteListTags` y los arrays correctos. Rompe J4/5: no.

### S5 — MEDIO/BAJO — Enlaces firmados (`SignedURL`) no atan el identificador del comentario

- `frontend/src/Helper/SignedURL.php:getToken` firma `task + email + asset_id + expires` con HMAC-SHA1 (clave `secret` de Joomla) pero **no el `cid`**; `ControllerFrontendCommentsTrait::checkToken` (:39-77) acepta el token para cualquier `cid[]`/`id` del mismo artículo durante 24 h, y lo acepta **en lugar** del token de formulario (CSRF) para acciones que modifican estado por GET (`publish/unpublish/delete/possiblespam/reportspam/reportham/unsubscribe`, `Email.php:446-452`). El ACL sigue aplicándose en el modelo (`CommentModel::canEditState/canDelete`), así que no hay escalada sin sesión autorizada, pero un moderador con sesión abierta que siga un enlace de otra persona del mismo artículo actúa sobre comentarios ajenos al del correo, y el correo da la misma firma a destinatarios que no son moderadores.
- Arreglo mínimo: incluir `cid` en la firma, usar `hash_hmac('sha256', …)`, y generar PUBLISH/DELETE/SPAM solo para destinatarios con `core.edit.state`/`core.delete`. Cambia el formato de los enlaces ya enviados (caducan a 24 h): rompe enlaces pendientes, no J4/5.

### S6 — BAJO — IP del comentarista tomada de `IpHelper::getIp()`

- `frontend/src/Model/CommentModel.php:321`. En joomla/utilities, `IpHelper::getIp()` puede tomar `X-Forwarded-For`/`Client-IP` si las "ip overrides" están permitidas (NV el valor por defecto en `joomla-framework/utilities` 4.0.0; no se ha verificado). Un valor falsificable ensucia el registro de spam y lo que se envía a Akismet. Arreglo: comprobar `IpHelper::setAllowIpOverrides(false)` o usar `REMOTE_ADDR` cuando no hay proxy de confianza.

### S7 — BAJO — `includeHTMLPurifier`: nombre de fichero con mayúsculas erróneas

- `HtmlFilter.php:104,108` `HTMLPUrifier.auto.php` / `HTMLPUrifier.includes.php`; los ficheros se llaman `HTMLPurifier.auto.php` y `HTMLPurifier.includes.php`. En sistemas de archivos sensibles a mayúsculas (Linux) las opciones `auto` y `all` (`config.xml:373`) provocan `require_once` fatal. Arreglo: corregir mayúsculas. Rompe J4/5: no.

### S8 — BAJO — Información en errores / depuración

- `plugins/content/engage/…:1208-1224` con `JDEBUG` activo imprime mensaje, fichero y traza en la página pública; `tmpl/common/errorhandler.php:53,94,147` imprime `$title`, versión de Joomla/PHP y SO sin escapar `$title`. Solo con depuración o error no controlado; el handler oculta al público salvo `JDEBUG`/super usuario (:29). Arreglo: `htmlspecialchars` y nunca mostrar traza si no es super usuario.

### S9 — BAJO — Flujo "main" protegido con `debug_backtrace`

- `frontend/src/Controller/CommentsController.php:~330-352` autoriza `task=main` solo si el llamante es `Engage::renderComments` inspeccionando `debug_backtrace`. Funciona, pero es frágil (inlining no existe en PHP; cambia si se renombra el plugin). El `asset_id` llega por `$input` fijado por el plugin; la comprobación de acceso al artículo la hace el plugin (`$row->access`, `onContentAfterDisplay:339`) y `Meta::getAssetAccessMeta`. Arreglo: pasar un flag explícito en el `Input` (no controlable por la URL, porque el plugin lo fija tras copiar el request).

### S10 — Privacidad (RGPD) — información, sin cambio de código inmediato

- Se guardan IP, user agent, nombre y correo (`#__engage_comments`; `CommentModel.php:321`). Hay plugins de `privacy` y `datacompliance` (exportar/borrar): bien.
- Terceros: **Akismet** (`plugins/engage/akismet`) envía a Akismet IP, UA, correo, nombre y texto (`Akismet.php:276`); **Gravatar** (`plugins/engage/gravatar`) cambia el avatar por una URL de gravatar.com con el hash MD5 del correo (el navegador del visitante contacta con un tercero); el enlace de búsqueda de IP (`iplookup`, `Service/Html/Engage.php:55`) sale a un tercero solo al hacer clic. Debe constar en la política de privacidad/consentimiento. La fuente `Akeeba-Products.woff` es local (sin Google Fonts).
- Mejora opcional: opción para no guardar IP/UA, o anonimizar tras N días.

### S11 — Blindaje opcional

- Añadir `rel="noopener noreferrer"` a `target="_blank"` (`frontend/tmpl/comments/default_list.php:224`, `backend/tmpl/comments/default.php:185`); límite de tamaño y de frecuencia (el límite de longitud existe: `CommentTable.php:195-210`, con `mb_strlen(..., '8bit')`, es bytes); cabecera `Content-Security-Policy` es cosa del sitio.
- HTMLPurifier: configuración por defecto razonable (`HTML.Allowed` con `a[href]`, `img[src]`, sin `style` salvo `span[style]`, esquemas por defecto http/https/mailto/ftp…). `span[style]` y `img[src]` permiten imágenes remotas (tracking de lectores) y CSS limitado por `CSS.AllowedProperties` por defecto de HTMLPurifier. Opcional: `URI.DisableExternalResources`/restringir `img` o `URI.AllowedSchemes`, y `Attr.AllowedFrameTargets`. Subir a HTMLPurifier 4.19.1 (§5).

## 4. Requisitos oficiales de Joomla 6 y mínimos que debería declarar el fork (A)

Verificado en `joomla-cms` 6.1-dev:

- PHP mínimo de Joomla 6: **8.3.0** (`index.php:13` `JOOMLA_MINIMUM_PHP`; `composer.json` `"php": "^8.3.0"`). Joomla 5.4: PHP `^8.1.0` (`composer.json` de 5.4-dev, `index.php` equivalente).
- Bases de datos: MySQL **8.0.13**, PostgreSQL **12.0**, MariaDB **10.4** (`installation/src/Helper/DatabaseHelper.php:48,57` y `$dbMinimumMariaDb` :39). SQL Server/Azure no soportados para actualizar a J6 (`UpdateModel.php:1555-1575`). El esquema de Engage (`install.mysql.utf8.sql`) es solo MySQL/MariaDB (no hay postgresql); declararlo.
- Cómo se declara la compatibilidad: (1) manifiesto: no hay campo; Joomla ignora `version=` del `<extension>`; los mínimos van en el script (`InstallerScript::$minimumPhp/$minimumJoomla`, `libraries/src/Installer/InstallerScript.php:85,93,118-126`, solo si `preflight` llama al padre); (2) servidor de actualizaciones: `<targetplatform name="joomla" version="…"/>`, `<php_minimum>`, `<supported_databases type="mysql" minimum="…"/>` y `<channel>`/`<stability>` (`libraries/src/Updater/ConstraintChecker.php`); la comprobación previa a actualizar a J6 se basa solo en eso (§1).
- **Propuesta para el fork** (si se soporta J5 y J6): `minimumJoomla = '5.0.0'` (el fork no necesita J4; J4 es fin de vida, `component/backend/tmpl/common/joomla_eol.php` ya lo anuncia: 15-oct-2025), `minimumPhp = '8.1.0'` (mínimo de J5); `targetplatform` regex `((5\.[0-9])|(6\.[0-9]))`; `php_minimum 8.1`; `supported_databases` mysql 8.0.13/mariadb 10.4. Si se prefiere un único mínimo común y coherente con J6, `minimumPhp 8.3.0` solo para la rama que sirva a J6. Hacer que `preflight` llame a `parent::preflight($type, $parent)` para que se apliquen.
- Si se mantiene J4.3 como mínimo, no se puede usar nada de lo que sea solo-J5; la mayoría de los cambios de §2 son neutros para J4 (NV).

## 5. Desactualización desde 3.4.2 (2025-02-12) (C)

- **HTMLPurifier**: 4.17.0 incluida (nov 2023). Su `composer.json` solo declara PHP hasta `~8.3.0`; `platform_check.php` solo exige `>= 7.4` (no bloquea 8.4). 4.18.0 (2024-11-01): "Support PHP 8.4", arreglos varios; 4.19.0 (2025-10-17): "PHP 8.4 support" (trigger_error con E_USER_ERROR deprecado en 8.4 → se cambia), "PHP 8.5", arreglo de *catastrophic backtracking* en `Core.AggressivelyFixLt` (ReDoS), `preg_replace` con null; 4.19.1 es el último tag (NV su contenido). Con 4.17.0 en PHP 8.4 funciona en el camino normal pero emite deprecaciones en rutas de error. Recomendación: actualizar a 4.19.1 (`composer update ezyang/htmlpurifier` en `src/component/backend`; solo cambia `vendor/`). 61 ficheros de `library/` difieren de 4.17.0 (diff sin espacios).
- **Joomla**: salieron 5.3, 5.4 (rama LTS 5), 6.0 y 6.1 (ramas existentes: 5.4-dev, 6.1-dev, 6.2-dev, 6.3-dev). Joomla 4 fin de servicio el 15-oct-2025 (según `joomla_eol.php`).
- **PHP**: 8.4 (nov 2024) y 8.5 (nov 2025, según el NEWS de HTMLPurifier 4.19.0). Obsolescencias que se acercan: `ReflectionProperty::setAccessible` (8.5), implícito-nullable (8.4).
- **Symfony/Console 7.4** y joomla/event/database/filesystem **4.x** en J6: el código propio es compatible salvo `Filesystem` (§2.1).
- **Update server de Akeeba**: NV.
- Las cadenas `COM_ENGAGE_…` y emails no dependen de la versión.

## 6. Tabla resumen priorizada

| # | Prioridad | Asunto | Dónde | Rompe J4/5 | Versión propuesta |
|---|---|---|---|---|---|
| 1 | Alto | Update server inexistente/sin `targetplatform` J6 → "no compatible" | `pkg_engage.xml:56` | no | 0.7.0 (Fase 4) |
| 2 | Alto | `Joomla\CMS\Filesystem\*` no existe en J6 | `UpgradeModel.php:13-14`, `UpdatesModel.php:13`, `ViewLoadAnyTemplateTrait.php:15` | no | 0.3.0 |
| 3 | Alto (seguridad) | `ORDER BY` controlado por el visitante (S1) | `CommentsController.php:362`, `CommentsModel.php:619` | no | 0.4.0 |
| 4 | Medio (seg.) | Purificación al mostrar se salta con filtro `none` (S2), módulo sin filtrar (S3) | `HtmlFilter.php:186`, `engage_latest/tmpl/default.php:101` | no | 0.4.1 |
| 5 | Medio | Idiomas declarados ausentes (97) y `es-ES` pkg sin declarar | manifiestos | no | 0.5.0 |
| 6 | Medio | `preflight` sin mínimos efectivos; declarar mínimos | `script.engage.php:29-31,126-137` | no | 0.5.1 |
| 7 | Medio | Implícito-nullable PHP 8.4 (11 sitios), `setAccessible` | ver §2.5 | no | 0.6.0 |
| 8 | Medio/Bajo | `ComponentParameters` frágil (null) | `ComponentParameters.php:106,133` | no | 0.6.1 |
| 9 | Medio/Bajo | Bugs filtro Joomla→Purifier (S4), mayúsculas `HTMLPUrifier` (S7) | `HtmlFilter.php` | no | 0.4.2 |
| 10 | Medio/Bajo | Firma de enlaces sin `cid` (S5) | `SignedURL.php` | enlaces antiguos | 0.4.3 |
| 11 | Bajo | HTMLPurifier 4.17 → 4.19.1 | `vendor/` | no | 0.6.2 |
| 12 | Bajo | `triggerEvent`, `Factory::get*` obsoletos (retirada en J7) | §2.3, §2.4 | no | 0.8.0 |
| 13 | Bajo | Constructor `&$subject` (retirada en J7) | §2.2 | sí (J4) | solo al dejar J4 |
| 14 | Bajo | RGPD / blindaje opcional (S6, S8–S11) | varios | no | 0.8.x |

## 7. Orden de aplicación recomendado (un cambio por versión, Fase 3)

1. 0.3.0 — Filesystem (`Joomla\Filesystem\*`): desbloquea J6 real. Probar instalación/actualización con `compat6` apagado.
2. 0.4.0 — S1 lista blanca de orden (seguridad, bajo riesgo).
3. 0.4.1 — S2/S3 purificación al mostrar y módulo; 0.4.2 — S4/S7; 0.4.3 — S5 (firma).
4. 0.5.0 — idiomas (quitar referencias ausentes; declarar es-ES del paquete); 0.5.1 — mínimos efectivos en `preflight` y manifiestos.
5. 0.6.0 — PHP 8.4 (`?Tipo`), `setAccessible`; 0.6.1 — `ComponentParameters`; 0.6.2 — HTMLPurifier 4.19.1.
6. 0.7.0 — Fase 4: update server propio con `targetplatform` 5/6, `php_minimum`, `supported_databases`; cambio de `<updateservers>` y de autoría/empaquetado.
7. 0.8.x — deprecaciones para J7 y blindaje opcional.

Cada versión debe probarse como mínimo en J5.4 + PHP 8.3 y J6.1 + PHP 8.4 (instalar, actualizar desde 3.4.2, comentar como invitado y como registrado, moderar, desinstalar). Nada de esto se ha podido ejecutar en esta fase.
