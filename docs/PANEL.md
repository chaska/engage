# Panel de control y opciones modernas de Engage (0.6.24 y 0.6.25)

Desde la 0.6.24, al pulsar **Akeeba Engage** en el menú de administración de Joomla se abre un **panel de control** en lugar de caer directamente en la lista de comentarios. Los comentarios, las plantillas de email y las opciones siguen donde estaban y funcionan igual.

![Panel en el diseño Claro](img/panel-claro.png)

## Qué hay

- **Accesos rápidos**, con icono en cuadrado de color: Comentarios (con insignias de comentarios pendientes y de spam), Plantillas de email, Opciones y Permisos (solo si usted puede administrar el componente) y Acerca de.
- **Actividad**: total, publicados, por moderar, spam y comentarios recibidos en los últimos 7 y 30 días, con un mini-gráfico de barras de los últimos 30 días. El gráfico es un SVG dibujado en la propia página (sin librerías); tiene descripción para lectores de pantalla, ventana de detalle al pasar el ratón o tocar, y una tabla con los mismos datos («Ver como tabla»). Los días se cuentan en UTC.
- **Últimos comentarios** con acciones rápidas: publicar, marcar como spam y eliminar. Cada acción abre una ventana de confirmación propia (accesible, con el foco en «Cancelar»; nunca el `confirm()` del navegador) y, al aceptar, usa las tareas que ya existían (`comments.publish`, `comments.possiblespam`, `comments.delete`) con el token de seguridad de Joomla y vuelve al panel. Los botones solo aparecen si usted tiene el permiso correspondiente (cambiar estado, eliminar), y las tareas lo vuelven a comprobar en el servidor. «Marcar como spam» no borra: deja el comentario en el estado de spam, donde se puede revisar.
- **Más comentados**: los cinco contenidos con más comentarios publicados.
- **Más valorados** (0.7.0): los cinco comentarios publicados con mejor puntuación (**me gusta menos no me gusta**, solo con puntuación positiva), con el nombre de quien comentó, un extracto y la puntuación; cada uno enlaza a los comentarios de su contenido. Además, los números de **Actividad** suman los «me gusta» y «no me gusta» de los comentarios publicados. Solo lectura, consultas con parámetros, sin llamadas externas, y sin mostrar quién reaccionó ni los favoritos (son privados). Con las reacciones desactivadas la tarjeta sigue mostrando lo ya guardado.
- **Estado y recomendaciones** (semáforo verde, ámbar y rojo, con icono y texto, nunca solo color) y un botón para arreglar cada punto:

| Punto | Rojo | Ámbar |
|---|---|---|
| Plugin de contenido (comentarios en los artículos) | desactivado o no instalado | |
| Plugin de correo (avisos) | | desactivado o no instalado, **o activo pero sin dirección fiable del sitio (0.8.3/0.8.4): los correos salen sin enlaces de moderación, de baja ni al comentario, porque la dirección del sitio nunca se toma de la petición. Se resuelve abriendo una vez el panel de control de Engage como administrador, o escribiendo la URL en Opciones > Notificaciones (ya NO hace falta tocar `configuration.php`)** |
| Caché de página | | caché de Joomla activa y plugin de caché de Engage apagado |
| Protección contra spam | | sin CAPTCHA válido ni Akismet con clave |
| Moderación | publicación inmediata y sin ninguna protección contra spam | |
| Gravatar | | modo «siempre» (contacta con gravatar.com sin consentimiento) o JBCookies sin grupo |
| Filtro de HTML | etiqueta peligrosa en la lista (`script`, `iframe`, `object`, `embed`, `style`, `form`) | modo «joomla», o `img[src]` permitido (el visitante contacta con el servidor de la imagen) |
| Carpeta de caché de HTML Purifier | | no escribible o no comprobable |
| Versión de PHP | menor que 8.1 | menor que 8.2 |
| Versión de la base de datos | menor que 8.0.13 (MySQL) o 10.4.0 (MariaDB) | no se pudo leer |
| Servidor de actualizaciones | | no hay, está desactivado o no apunta al fork |

- **Acerca de**: versión del paquete instalado (la guarda Joomla), versión del fork, licencia (GPL v3 o posterior), autor original (Nicholas K. Dionysopoulos / Akeeba Ltd) y el aviso de que es un fork comunitario no oficial, sin afiliación con Akeeba Ltd, con enlaces al repositorio, al registro de cambios, a esta documentación y al sitio del autor original.

## Tres diseños y «Automático»

El selector de la cabecera tiene **Claro**, **Medio** (pizarra de tono cálido), **Oscuro** y **Automático** (sigue el modo claro/oscuro del dispositivo). La preferencia se guarda en el navegador (`localStorage`, clave `eg-admin-theme`, dentro de `try/catch`) y se aplica **antes de pintar** la página (un script pequeño sin `defer` en la cabecera), así que no hay parpadeo. Sin JavaScript, o sin preferencia guardada, manda el dispositivo por CSS puro.

![Panel en el diseño Medio](img/panel-medio.png)
![Panel en el diseño Oscuro](img/panel-oscuro.png)

El diseño del panel es independiente del tema de administración de Joomla (Atum claro u oscuro): todo el CSS cuelga del contenedor `.eg-admin` y de variables `--eg-admin-*`, y no toca nada fuera de él. El selector se maneja con teclado (flechas, Inicio y Fin) y anuncia el cambio a los lectores de pantalla.

### Accesibilidad

Teclado completo y foco visible en todos los controles, `role="radiogroup"` en el selector, `role="status"` para los avisos, ventana modal con `<dialog>`, `prefers-reduced-motion`, modo de contraste forzado, adaptable desde 360 px, sin estilos ni scripts en línea (compatible con una CSP estricta). El contraste se **mide**: `tests/28-panel.php` calcula 250+ pares sobre las variables del CSS y `tests/joomla-live/11-panel.sh` mide en Chromium cada texto visible del panel contra su fondo real (mínimo 4,5:1; 3:1 para iconos, gráfico y anillo de foco) en los tres diseños y en escritorio y móvil.

## Permisos

- Ver el panel: `core.manage` del componente (Joomla lo exige antes de llegar a nuestro código).
- Accesos a Opciones y Permisos y botón «Opciones» de la barra: `core.admin` o `core.options`.
- Publicar y marcar como spam: `core.edit.state`. Eliminar: `core.delete`. Los comprueban las tareas existentes.
- Los botones para abrir un plugin solo se ofrecen si usted puede gestionar y editar plugins.

## Privacidad: cero conexiones externas

El panel **no se conecta a ningún servidor**: no comprueba versiones, no descarga noticias, no carga tipografías, iconos ni scripts de ningún CDN. Los iconos son SVG propios dentro de la página y la tipografía es la del sistema. Los únicos enlaces a otros sitios (repositorio, registro de cambios, documentación y autor original) los abre usted y llevan `rel="noopener noreferrer"`. `tests/joomla-live/11-panel.sh` registra todas las peticiones del navegador y exige que ninguna sea a otro host.

## Actualizar desde la 3.4.2: el menú de administración

Comprobado en un Joomla 6.1.4 real, actualizando desde el paquete 3.4.2 original (`tests/joomla-live/04-actualizacion.sh`): el elemento «Akeeba Engage» del menú sigue apuntando a `index.php?option=com_engage` y ahora abre el panel. Joomla vuelve a crear los elementos del menú al actualizar (el identificador del elemento cambia, el título y el enlace no) y, como el manifiesto declara ahora un submenú, aparecen debajo **Panel de control**, **Comentarios** y **Plantillas de email**. No hay cambios en la base de datos. Cualquier enlace antiguo con `view=comments` o `view=emailtemplates` sigue funcionando.

## Archivos

`backend/src/Controller/ControlpanelController.php`, `backend/src/View/Controlpanel/HtmlView.php`, `backend/tmpl/controlpanel/default.php`, `backend/src/Helper/Panel{Data,Health,Chart,Icons}.php` (desde la 0.7.0 `PanelData::reactions()` alimenta la tarjeta «Más valorados»), `media/css/panel.css`, `media/js/panel.js`, `media/js/panel-theme.js`, cadenas `COM_ENGAGE_PANEL_*` en `com_engage.ini` y `COM_ENGAGE_MENU_*` en `com_engage.sys.ini` (en-GB y es-ES). `PanelHealth` y `PanelChart` son funciones puras (probadas con datos simulados); `PanelData` solo lee de la base de datos, con el constructor de consultas de Joomla y parámetros enlazados.

---

# Opciones modernas (0.6.25)

La pantalla **Opciones** (menú de Engage > Opciones, o la tarjeta «Opciones» del panel) es una alternativa a la pantalla de opciones de Joomla: categorías a la izquierda, a la derecha filas «etiqueta + control» con una ayuda breve debajo, y **cada cambio se guarda al instante**, sin pulsar «Guardar y cerrar». La pantalla clásica sigue disponible con el botón **Opciones clásicas** (barra de herramientas y cabecera), y siempre es la que tiene todos los campos.

![Opciones en el diseño Claro](img/config-claro.png)

## Categorías y controles

| Categoría | Qué contiene |
|---|---|
| **Diseño** | Vista previa en vivo de un hilo (cambia al elegir sangría, cita y marca de las respuestas y el tema), tema visual con **tarjetas de vista previa** (Clásico, Moderno, Minimalista, Oscuro), nivel máximo de anidación, cita «En respuesta a», sangría, marca visual, orden y dónde mostrar el resumen de comentarios. **Desde la 0.8.0** también: insignias «Autor» y «Moderador», botón «Copiar enlace», selector de orden con «Solo mis favoritos» (interruptores) y orden inicial (control segmentado: Automático / Más recientes / Más antiguos / Más valorados). Ver [`ORDEN-Y-FAVORITOS.md`](ORDEN-Y-FAVORITOS.md). |
| **Moderación** | Publicación inmediata o con aprobación, comentarios abiertos o cerrados, cierre automático, longitud mínima y máxima, elementos por página |
| **Reacciones** (0.7.0) | Botones de reacción sí/no, botón de no me gusta sí/no, botón de favorito sí/no (interruptores) y quién puede reaccionar (control segmentado: usuarios registrados / solo quien puede comentar). Icono de corazón; se guardan al instante y el servidor valida cada valor como 0/1 o lista cerrada. Ver [`REACCIONES.md`](REACCIONES.md). |
| **Protección contra spam** | CAPTCHA (los plugins de captcha activados) y a quién se exige, aceptación de condiciones y su texto, y Akismet (clave, a quién se comprueba, descartar spam evidente) |
| **Notificaciones** | Aviso al autor del contenido y a quienes participan; correo a los gestores (plugin de correo); **«Incluir enlaces en los correos»** (0.8.3, `email_include_links`, Sí por defecto) |
| **Privacidad y Gravatar** | Modo de Gravatar (preguntar, apagado, siempre), aviso, origen del consentimiento (aviso de Engage o JBCookies) y grupo de JBCookies, enlace al perfil, calificación e imagen por defecto |
| **Seguridad del HTML** | Modo de filtrado, uso del filtrado de Joomla, lista de etiquetas permitidas de HTML Purifier, modo de inclusión |
| **Avanzado** | Solución de plantillas de correo, URL de búsqueda de IP, antigüedad del spam, CSS personalizado y otros |
| **Permisos** | Enlace a la pantalla de permisos de Joomla |

Cada ajuste usa el control que le corresponde por su definición en `config.xml` (o en el manifiesto del plugin): **interruptor** (`role="switch"`) para sí/no, **control segmentado** (`role="radiogroup"`, con flechas) para listas cortas y enteros de pocos valores, **selector** para listas largas, **campo numérico** o **de texto** con validación, y **área de texto** para la lista de etiquetas. Las dependencias (`showon`) de `config.xml` se respetan: lo que no aplica se oculta. Etiquetas y ayudas son las cadenas que ya existían (la ayuda se acorta a una frase y el resto queda en «Más información»). En móvil las categorías pasan a una tira horizontal de pestañas.

Los tres diseños (Claro, Medio, Oscuro, Automático) son los mismos del panel y se eligen con el mismo selector.

## Cómo se guarda (y qué se vigila)

Cada cambio envía por `fetch` un `POST` a `index.php?option=com_engage&task=settings.save&format=json` con `scope`, `key`, `value` y el **token CSRF de Joomla**. Se muestra un aviso discreto («Guardado»; en rojo si algo falla), la fila indica «Guardando…/Guardado», y **si el servidor lo rechaza o no responde, el control vuelve al valor anterior** y se avisa.

En el servidor (`SettingsController::save`, `SettingsService`, `SettingsSchema`, `SettingsStorage`):

1. Solo `POST`, con token válido (si no, 405/403).
2. **Ámbito**: lista cerrada (`com_engage` y los plugins de Engage `gravatar`, `akismet` y `email`). Cualquier otro componente o plugin se rechaza.
3. **Permiso**: `core.admin` o `core.options` del componente (los mismos que la pantalla clásica) y, para los plugins de Engage, además `core.manage` y `core.edit` de `com_plugins`. Se comprueba **antes** de mirar clave o valor.
4. **Clave**: solo las declaradas en `config.xml` o en el manifiesto del plugin (el esquema se lee de esos ficheros en cada petición). Los campos de permisos (`rules`), de módulo (`login_module`) y de archivo (`custom_default`) no se aceptan: siguen en las opciones clásicas.
5. **Valor**: validado con la definición del propio campo (opciones permitidas de las listas, rango y paso de los enteros, longitud, caracteres permitidos), con reglas extra para `iplookup` (URL `http(s)` con un único `%s`), la lista de HTML Purifier (solo caracteres de su sintaxis), la clave de Akismet y el grupo de JBCookies; los campos de texto pasan además por el filtro de Joomla indicado en el campo (`safehtml` para el texto de las condiciones). Se rechazan arrays, objetos, NUL, caracteres de control, UTF-8 inválido y cadenas por encima del límite del campo (de 64 a 4000 caracteres según el campo).
6. **Escritura**: se leen los parámetros actuales de la base de datos, se cambia **solo esa clave** y se guarda con `Table Extension` de Joomla (sin tablas nuevas); se vuelve a leer para comprobarlo y se limpia la caché del sistema. Repetir el mismo valor no escribe nada (idempotente).
7. **Respuestas genéricas**: 403 (sin permiso o token), 422 (ámbito, clave o valor no válidos, sin decir cuál), 500 (no se pudo guardar). Cabeceras `no-store` y `nosniff`; el cuerpo nunca repite lo recibido.
8. **Registro de acciones**: si el plugin de registro de Engage está activado se anota «el usuario X cambió el ajuste Y (ámbito)» en el Registro de acciones de Joomla, **sin el valor** (puede ser una clave de API).

Nada de esto puede tocar permisos, parámetros de otras extensiones ni escalar privilegios: lo que no está en la lista cerrada de ámbitos y claves no se escribe.

## Pruebas

`tests/29-ajustes.php` (239 comprobaciones; esquema leído de los manifiestos reales, validación por tipo, servicio con permisos/almacén simulados, seguridad estática) y `tests/joomla-live/12-ajustes.sh` (navegador y HTTP reales sobre Joomla 6.1.4; ver `tests/joomla-live/LEEME.md`).

## Filas con controles anchos (0.6.26)

La fila «etiqueta + control» es ahora una fila flexible con salto de línea: la columna de texto pide como mínimo 16 rem y, si el control no cabe a su lado, baja a la línea siguiente (a la izquierda). Los controles segmentados con opciones largas (más de 40 caracteres en total o alguna de más de 24; desde la 0.6.27 ya no cuenta el número de opciones; p. ej. «Modo de Gravatar» y «Clasificación») pasan siempre a pila: etiqueta y ayuda arriba y el control debajo a todo el ancho, con las opciones repartidas y envueltas en varias líneas. Es CSS y un criterio del servidor (clase `eg-row--wide`); no depende de JavaScript. Los interruptores, selectores, números y segmentados cortos no cambian.

![Privacidad y Gravatar](img/config-gravatar.png)

## Controles segmentados por ancho real (0.6.27)

El apilado ya no depende del número de opciones sino del ancho real del texto (clase del servidor en cada fila):

- **Cortos** (`eg-row--short`: suma de etiquetas de hasta 32 caracteres y ninguna de más de 12; p. ej. «Nivel máximo de anidación» 1 a 6, «Sangría», «Orden», Mostrar/Ocultar): control compacto de ancho propio, segmentos de **igual anchura** y **siempre en una sola fila**. Va a la derecha, en la misma línea que la etiqueta, si cabe; si no, debajo y a la izquierda. En pantallas muy estrechas reduce el relleno y, como último recurso, se desplaza en horizontal (no se parte).
- **Medios**: igual anchura; en pantallas estrechas pasan a una rejilla regular a todo el ancho (nunca 4+2 estirado).
- **Largos** (`eg-row--wide`): pila a todo el ancho con rejilla regular de columnas iguales.

**Ayudas acortadas**: el texto de ayuda de una fila se acorta en el servidor a 200 caracteres (termina en «…»); no es un recorte por CSS (`line-clamp`). El texto completo está siempre en «Más información», un `<details>` nativo con `<summary>`: se abre con Tab + Intro/Espacio y lo anuncian los lectores de pantalla como expandido o contraído.

![Opciones de Diseño con los segmentados cortos](img/config-segmentados.png)

Prueba: `tests/joomla-live/14-opciones-segmentados.sh`.

## Lista de comentarios del administrador (0.8.5)

- **Tipo de elemento:** filtro con los tipos que realmente tienen comentarios (Artículos y, de cada tercero, `com_xxx.tipo (de terceros)`). Lista cerrada: un valor que no esté en ella se ignora.
- **Estado:** «Todos (publicados, sin publicar y spam)» es el valor por defecto; también se puede filtrar por publicado, sin publicar, posible spam y spam. Un comentario de un tercero guardado con `enabled` 0, -3 o -2 se localiza ahí.
- **Elemento de cada fila:** título (como texto) y enlace de la ficha que devuelve el plugin del tercero (ya saneada: URL solo relativa o del propio sitio). La ficha se pide una vez por activo y solo a los 50 primeros activos distintos de la página.
- **«Elemento no disponible (asset N)»:** plugin del tercero desactivado, elemento borrado o activo inexistente. El comentario sigue en la lista y se puede moderar.

## Dirección base de los enlaces de los correos (0.8.4: ya no hay que tocar `configuration.php`)

Los enlaces absolutos de los correos (moderación firmada, baja, enlace al comentario) se construyen **siempre** sobre una «base fiable», **nunca sobre la cabecera `Host` de una petición pública** (con un `Host` falsificado el correo llevaría enlaces firmados a otra web). La 0.8.3 solo aceptaba `live_site` de `configuration.php`; **desde la 0.8.4 no hay que pedir nada al administrador**. Orden de prioridad:

1. **Opción «URL base de los enlaces de los correos»** (`email_base_url`, Opciones > Notificaciones). Manda sobre todo lo demás. Se valida en el servidor: `https://` (o `http://` solo si el sitio no fuerza SSL), nombre de dominio ASCII válido (los dominios con acentos se escriben en punycode, `xn--…`), sin usuario ni contraseña, sin parámetros ni ancla, sin ruta salvo la subcarpeta de Joomla, máximo 255 caracteres, sin IP interna ni `localhost` salvo que sea justo la dirección con la que usted está administrando el sitio; se guarda normalizada (sin barra final). Valores como `javascript:`, `https://a@b`, rutas con `..`, puertos fuera de rango, `//`, barras invertidas o espacios se rechazan (422).
2. **`live_site`** de `configuration.php`, si existe y es válido (como en la 0.8.3).
3. **Base aprendida.** Si no hay 1 ni 2, Engage guarda sola la dirección de la **primera petición de administración autenticada** al panel de control de Engage: un usuario con `core.admin` sobre `com_engage`, con sesión activa, abriendo la pantalla por GET (sin AJAX, sin `format=json`). Se guarda **una sola vez y nunca se sobrescribe** (otro administrador entrando por un alias del dominio no la cambia); no se aprende desde peticiones públicas o anónimas, ni por POST, ni una dirección interna (`localhost`, IP privada…). El esquema es `https` si el sitio fuerza SSL o la petición llegó por HTTPS (según Joomla; no se miran `X-Forwarded-*`), y nunca baja a `http` cuando el sitio fuerza SSL. Tras un proxy inverso que termina el TLS y no se lo cuenta a Joomla, escriba la opción 1.
4. **Nada de lo anterior:** los correos salen sin enlaces firmados y el semáforo del panel se pone en ámbar con la instrucción «abra el panel de control de Engage una vez como administrador o escriba la URL del sitio en Opciones, grupo Notificaciones».

El semáforo de «Correos de aviso» muestra qué base se usa y de dónde viene (opción, `live_site` o aprendida). Si es la aprendida, un botón **Olvidar la dirección aprendida** (POST + token + `core.admin`) la borra; no vuelve al panel, así que la siguiente visita de administración al panel la aprende de nuevo (útil tras cambiar de dominio). Para cambiarla basta escribir la opción. Una opción no válida (escrita fuera del formulario) se ignora y el semáforo avisa. Multi-dominio y alias: manda la base elegida, no el dominio por el que entra cada visitante. La consola/cron usa la base guardada (opción o aprendida; `live_site` si existe), igual que la web. Las páginas en caché no se ven afectadas: los correos se construyen al enviarse, no al pintar la página.

## Correos sin enlaces (0.8.3)

La opción **Incluir enlaces en los correos** (Opciones > Notificaciones; clave `email_include_links`; lista cerrada Sí/No, **Sí por defecto**: nada cambia al actualizar) permite que los correos de aviso no lleven ningún enlace. Con **No**:

- Los correos **no llevan ninguna URL de moderación** (publicar, despublicar, borrar, posible spam, spam, no es spam) **ni enlace al contenido o al comentario**, ni al sitio; solo informan de quién escribió qué, en qué contenido (título como texto plano) y cuándo. Esas direcciones **ni se calculan**: no se firma ningún enlace, así que no quedan «tokens huérfanos».
- **Excepción obligatoria: el enlace de baja** de los correos que lo llevan (los que reciben el autor del contenido y quienes participan en el hilo) **se mantiene**, porque quien los recibe debe poder darse de baja. Va firmado y construido con la base fiable (véase «Dirección base de los enlaces»); si no hay base fiable tampoco se pone (y el semáforo de los correos ya avisa).
- Plantillas por defecto **y personalizadas**: las etiquetas `{PUBLISH_URL}`, `{UNPUBLISH_URL}`, `{DELETE_URL}`, `{POSSIBLESPAM_URL}`, `{SPAM_URL}`, `{UNSPAM_URL}`, `{UNSUBSCRIBE_URL}`, `{CONTENT_LINK}`, `{COMMENT_LINK}` y `{SITEURL}` quedan vacías y Engage **quita después de sustituirlas** los botones (`<a class="engageemail-button…">`) y los enlaces cuyo `href` queda vacío (los enlaces de texto, como el título del contenido, se quedan como texto) y, en la parte de texto plano, las líneas «URL del comentario:» o «darse de baja:» sin valor. Así no queda ningún `href=""` ni `href="#"`. (En Joomla 5.2.0–5.2.1 con la solución alternativa de plantillas activa esa limpieza no se aplica; el resto sí.)
- Con **Sí** el correo es exactamente el de antes.
- Guardar la opción exige los mismos permisos que el resto de opciones del panel moderno (`core.admin` o `core.options` de `com_engage`) y un token; cualquier valor distinto de 0/1 se rechaza (422) y, si llegara a la base de datos, cuenta como Sí.
