# Resultados de las pruebas en un Joomla REAL

Fecha: 2026-10-03. Hasta ahora el fork solo se había probado con stubs (`tests/NN-*.php`). Este documento recoge lo que ocurre al instalar el paquete en un Joomla 6 de verdad dentro de un contenedor de pruebas y al ejecutar contra él las pruebas de [`PRUEBAS-SEGURIDAD-JOOMLA6.md`](PRUEBAS-SEGURIDAD-JOOMLA6.md) que se pueden automatizar. Nada de esto toca la web de producción: es un sitio local desechable (`127.0.0.1`), con BD, usuarios y contraseñas aleatorios que viven fuera del repositorio.

## 1. Entorno

| Elemento | Valor |
|---|---|
| Joomla | **6.1.4** Stable, paquete oficial `Joomla_6.1.4-Stable-Full_Package.zip` de GitHub Releases (SHA-256 `817d2fa37c7f8a7ecbd1d028d6be9362dc6e5fd693f9e07e7fdb9998431e7196`); instalado con `php installation/joomla.php install` |
| PHP | 8.3.6 (CLI y `php -S`), `error_reporting=-1`, todos los avisos a un registro propio |
| BD | MariaDB 10.11.14, `sql_mode` por defecto (`STRICT_TRANS_TABLES...`), tablas `utf8mb4` |
| Servidor web | `php -S` con un router propio (`tests/joomla-live/lib/router.php`); correo por un SMTP local de captura (`lib/smtp-sink.php`) |
| Paquete probado | `dist/pkg_engage-3.4.2.1.zip` generado por `php build/build.php` (hash final en la sección 7) |
| Original | `upstream/3.4.2-instalado` reempaquetado (sección 4); `upstream/` no se modifica |
| `compat6` | desactivado (valor por defecto de Joomla 6.1.4): nada depende de los alias de compatibilidad |

Reproducción: `tests/joomla-live/LEEME.md`. Los números de más abajo salen de esos scripts.

## 2. Instalación del paquete 3.4.2.1 (Joomla 6.1.4 limpio)

`php cli/joomla.php extension:install --path=pkg_engage-3.4.2.1.zip` -> `[OK] Extension installed successfully.` Sin errores ni avisos de Joomla ni de PHP.

- Tablas creadas: `jos_engage_comments`, `jos_engage_unsubscribe` (InnoDB, `utf8mb4`). `#__schemas`: versión `3.0.2-20220107` (la última migración SQL del componente).
- Extensiones registradas (todas con versión 3.4.2.1): paquete `pkg_engage`, componente `com_engage`, módulo `mod_engage_latest`, y plugins `content/engage`, `console/engage`, `user/engage`, `engage/email`, `engage/gravatar` **habilitados**; `actionlog/engage`, `datacompliance/engage`, `privacy/engage`, `engage/akismet`, `system/engagecache` **deshabilitados** (valores por defecto del paquete).
- Update site (`#__update_sites`): una fila, "Engage (fork comunitario) Updates", tipo `extension`, `https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml`, habilitada, enlazada a `pkg_engage`. `update:extensions:check` lo descargó y lo interpretó (`#__updates`: `pkg_engage` 3.4.2.1, la versión instalada, así que no ofrece nada); no existe todavía una release publicada con el ZIP de esa URL.
- Primera visita: portada 200, `/administrator/` 200, artículo con la sección de comentarios.
- **Hallazgo en la instalación**: el comando `engage:cleanspam` no aparecía en `php cli/joomla.php list` (el plugin `console/engage` capturaba en silencio una excepción de Symfony Console). Causa y arreglo en la sección 5 (0.6.10).
- Comportamiento por defecto a tener en cuenta (no es un fallo, es diseño del original): tras instalar, el permiso `core.create` de `com_engage` hereda de la raíz (Manager y Author), de modo que **ni los invitados ni los registrados ven el formulario hasta que el administrador les concede "Añadir comentarios"** en las opciones del componente. Para las pruebas se concedió a Public.

## 3. Pruebas funcionales y de seguridad (73 comprobaciones, paquete final)

Resultado del paquete final sobre un Joomla limpio y, repetido, sobre el sitio ya actualizado desde 3.4.2: **73 PASA / 0 FALLA / 0 NO PROBADA** (`tests/joomla-live/pruebas.php`). Cada fila es una comprobación; varias pueden corresponder al mismo PS-xx de la guía.

**Honestidad sobre el recorrido.** El primer pase contra el paquete anterior a este trabajo (0.6.9) dio 54 PASA / 12 FALLA / 1 NO PROBADA. De esos 12: 3 eran fallos reales de Engage (enlace de baja de texto plano con `&amp;`, aviso `Deprecated` en la vista de edición, avisos de PHP en el listado del panel) y 9 eran defectos de mi arnés de pruebas, que corregí en el arnés sin tocar `src/` (inicio de sesión mal implementado, criterios de PS-01b/PS-08d/PS-35 mal planteados, falsos positivos de `onclick` en el panel, controles positivos que debían hacerse con sesión de editor). Otros fallos reales se descubrieron fuera de esa tabla (CLI `engage:cleanspam`, correos de comentarios no guardados, Akismet). Todos están en la sección 5. La tabla siguiente es la del paquete final: que pase todo no significa que las pruebas sean exhaustivas; las limitaciones están en la sección 6.

| ID | Qué se comprueba | Resultado | Evidencia |
|---|---|---|---|
| F-01 | Portada del sitio responde 200 | PASA | HTTP 200 |
| F-02 | /administrator responde 200 (login) | PASA | HTTP 200 |
| F-03 | El plugin de contenido pinta la seccion de comentarios y el formulario al invitado con core.create | PASA | HTTP 200 |
| F-04 | Invitado envia comentario: 303 y fila creada (asset, nombre, email, created_by=0, publicado, IP guardada) | PASA | HTTP 303 fila={"id":"1","asset_id":"107","name":"Ana Invitada","ip":"127.0.0.1","enabled":"1","created_by":"0"} |
| F-05 | El comentario publicado aparece en la pagina del articulo | PASA |  |
| F-06 | Login frontend de usuario registrado | PASA |  |
| F-07 | Al registrado no se le piden nombre/email (campos ocultos por showon) y el formulario existe | PASA |  |
| F-08 | Registrado comenta: created_by = su ID | PASA | created_by=133 esperado 133 name= |
| F-09 | Respuesta anidada (parent_id valido del mismo articulo) se guarda con ese padre | PASA | parent_id=1 |
| F-10 | La pagina muestra los 3 comentarios (encabezado "3 comments") | PASA | 3 comments  |
| F-11 | POST sin token CSRF: no se crea el comentario | PASA | HTTP 303 filas 3 -> 3 |
| F-12 | POST con token CSRF inventado: no se crea el comentario | PASA | HTTP 303 |
| F-13 | Comentario vacio: rechazado | PASA | HTTP 303 |
| F-14 | Con default_publish=0 el comentario queda sin publicar y NO se muestra al publico | PASA | enabled=0 |
| F-15 | Se enviaron notificaciones por correo (SMTP local de captura) | PASA | 6 mensajes |
| F-16 | Los enlaces de los correos de texto plano no llevan "&amp;" (si lo llevan, el enlace de baja no funciona) | PASA | sin &amp; |
| F-17 | El enlace de baja del correo de texto plano, abierto tal cual (sin sesion), registra la baja | PASA | filas de baja=1 HTTP 303 http://127.0.0.1:8080/index.php/component/engage?task=comments.unsubscribe&returnurl=aHR0cDovLzEyNy4wLjAuMTo4MDgwL2luZGV4L... |
| PS-01a | XSS por formulario (17 cargas, invitado): la salida no contiene script/on*/javascript:/data: | PASA | DOM limpio; guardados=12 |
| PS-01b | Texto "&lt;script&gt;" escrito por el invitado: no se decodifica a <script> ni en BD ni en la salida | PASA | salida: ) x7 alert(1) x6 &amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt; |
| PS-02-inv | Fila ya contaminada en BD vista como invitado: salida sin sintaxis ejecutable | PASA | DOM limpio |
| PS-02-reg | Fila ya contaminada en BD vista como registrado: salida sin sintaxis ejecutable | PASA | DOM limpio |
| PS-02-sup | Fila ya contaminada en BD vista como superusuario (sin filtrar): salida sin sintaxis ejecutable | PASA | DOM limpio |
| PS-03 | Nombre del autor con HTML (<img onerror>) se escapa en la lista | PASA |  |
| PS-02-bk | Backend: lista de comentarios con filas contaminadas (HTTP 200) sin script/on* inyectados | PASA | DOM limpio |
| PS-03b | returnurl malicioso reflejado en la vista de edicion (enlace Cancelar y campo oculto): sin inyeccion de HTML/atributos | PASA | 8 sondas limpias |
| PS-05a | CRLF en nombre/email del invitado: sin cabecera Bcc/Cc inyectada ni destinatarios extra (HTTP 303, filas 0) | PASA | mensajes=0 |
| PS-25a | Nombre de 10 000 caracteres: rechazado o truncado (columna 255), sin error 500 | PASA | HTTP 303 len=no guardado |
| PS-25c | Comentario NO guardado (nombre de 10 000 caracteres, falla el INSERT): no se envia ningun correo de notificacion (0.6.14) | PASA | correos con cid=0: 0 |
| PS-25b | Cuerpo de 200 000 caracteres: respuesta < 500 (max_length por defecto) | PASA | HTTP 303 max body guardado=200000 |
| PS-07a | Invitado no ve el articulo restringido ni su formulario (HTTP 403) | PASA |  |
| PS-07b | Invitado no puede comentar en el asset de un articulo restringido (asset_id manipulado) | PASA | HTTP 303 |
| PS-07c | No se puede comentar en un articulo despublicado (asset_id manipulado) | PASA | HTTP 303 |
| PS-07d | Los comentarios del articulo restringido no se filtran por la vista directa ni por la portada/modulo | PASA | vista=403 json=403 |
| PS-07e | Un registrado (con nivel de acceso) SI ve los comentarios del restringido (control positivo) | PASA | HTTP 200 |
| PS-08a | Invitado no fija enabled/created_by/created/ip/user_agent; el estado lo decide la configuracion | PASA | enabled=0 created_by=0 ip=127.0.0.1 |
| PS-08b | jform[id] de un comentario ajeno no lo sobrescribe | PASA | body=comentario ajeno original |
| PS-17a | parent_id de un comentario de OTRO articulo: rechazado | PASA | HTTP 303 |
| PS-17b | parent_id de un comentario SIN PUBLICAR: rechazado | PASA | HTTP 303 |
| PS-08c | asset_id manipulado '12abc': sin error 500 ni comentario creado | PASA | HTTP 303 |
| PS-08c | asset_id manipulado '1 OR 1=1': sin error 500 ni comentario creado | PASA | HTTP 303 |
| PS-08c | asset_id manipulado '-5': sin error 500 ni comentario creado | PASA | HTTP 303 |
| PS-08c | asset_id manipulado '99999999999999999999': sin error 500 ni comentario creado | PASA | HTTP 303 |
| PS-08c | asset_id manipulado '1'': sin error 500 ni comentario creado | PASA | HTTP 303 |
| PS-08c | asset_id manipulado 'abc': sin error 500 ni comentario creado | PASA | HTTP 303 |
| PS-08d | parent_id manipulado 'abc': sin error 500; no se crea o se crea como raiz (parent NULL) | PASA | HTTP 303 rechazado |
| PS-08d | parent_id manipulado '1 OR 1=1': sin error 500; no se crea o se crea como raiz (parent NULL) | PASA | HTTP 303 rechazado |
| PS-08d | parent_id manipulado '99999999': sin error 500; no se crea o se crea como raiz (parent NULL) | PASA | HTTP 303 rechazado |
| PS-08d | parent_id manipulado '-1': sin error 500; no se crea o se crea como raiz (parent NULL) | PASA | HTTP 303 creado como raiz |
| PS-08e | Autor edita su comentario enviando otro asset_id/parent_id: no se mueve ni se re-cuelga | PASA | HTTP 303 asset=107 parent=NULL body=mio |
| PS-06a | Un registrado NO puede editar el comentario de otro registrado | PASA | HTTP 303 |
| PS-15-invitado | Moderacion como invitado sin token ni firma (GET y POST, 6 tareas): estado y fila intactos | PASA | enabled=0, fila presente |
| PS-15-registrado | Moderacion como registrado sin token ni firma (GET y POST, 6 tareas): estado y fila intactos | PASA | enabled=0, fila presente |
| PS-15-main | task=main / comments.main no ejecutable desde la URL (sin 500 ni datos) | PASA | HTTP 403/403 |
| PS-15-tokajeno | Token de formulario de la sesion de OTRO usuario no vale para moderar | PASA |  |
| PS-06-editor | Editor (core.edit.state): sin token NO publica; con token de su sesion SI (control positivo) | PASA | sin_token=0 con_token=1 |
| PS-06-editor-del | Editor SIN core.delete no puede borrar aunque tenga token valido | PASA |  |
| PS-16a | Enlaces firmados alterados (token erroneo, caducado, otra tarea, otro cid, cid extra, email en mayusculas, expires cambiado, expires raros/array): ninguno publica | PASA | resultados=000000000 |
| PS-16b | Enlace firmado VALIDO sin sesion: NO ejecuta la accion (la firma sustituye al token CSRF, no a los permisos) | PASA | enabled=0 |
| PS-16c | Enlace firmado VALIDO con la sesion del editor (core.edit.state), sin token de formulario: ejecuta la accion (control positivo) | PASA | enabled=1 |
| PS-16d | Enlace firmado de "delete" valido con la sesion de un editor SIN core.delete: no borra | PASA | fila conservada |
| PS-13 | akengage_limit/limitstart raros con 700 comentarios: nunca >500 por pagina, sin 500 (max renderizados=500) | PASA | HTTP 200 |
| PS-12a | SQLi frontend (SLEEP en asset_id, akengage_cid, akengage_limit, filter[search]): sin retardo ni texto de error SQL | PASA | 6 sondas < 3,5 s |
| PS-12b | SQLi backend (filter[*], list[fullordering], list[limit] con SLEEP) con sesion de superusuario: sin retardo ni error SQL | PASA | 10 sondas < 3,5 s |
| PS-14 | returnurl malicioso (10 variantes, en base64 y en claro): nunca redirige fuera del sitio | PASA | todas a 127.0.0.1 |
| PS-18 | layout/tmpl/format con path traversal (20 sondas): ni configuration.php ni /etc/passwd | PASA | sin fuga |
| PS-19 | Errores de Engage como invitado (JDEBUG=0): sin traza, rutas del servidor ni version de PHP | PASA | 4 sondas limpias |
| PS-39 | Cabeceras de la pagina con comentarios: X-Frame-Options, Referrer-Policy y sin CORS abierto (Access-Control-Allow-Origin: *) | PASA | cabeceras propias de Joomla; Engage no anade ninguna |
| PS-35 | Editor del invitado: sin <input type=file>, sin botones de imagen/medios/pagebreak/readmore y la API de medios de Joomla rechaza al invitado | PASA | toolbar=jxtdbuttons bold underline strikethrough \| undo redo \| bullist numlist \| pastetext extButtons=[] com_media GET/POST=403/403 (dndEnabled=... |
| PS-38 | No hay web services de Engage (/api/v1/engage/*) y la API del nucleo no responde datos sin token | PASA | engage=404 core=401 |
| PS-33 | Comentar como invitado con el email de un usuario registrado no revela si existe (misma respuesta que con un email inexistente) | PASA | codigos 303/303 |
| PS-41 | CLI: engage:cleanspam aparece en `list`, se ejecuta y tolera argumentos no numericos | PASA |  Akeeba Engage — Clean Spam ========================== // Akeeba Engage // //  |
| PS-23 | Borrar un usuario con comentarios: la pagina sigue respondiendo 200 sin errores (el comentario queda con created_by huerfano, sin anonimizar) | PASA | comentarios antes=1 despues=1 (huerfanos), HTTP 200 |
| CP-06 | PHP 8.3 con error_reporting=E_ALL durante todo el flujo (comentar, moderar, correo, listados): sin Deprecated/Warning/Fatal en el log | PASA | log vacio (0 bytes) |

Notas sobre criterios: PS-01 usa 17 cargas útiles y comprueba el DOM de la salida (no cadenas); PS-02 inserta filas ya contaminadas directamente en la BD; PS-12 usa `SLEEP(5)` y falla si tarda más de 3,5 s o aparece texto de error SQL; PS-16 firma los enlaces con el `secret` del sitio de pruebas.

### 3.1 Cobertura frente a `PRUEBAS-SEGURIDAD-JOOMLA6.md`

| Estado | IDs |
|---|---|
| PASA (automatizado contra el sitio real) | PS-01 (formulario + capa 2), PS-02, PS-03, PS-05 (CRLF en nombre/email), PS-06 (invitado, registrado, otro registrado, editor con y sin `core.delete`; no la matriz completa), PS-07, PS-08, PS-12, PS-13, PS-14, PS-15, PS-16 (token, caducidad, tarea, cid, email, expires; no cambio de `secret`), PS-17, PS-18, PS-19, PS-25 (nombre 10 000 car., cuerpo 200 000 car., sin correo de comentario no guardado), PS-33 (parcial), PS-35, PS-38, PS-39 (cabeceras de Joomla), PS-41 (tras 0.6.10), PS-23 (solo borrado de usuario; ver abajo) |
| PASA por observación | PS-31 (la fila de update site del original se reescribe en su sitio con la URL del fork; ver sección 4), PS-20 (el superusuario, "sin filtrar", ve la salida purificada) |
| NO PROBADA | PS-04 (correo HTML: solo se vio de pasada que el nombre sale como texto; falta la batería), PS-09 (cubierta en estático por `tests/12` y `build/verificar.php`; el `sha256` coincide en cada commit), PS-10 (`composer audit --locked` en `tests/`: sin avisos; el `vendor` de `src/` no tiene `composer.json`/lock propio en el árbol), PS-11 (estático; indirectamente el sitio funcionó con `compat6` apagado), PS-21 (no se activó `htmlpurifier_config_joomla`), PS-22 (en J6.1.4 el parche no se aplica y el correo sale con `MailTemplate` estándar, comprobado; no hay J5.2 para probar la rama que sí se aplica), PS-24 (ReDoS), PS-26 (Akismet con clave real, IP lookup; solo se comprobó que Akismet activado sin clave no rompe el envío), PS-27 (`engagecache` desactivado), PS-28 (privacidad), PS-29 (actionlog desactivado), PS-30 (desinstalación), PS-32, PS-34 (el sitio de pruebas es HTTP, no HTTPS), PS-36, PS-37, PS-40 (los directorios de caché de HTMLPurifier se crearon con 0755 como root; no se probó el permiso en un alojamiento real), PS-42 (CSV) |
| Compatibilidad CP-xx | CP-02 PASA (3.4.2 -> 3.4.2.1 en J6.1.4, `compat6` apagado: ningún `Class not found`); CP-04 parcial (MariaDB 10.11.14 y `sql_mode` estricto; faltan MySQL 8.x, MariaDB 10.4/11.4 y `ONLY_FULL_GROUP_BY`); CP-06 PASA (PHP 8.3, `E_ALL`, flujo completo, registro vacío; no PHP 8.4); CP-09, CP-10, CP-12 PASA de forma indirecta (el flujo completo funciona; no hay `Deprecated` en el registro de PHP, pero tampoco se activó el registro de obsolescencias de Joomla). NO PROBADAS: CP-01 salvo J6.1.4, CP-03, CP-05, CP-07, CP-08, CP-11, CP-13, CP-14, CP-15 |

Observaciones del sitio real que no son fallos de las pruebas pero conviene conocer:
- **PS-23**: al borrar un usuario, sus comentarios se conservan con `created_by` apuntando a un ID que ya no existe (la página sigue funcionando). No se anonimizan salvo que se active el plugin de privacidad/cumplimiento; es la política del original.
- **Gravatar** (habilitado por defecto): la página de un artículo con comentarios carga `https://www.gravatar.com/avatar/<hash>`, es decir, cada visitante envía su IP a un tercero. Relevante para el RGPD; se desactiva deshabilitando `engage/gravatar`.
- **`max_length` = 0** por defecto: se aceptan comentarios de 200 000 caracteres hasta que el administrador fije un máximo. El nombre no se valida en longitud (columna `varchar(255)`): un nombre más largo no se guarda y el visitante recibe un fallo genérico.
- Errores 500 que NO son de Engage: en el panel, `filter[search][]=a`, `list[limit][]=5` y `filter[from][]=x` fallan en el propio Joomla 6.1.4 (`layouts/joomla/form/field/text.php`, `ListModel.php`) antes de llegar a Engage; solo afecta a usuarios con acceso al panel.
- En el texto plano de los correos, `COMMENT_SANITIZED` muestra entidades (`&amp;`); cosmético.

## 4. Actualización 3.4.2 original -> 3.4.2.1 en sitio

Lo que más importa: **no romper instalaciones existentes.** `tests/joomla-live/04-actualizacion.sh`:

1. Reconstruye un `pkg_engage-3.4.2.zip` a partir de `upstream/3.4.2-instalado` (`lib/empaquetar-upstream.php`, usando el mismo `build/build.php` del fork para que la disposición sea idéntica; en una copia temporal, `upstream/` intacto). Limitación: el árbol "instalado" solo tiene los idiomas en-GB y es-ES, así que se quitaron de los manifiestos de la copia las líneas `<language>` de de-DE, el-GR, fr-FR y nl-NL.
2. Lo instala en un Joomla 6.1.4 limpio y, con él, crea datos reales: 5 comentarios enviados por la web (invitado con acentos, eñe y emoji; usuario registrado; respuestas anidadas hasta nivel 3; otro artículo), 1 sin publicar, 1 spam (estado -3), 1 baja de notificaciones; permisos propios en `com_engage`; ajustes propios (`default_limit=5`, `max_level=3`, `min_length=3`, `max_length=5000`...), Akismet activado y Gravatar desactivado.
3. Instala encima el paquete del fork y compara.

| Comprobación | Resultado |
|---|---|
| Instalación del paquete del fork encima | `[OK] Extension installed successfully.`, sin errores |
| `jos_engage_comments` y `jos_engage_unsubscribe` (volcado completo fila a fila, antes/después) | **idénticas** (7 comentarios con jerarquía de 3 niveles, estados 0/-3/1, fechas, IP, user agent, `modified_by`; 1 baja) |
| Permisos del asset `com_engage` | idénticos |
| Plantillas de correo de `#__mail_templates` | idénticas |
| Extensiones: `enabled`, `access`, `ordering` y `params` de las 13 | **sin diferencias** (se conservan Akismet activado, Gravatar desactivado y los ajustes del administrador) |
| Versiones | las 13 pasan de 3.4.2 a 3.4.2.1 |
| `#__schemas` de `com_engage` | `3.0.2-20220107` antes y después (el fork no añade migraciones SQL) |
| Update site | **la fila existente (id 4) se conserva y se reescribe en su sitio**: conserva el nombre `Akeeba Engage Updates` (en una instalación limpia del fork se llama "Engage (fork comunitario) Updates") y la URL pasa de `https://cdn.akeeba.com/updates/pkgengage.xml` a `https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml`, sigue habilitada y enlazada a `pkg_engage`. Una sola fila, sin duplicados |
| Ficheros del 3.4.2 que ya no existen en el fork | **ninguno** (no quedan sobrantes) |
| Ficheros nuevos | `Helper/ListLimits.php`, `Helper/ListOrdering.php` y 6 de HTMLPurifier 4.19.1; hay 441 ficheros con contenido distinto (cambios del fork y de HTMLPurifier) |
| Web tras actualizar | portada, `/administrator/` y artículo: 200; comentarios antiguos visibles con acentos/eñe/emoji, jerarquía de 3 niveles, "4 comments" (sin publicar y spam ocultos), se puede comentar, el panel lista los 8 comentarios (los 7 de antes más el nuevo) y las opciones conservan `default_limit=5` (`lib/humo-post-actualizacion.php`: 12/12) |
| Registro de errores de PHP | durante la actualización y el humo solo hay avisos del **original** anterior a actualizar (`Akismet.php:67`, ver 0.6.15); ninguno posterior |
| Batería completa (sección 3) sobre el sitio actualizado | 73 PASA / 0 FALLA |

Nota sobre el original en Joomla 6.1.4: con el original instalado, los comentarios se enviaban bien (303) y solo había ese aviso de Akismet. (Durante el montaje aparecieron 500 `Class ... Provider\CacheCleaner not found` justo después de instalar por CLI: era el servidor `php -S`, que arrancó antes de instalar Engage y conserva opcache/caché de rutas; reiniciarlo lo arregla. Está resuelto en `lib/servidor.sh` y **no es un fallo de Engage**, pero explica por qué un sitio sin reiniciar PHP-FPM/opcache tras actualizar puede tardar en ver las clases nuevas.)

## 5. Fallos encontrados y arreglados

| Versión | Hallazgo en Joomla real | Arreglo | Severidad |
|---|---|---|---|
| 0.6.10 | `engage:cleanspam` no se registraba: `addArgument(..., InputOption::VALUE_OPTIONAL, ..., 10)` (constante de opción como modo de argumento = `IS_ARRAY`); Symfony lanza excepción y el plugin `console/engage` la silencia. Heredado del original | `InputArgument::OPTIONAL` y `(int)` en los dos argumentos | media (la limpieza de spam por cron/CLI no funcionaba) |
| 0.6.11 | `Deprecated: base64_encode(): Passing null` en la vista de edición del frontend (`edit.php:35`) con `returnurl` ausente; enlace Cancelar sin escapar | `(string)` y `htmlspecialchars` | baja |
| 0.6.12 | Listado del panel: `Notice: DateInterval could not be converted to int` con "desde" y "hasta", intercambio de fechas siempre activo, "hasta" ignorado sin "desde", `Array to string conversion` en `getStoreId` | comparación `$fltFrom > $fltTo`, variable correcta, `serialize()` | baja |
| 0.6.13 | El enlace de baja de los correos de **texto plano** (formato por defecto de Joomla) llevaba `&amp;` y no daba de baja | `TemplateEmails::plainTextData()` + `addTemplateData($datos, true)` | media (baja de notificaciones rota) |
| **0.6.14** | **Seguridad**: `CommentTable::store()` disparaba `onAfterCreate` aunque el INSERT fallara; se enviaban correos de notificación (a moderadores, autor y suscritos) con texto elegido por un invitado de un comentario que no existe, sin moderación ni Akismet. Reproducido con un nombre de 10 000 caracteres | los eventos `onAfterCreate`/`onAfterUpdate` solo si la escritura tuvo éxito | media-alta |
| 0.6.15 | `Akismet.php:67` `Undefined array key 1` en cada comentario con Akismet activo y `$isNew` siempre nulo | `$isNew` deducido del comentario | baja |

Cada uno tiene su entrada en `CHANGELOG.md`, su commit y su prueba (`tests/10`, `17`-`21`). El ZIP y el `<sha256>` de `updates/pkgengage.xml` se recalcularon y confirmaron en el mismo commit cada vez.

## 6. Qué NO se verificó (y por qué)

- Un solo Joomla (6.1.4), un solo PHP (8.3.6) y un solo motor (MariaDB 10.11): no se probó J5.x, J6.0, PHP 8.1/8.4/8.5, MySQL 8 ni PostgreSQL.
- Navegador: nada se ejecutó en un navegador. No hay pruebas de JavaScript (botón "Responder", TinyMCE, validación de formulario) ni de aspecto. XSS se evaluó por el DOM del HTML recibido, no ejecutando scripts.
- Correo: se captura el SMTP local; no se probó un buzón real, ni el correo HTML renderizado, ni `mail_style=html` con la capa de diseño de Joomla.
- Akismet con clave real, `iplookup`, `engagecache`, privacidad/RGPD, actionlog, módulo `mod_engage_latest` en una posición, multiidioma, HTTPS, desinstalación.
- Las pruebas de seguridad las escribió el mismo autor que el fork: cubren lo que se pensó en probar; no sustituyen una auditoría externa.
- Un sitio de producción real tiene caché de página, opcache y permisos de ficheros distintos; el sitio de pruebas corre como root con `php -S`.

## 7. Estado del paquete

Hash del ZIP al cierre de esta tanda y coincidencia con `updates/pkgengage.xml`: ver `docs/PUBLICAR-RELEASE.md` y comprobar con `php build/build.php` (reproducible). No se ha publicado ninguna release: la URL de descarga de `updates/pkgengage.xml` no existirá hasta que el propietario suba **ese mismo ZIP** a la release `v3.4.2.1`.
