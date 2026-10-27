# Gravatar con consentimiento previo (opt-in)

Aplica desde la versión 0.6.17 del fork. **Esto es documentación técnica, no asesoramiento legal**: si el sitio usa Gravatar, qué base jurídica corresponde (consentimiento u otra) y qué debe decir la política de privacidad lo decide el responsable del sitio con su asesor.

## Por qué existe

Antes, el plugin `engage/gravatar` escribía en el HTML `https://www.gravatar.com/avatar/<sha256 del email>` y el navegador de **cada visitante** descargaba la imagen. Eso envía su dirección IP (y el User-Agent y, según el navegador, la página de origen) a gravatar.com, un tercero, **antes de que el visitante pueda decidir nada**, lo que en la UE/España es un problema de RGPD y de ePrivacy/LSSI. Además, el hash del email de cada comentarista viaja a ese tercero. La solución adoptada es pedir permiso antes (opt-in) y que, mientras no lo haya, la página no contacte con Gravatar.

## Modos (parámetro `mode` del plugin)

| Valor | Qué hace el servidor | Qué ve el visitante |
|---|---|---|
| `ask` (**por defecto**) | Sirve un avatar genérico local (`/media/com_engage/images/avatar-generico.svg`). **Desde la 0.8.3 el HTML no lleva la URL de Gravatar ni el hash del correo**, solo una referencia opaca en `data-engage-gravatar` (ver «Referencia opaca y endpoint»). | Avatar genérico + un aviso sobre los comentarios con el botón «Mostrar fotos de Gravatar (esto envía su IP a gravatar.com)». Al aceptar, el navegador (no el servidor) carga las fotos. |
| `off` | Avatar genérico local. No hay ninguna mención ni URL de Gravatar en el HTML y no se carga el JavaScript. | Avatar genérico; nunca se contacta con Gravatar. |
| `always` | Comportamiento anterior: `src="https://www.gravatar.com/avatar/<sha256 del correo>..."` directo. **Esa URL, con el hash del correo de cada comentarista, queda en el HTML público** (visible para cualquiera y para los rastreadores) y el navegador contacta con Gravatar sin preguntar. | Fotos desde el principio. **Solo si el administrador lo elige expresamente** y su política de privacidad y su base jurídica ya cubren esa transmisión y esa publicación del hash. |

- Un `mode` ausente, vacío o desconocido se trata como `ask`. **Migración**: las instalaciones que ya tenían el plugin configurado (parámetros guardados sin `mode`) pasan a `ask` al actualizar. Es un cambio de comportamiento deliberado (la opción más segura): las fotos dejan de salir solas hasta que el visitante acepte. Para volver al comportamiento anterior: Extensiones > Plugins > «Akeeba Engage – Gravatar integration» > **Modo de Gravatar = Siempre**.
- `show_notice` (1/0, por defecto 1; solo en `ask`): `0` oculta el aviso propio pero mantiene el API de JavaScript (ver más abajo). Pensado para sitios que ya tienen gestor de cookies/consentimiento.
- `default_image`, `custom_default`: en `ask`/`off` el avatar previo al consentimiento es **siempre local** (silueta genérica; la imagen personalizada si es un archivo del propio sitio; vacío con «Imagen en blanco»). Estas opciones, junto con `rating` y `force_default`, solo afectan a lo que devuelve Gravatar después de aceptar o en `always`.
- `profile_link`: ver «Enlace de perfil».
- `consent_source` (`engage` por defecto o `jbcookies`; solo con `mode=ask`, 0.6.19): quién decide. `engage` = todo lo descrito en este documento, sin cambios. `jbcookies` = la decisión la toma el módulo de cookies JBCookies (ver «Usar JBCookies»); con otro `mode` no tiene ningún efecto.
- `jbcookies_group` (texto, vacío por defecto; solo con `consent_source=jbcookies`, 0.6.19): grupo de JBCookies que debe estar activado para que «Guardar selección» conceda. Filtro estricto `[a-z0-9_-]` (máx. 64); `necessary`, `__proto__`, `constructor` y `prototype` se descartan (= vacío).

## Referencia opaca y endpoint (0.8.3)

**El problema (revisión independiente de la 0.8.2).** En modo `ask` el HTML público llevaba `data-engage-gravatar="https://www.gravatar.com/avatar/<sha256(correo)>?..."` de **cada comentarista (también invitados) antes de que nadie consintiera**. Aunque el navegador no cargase nada, el hash de cada correo quedaba publicado para cualquiera, y un hash se puede contrastar con una lista de direcciones.

**La solución.** Antes del consentimiento el HTML solo contiene una **referencia opaca** `<id del comentario>.<tamaño>.<token>` (por ejemplo `17.48.3fa9…`). El `token` es `HMAC-SHA256("engage-gravatar-v1|<id>|<tamaño>", secreto del sitio)` truncado a 32 caracteres hexadecimales: no contiene nada del correo, no se puede calcular sin el secreto de `configuration.php` y no sirve para nada fuera de este sitio. Es el **mismo para todos los visitantes** (el HTML sigue siendo cacheable por Joomla y por `system/engagecache`). Tras aceptar, `gravatar.js` cambia las referencias por las URL reales con dos peticiones al propio sitio:

1. `GET index.php?option=com_engage&task=avatar.token` → `{"ok":true,"token":"<token CSRF de Joomla>"}` (sin caché: `no-store`, `Vary: Cookie`).
2. `POST index.php?option=com_engage&task=avatar.resolve` con `c=<ref>,<ref>,...` (hasta 100) y el token CSRF → `{"ok":true,"urls":{"17.48":"https://www.gravatar.com/avatar/...","18.48":null}}`.

El servidor **solo responde por los comentarios que ese visitante vería en la página**: publicados, con toda la cadena de padres publicada y de un contenido que puede ver (la misma regla que las reacciones; los moderadores que pueden ver contenido sin publicar reciben lo suyo por el nivel del contenido, nunca el de un comentario sin publicar o spam). Un `id` inexistente, un comentario que no se puede ver y un token falso dan **exactamente la misma respuesta** (`null`), de modo que no se descubre qué comentarios existen. La respuesta no repite nada de lo recibido. Defensas del endpoint: solo `POST` con token CSRF de la sesión (token falso, ausente, de otra sesión o en la URL = 403; `GET` = 405), se rechaza la cabecera `Sec-Fetch-Site: cross-site`, límite de **240 peticiones por minuto** por persona (por usuario, o por IP para invitados; 429 al pasarlo), máximo 100 referencias y 10 contenidos distintos por petición (400 generico), y cualquier otra tarea del controlador `avatar` responde 403. La URL que sale pasa antes por la lista blanca de Gravatar en PHP y otra vez en el JavaScript.

Los archivos son `backend/src/Helper/AvatarResolver.php` (lógica pura probada), `frontend/src/Controller/AvatarController.php` y `media/js/gravatar.js`. Pruebas: `tests/44-gravatar-referencia-opaca.php`, `tests/22-gravatar-consentimiento.php`, `tests/24-gravatar-jbcookies.*` y, en un Joomla real, `tests/joomla-live/05-gravatar.sh` y `tests/joomla-live/22-revision-082.sh` (sección H4).

**Modo `always`.** Mantiene el comportamiento anterior **solo porque el administrador lo elige**: la URL con el hash del correo va en el `src` del HTML público (y el enlace de perfil, si está activado). Es una decisión de privacidad suya; el modo por defecto, `ask`, y `off` no publican nada.

**Imagen personalizada (`d=`).** La dirección absoluta de la imagen por defecto propia se construye con el `live_site` de la configuración de Joomla (nunca con la cabecera `Host` de la petición); si no hay `live_site`, se usa la imagen estándar de Gravatar.

## Cómo funciona

1. `Gravatar::onAkeebaEngageUserAvatarURL` devuelve la URL local y, en `ask`, además la de Gravatar en un argumento aparte (`deferred`) del evento (solo en el servidor). `Avatar::getUserAvatarData()` (componente) los combina y **sustituye la URL por la referencia opaca**; `default_list.php` pinta `<img src="/media/com_engage/images/avatar-generico.svg" data-engage-gravatar="17.48.<token>">`, con el valor escapado. La URL real solo sale de `Avatar::getDeferredURL()` cuando el endpoint la pide. No hay `srcset`, `<picture>`, `<link rel=preload/prefetch>` ni la URL ni el hash en ningún atributo del HTML.
2. `media/com_engage/js/gravatar.js` (activo `com_engage.gravatar`, registrado solo cuando hay avatares con `data-engage-gravatar`) muestra el aviso dentro de `#akengage-comments-section`. **La decisión es siempre del cliente**: nada depende de la sesión ni de cookies del servidor, así que es compatible con la caché de página de Joomla y con `system/engagecache` (todos los visitantes reciben el mismo HTML).
3. Al aceptar: guarda `localStorage["engage_gravatar_consent"] = "1"` (dentro de `try/catch`; sin cookies; la decisión no se envía al servidor) y pide al endpoint las URL de las referencias de la página (ver arriba). Sustituye el `src` de cada imagen por la URL recibida, **solo si pasa una lista blanca estricta** (`^https://www.gravatar.com/avatar/<32-64 hex>(?query)?$`; la misma expresión en PHP y en JS) y con `referrerpolicy="no-referrer"`. Si ya había consentimiento previo, carga directamente.
4. «Dejar de mostrar fotos de Gravatar» borra la clave y devuelve el avatar local. Con `localStorage` bloqueado la elección solo dura hasta recargar la página.
5. Textos: sistema de idioma de Joomla (`COM_ENGAGE_GRAVATAR_*`, en-GB y es-ES) pasados con `Text::script` / `Joomla.Text._`. El JS usa `textContent` y atributos, nunca `innerHTML` ni `eval`.

## Enlace de perfil (`onAkeebaEngageUserProfileURL`)

El enlace `https://www.gravatar.com/<hash>` lo pulsa el visitante (no carga solo) pero hace llegar a Gravatar el hash del email. Como el servidor no puede saber si hubo consentimiento: en `off` y en `ask` **no se emite nunca** (el avatar no lleva enlace); solo se emite en `always` (si `profile_link` = Sí).

## API de JavaScript y gestores de cookies

El script expone:

```js
window.AkeebaEngageGravatar = { grant(), revoke(), isGranted() };
```

y escucha en `document` el evento `engage:gravatar-consent` con `detail: {granted: true|false}` (cualquier otro `detail` se ignora). Además emite `engage:gravatar-changed` (`detail.granted`) cada vez que cambia el estado.

Conexión genérica con un gestor de consentimiento (el gestor es quien debe saber su categoría/nombre del servicio, p. ej. «terceros: avatares» o «Gravatar»):

```js
// Cuando el gestor concede o retira el consentimiento de la categoría correspondiente:
document.dispatchEvent(new CustomEvent('engage:gravatar-consent', {detail: {granted: true}}));   // o false
// Si el gestor ejecuta su código antes de que cargue gravatar.js, espere a que exista el API:
document.addEventListener('DOMContentLoaded', function () {
    if (window.AkeebaEngageGravatar) { /* leer el estado guardado por el gestor y llamar grant()/revoke() */ }
});
```

Notas:
- `grant()`/`revoke()` también guardan/borran `engage_gravatar_consent` en localStorage. Si el gestor es la única fuente de verdad, llame a `revoke()` al detectar que no hay consentimiento en cada carga (así no queda una elección antigua).
- Si el sitio ya tiene gestor de cookies, ponga **Mostrar el aviso de consentimiento = No** (`show_notice=0`): no se pinta el aviso propio, pero el API y el evento siguen activos. En ese caso el visitante no tiene el botón «Dejar de mostrar fotos»: la retirada la gestiona el gestor.
- Si `Content-Security-Policy` limita `img-src`, añada `https://www.gravatar.com` (solo hace falta tras el consentimiento).

## Usar JBCookies

Aplica desde la versión 0.6.19. **Esto es documentación técnica, no asesoramiento legal.** Escrita para quien no es técnico: los pasos están al final de esta sección.

### Qué es y qué hace (y qué NO hace) JBCookies

JBCookies (`mod_jbcookies`, de JoomBall) es un módulo de Joomla que muestra el aviso de cookies. Comprobado leyendo su código (versión 6.0.2 del ZIP, el manifiesto dice 6.0.1) y en un Joomla 6.1.4 real:

- Guarda la decisión del visitante en una cookie **legible por JavaScript** llamada `jbcookies` (valor JSON codificado: `{"status":"allow"|"deny"|"custom","preferences":{"<grupo>":0|1,...}}`; también acepta el valor antiguo en texto `allow`/`deny`/`custom`). Dura 365 días por defecto (campo «duration_cookie_days»), `path=/`.
- Avisa en el navegador con el evento `jbcookies:update` (en `document`, `detail: {status, preferences}`) al pulsar *Aceptar todo*, *Rechazar* o *Guardar selección*.
- Al pulsar el icono de «cambiar mi decisión» **borra la cookie sin avisar con ningún evento** y vuelve a mostrar el aviso.
- Los grupos de la ventana de *Ajustes* son **fijos**: `necessary` (obligatorio, siempre activo), `analytics`, `marketing` y `unassigned` («sin clasificar»). Aunque el campo «Grupos de cookies» del módulo parece permitir crear grupos, en la versión probada el código solo usa esos cuatro (`InventoryHelper::getDefaultGroups()`); el administrador solo puede asignar cookies a ellos. **No se puede crear un grupo propio `terceros` o `avatares`.** Además, **todos los interruptores aparecen activados de entrada**: si el visitante pulsa «Guardar selección» sin tocar nada, JBCookies guarda `custom` con todos los grupos a 1 (esto es del módulo, no de Engage; valore con su asesor si eso es un consentimiento válido).
- **JBCookies NO bloquea por sí mismo scripts de terceros** (Google Analytics, Meta Pixel, Gravatar...). Solo guarda la preferencia, borra al rechazar las cookies de su inventario y avisa por el evento. **Cada script de terceros debe leer esa preferencia**; si su analítica sigue cargándose aunque el visitante rechace, JBCookies no lo arregla. Esta integración solo cubre Gravatar.
- Usa jQuery y el modal de Bootstrap (la plantilla debe cargarlos) y arranca en `DOMContentLoaded`.

### Cómo decide Engage con `consent_source=jbcookies`

Regla de concesión (se aplica en el navegador, en `gravatar.js`; cualquier duda = NO):

| Cookie `jbcookies` | ¿Se cargan las fotos? |
|---|---|
| `status` = `allow` (o el valor antiguo `allow`) | Sí |
| `status` = `custom` y `jbcookies_group` **no vacío** y `preferences[grupo]` es exactamente `1` (o `true`) | Sí |
| `custom` con el grupo a `0`, ausente, `"1"` (texto), con `preferences` que no sea un objeto, o con `jbcookies_group` vacío | No |
| `deny`, sin cookie, JSON roto, valor de más de 4096 caracteres, `status` raro (`ALLOW`, `allow `, lista, número...), dos cookies `jbcookies` donde alguna no concede | No |

- El plugin **no pinta su propio aviso** (equivale a `show_notice=0`): el único aviso es el de JBCookies.
- Se lee la cookie al cargar la página, al recibir `jbcookies:update` (se vuelve a leer **la cookie**, no se fía del `detail` del evento) y, solo para **retirar** fotos, al volver el foco a la pestaña (`focus`, `visibilitychange`, `pageshow`) y al pulsar el icono «cambiar mi decisión» (`.jb-cookie-decline`).
- **No se usa `localStorage`** en este modo (ni se lee ni se escribe `engage_gravatar_consent`).
- Sin el módulo instalado/publicado, o sin cookie, **no se carga ninguna foto** (seguro por defecto). Ojo: si el módulo se desinstala pero el visitante conserva una cookie `jbcookies=allow` antigua, esa cookie sigue valiendo.
- La API (`window.AkeebaEngageGravatar`) y el evento `engage:gravatar-consent` siguen existiendo, pero **no pueden contradecir al gestor**: `grant()` (y `engage:gravatar-consent` con `granted:true`) solo vuelve a leer la cookie y concede únicamente si la regla de arriba lo permite; `revoke()` (y `granted:false`) siempre retira las fotos (es el lado seguro), pero solo hasta el próximo `jbcookies:update` o recarga. Sigue emitiéndose `engage:gravatar-changed` cuando cambia el estado.
- Lista blanca de URL (`https://www.gravatar.com/avatar/<hash>`), `referrerpolicy="no-referrer"` y ausencia de `innerHTML`/`eval`: igual que antes.
- El ajuste llega al JavaScript en atributos `data-engage-gravatar-source="jbcookies"` y `data-engage-gravatar-group="<grupo>"` del `<img>` (escapados; el grupo ya filtrado en el servidor y otra vez en el navegador). Así sobreviven a la caché de página de Joomla (probado) y no hay nada que escapar en un bloque de script.
- El modo `engage` (por defecto) no cambia nada: ni en el HTML ni en el JavaScript (probado: ignora la cookie `jbcookies`).

### Limitaciones conocidas

1. «Cambiar mi decisión» no emite evento. Engage lo detecta solo al hacer clic en ese icono (selector `.jb-cookie-decline` del módulo; si JoomBall cambia esa clase, no se detectará) y al volver el foco a la pestaña, sin sondeo continuo. Si no se detecta, las fotos ya cargadas permanecen hasta recargar la página; nunca se cargan fotos nuevas sin consentimiento.
2. Si el navegador bloquea las cookies, JBCookies no puede guardar la decisión y Engage tampoco carga fotos (la cookie es la única fuente de verdad).
3. JBCookies 6.0.x no permite grupos propios (ver arriba). Con `jbcookies_group` vacío solo «Aceptar todo» concede.
4. En Joomla 6.1.4 se comprobó que **reinstalar/actualizar** JBCookies encima de sí mismo falla en su `script.php` (línea ~119, usa la clase retirada `Joomla\CMS\Filesystem\File`: «Class not found»); la instalación nueva funciona. Es un problema del módulo, no de Engage; avise a JoomBall o actualice a mano.
5. **Licencia**: el ZIP de JBCookies es inconsistente. `LICENSE` y `mod_jbcookies.xml` dicen GPL v3, pero su `README.md` dice «License: Non-Commercial». **El fork NO distribuye JBCookies** (ni lo copia al repositorio ni lo incluye en el paquete): cada sitio debe instalarlo por su cuenta tras aclarar la licencia con JoomBall. Las pruebas lo usan solo desde una ruta local que se pasa por variable de entorno.

### Pasos exactos (sin tecnicismos)

Antes: instale y publique el módulo JBCookies como siempre (Extensiones > Gestionar > Instalar; luego Contenido > Módulos del sitio > JBCookies: *Estado* Publicado, una *Posición* de su plantilla, *Asignación de menú* «En todas las páginas»). Compruebe que en la web sale el aviso con los botones *Ajustes* y *Aceptar*.

1. En el panel de administración vaya a **Sistema > Plugins** (o Extensiones > Plugins), busque **«Akeeba Engage – Gravatar»** y ábralo.
2. **Modo de Gravatar (Gravatar mode)**: déjelo en **«Preguntar al visitante primero (recomendado)»** (`ask`).
3. **Quién decide (consent source)**: elija **«Módulo JBCookies (gestor de cookies)»**. (Este campo solo aparece con el modo «Preguntar».)
4. **Grupo de JBCookies (opcional)**: **déjelo vacío** para empezar. Con el campo vacío las fotos de Gravatar solo se cargan cuando el visitante pulsa **«Aceptar todo»** en el aviso de JBCookies. Si quiere que también cuente «Guardar selección», escriba `marketing` (o `analytics`) y use ese grupo para lo que corresponda en su política: JBCookies no deja crear un grupo `terceros` ni `avatares`; en ese caso las fotos se cargan cuando el visitante deja **activado ese interruptor** en *Ajustes* y pulsa *Guardar selección*. No escriba `necessary`: está siempre activo y no se acepta.
5. Pulse **Guardar y cerrar**.
6. Si su web usa **caché de página** (Sistema > Limpiar caché, o plugin «Sistema – Caché de página»), **vacíe la caché**.
7. Compruebe en una ventana de incógnito: al entrar **no** deben verse las fotos de Gravatar (avatar genérico) ni el aviso propio de Engage; solo el de JBCookies. Pulse **Aceptar todo**: aparecen las fotos. Recargue: siguen. Pulse el icono de cookies (esquina de la pantalla) para cambiar la decisión: las fotos se retiran.
8. Ponga en su política de privacidad que Gravatar (servicio de terceros) recibe la IP del visitante si acepta.

Recuerde: **JBCookies no bloquea otros scripts por sí solo.** Para que su analítica (Google Analytics u otra) respete el rechazo, esa analítica debe leer la cookie `jbcookies` (o su plugin/etiqueta debe estar condicionado a ella); si no, seguirá cargándose.

## Caché de página

Si activa la caché de página de Joomla y cambia el modo del plugin, **vacíe la caché**: las páginas ya cacheadas conservan el HTML con el modo anterior (p. ej. un `src` directo de `always`).

## Lo que no hace

- No bloquea el contacto con Gravatar por otros medios del sitio (otros plugins, plantilla, campo personalizado de avatar con una URL externa).
- El hash del email (sha256) está en `data-engage-gravatar` aunque no haya consentimiento: no se contacta con Gravatar, pero el hash va en el HTML público (no es un secreto, pero identifica el email; no se registra en logs). Si eso le molesta, use `off`.
- No es un banner de cookies ni sustituye a su política de privacidad.

## Pruebas

`tests/22-gravatar-consentimiento.php` (unitarias), `tests/joomla-live/05-gravatar.sh` (Joomla real por HTTP y navegador Chromium con peticiones de red interceptadas). Resultados: `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md`, sección 7.

0.6.19 (JBCookies): `tests/24-gravatar-jbcookies.php` + `tests/24-gravatar-jbcookies.js` (parte PHP del plugin y `gravatar.js` real en un contexto `vm` con una tabla de la regla de concesión con entradas hostiles) y `tests/joomla-live/07-jbcookies.sh` (instala JBCookies desde un ZIP indicado en `JBCOOKIES_ZIP`, sin copiarlo al repositorio, y pulsa sus botones reales en Chromium). Resultados: sección 10 de `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md`.

## El servidor decide (0.9.13)

Hasta la 0.9.12 el consentimiento vivía solo en el navegador (`localStorage` o la cookie de JBCookies) y el servidor entregaba la dirección de Gravatar a **cualquiera con un token CSRF** (también un robot o un invitado), con el hash del correo de quien comentó. Desde la 0.9.13, en el modo `ask`:

- **Sin sesión no hay Gravatar.** La página de un invitado ya no lleva la referencia diferida (`data-engage-gravatar`) ni el aviso: solo el avatar genérico (o la foto de un plugin). `avatar.resolve` responde 403 `login` a quien no tiene sesión.
- **El consentimiento se guarda en el servidor, ligado a la cuenta** (tabla `#__user_profiles` de Joomla, clave `engagegravatar.consent`; sin SQL nuevo). Solo lo escribe `avatar.consent`: petición POST con token CSRF, solo con sesión, valor cerrado `grant=1` (concede) o `grant=0` (revoca), con el mismo límite de frecuencia. Al pulsar «Mostrar fotos de Gravatar», `gravatar.js` primero guarda el consentimiento y solo entonces pide las fotos; «Dejar de mostrar» lo borra. Con JBCookies, la decisión del módulo sigue mandando y se refleja en el servidor la primera vez que concede.
- `avatar.resolve` exige sesión **y** consentimiento guardado (403 `consent` si no lo hay: el navegador vuelve a preguntar aunque recordase un «sí» de otro momento). Mantiene el límite de 240 peticiones por minuto, las comprobaciones de visibilidad del comentario y la lista blanca de URL.
- La referencia diferida sigue siendo un HMAC del id y el tamaño: no contiene ni permite derivar nada del correo.
- El modo `always` sigue siendo público por diseño (la URL con el hash va en el HTML). El borrado de la cuenta elimina la clave de consentimiento.

**Límite que queda, dicho con honestidad:** una persona que se registre en el sitio y consienta puede obtener, por comentario visible, la dirección de Gravatar (que lleva el hash del correo) de los autores, con el límite de frecuencia. Cierra el acceso de robots e invitados, no el de cuentas registradas que consienten; si el registro es abierto, valore el modo `off`.
