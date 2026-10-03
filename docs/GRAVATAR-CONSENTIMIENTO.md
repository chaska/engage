# Gravatar con consentimiento previo (opt-in)

Aplica desde la versión 0.6.17 del fork. **Esto es documentación técnica, no asesoramiento legal**: si el sitio usa Gravatar, qué base jurídica corresponde (consentimiento u otra) y qué debe decir la política de privacidad lo decide el responsable del sitio con su asesor.

## Por qué existe

Antes, el plugin `engage/gravatar` escribía en el HTML `https://www.gravatar.com/avatar/<sha256 del email>` y el navegador de **cada visitante** descargaba la imagen. Eso envía su dirección IP (y el User-Agent y, según el navegador, la página de origen) a gravatar.com, un tercero, **antes de que el visitante pueda decidir nada**, lo que en la UE/España es un problema de RGPD y de ePrivacy/LSSI. Además, el hash del email de cada comentarista viaja a ese tercero. La solución adoptada es pedir permiso antes (opt-in) y que, mientras no lo haya, la página no contacte con Gravatar.

## Modos (parámetro `mode` del plugin)

| Valor | Qué hace el servidor | Qué ve el visitante |
|---|---|---|
| `ask` (**por defecto**) | Sirve un avatar genérico local (`/media/com_engage/images/avatar-generico.svg`). La URL de Gravatar va solo en el atributo `data-engage-gravatar` de la imagen. | Avatar genérico + un aviso sobre los comentarios con el botón «Mostrar fotos de Gravatar (esto envía tu IP a gravatar.com)». Al aceptar, el navegador (no el servidor) carga las fotos. |
| `off` | Avatar genérico local. No hay ninguna mención ni URL de Gravatar en el HTML y no se carga el JavaScript. | Avatar genérico; nunca se contacta con Gravatar. |
| `always` | Comportamiento anterior: `src="https://www.gravatar.com/avatar/..."` directo. | Fotos desde el principio. Úselo solo si su política de privacidad y su base jurídica ya cubren esa transmisión. |

- Un `mode` ausente, vacío o desconocido se trata como `ask`. **Migración**: las instalaciones que ya tenían el plugin configurado (parámetros guardados sin `mode`) pasan a `ask` al actualizar. Es un cambio de comportamiento deliberado (la opción más segura): las fotos dejan de salir solas hasta que el visitante acepte. Para volver al comportamiento anterior: Extensiones > Plugins > «Akeeba Engage – Gravatar integration» > **Modo de Gravatar = Siempre**.
- `show_notice` (1/0, por defecto 1; solo en `ask`): `0` oculta el aviso propio pero mantiene el API de JavaScript (ver más abajo). Pensado para sitios que ya tienen gestor de cookies/consentimiento.
- `default_image`, `custom_default`: en `ask`/`off` el avatar previo al consentimiento es **siempre local** (silueta genérica; la imagen personalizada si es un archivo del propio sitio; vacío con «Imagen en blanco»). Estas opciones, junto con `rating` y `force_default`, solo afectan a lo que devuelve Gravatar después de aceptar o en `always`.
- `profile_link`: ver «Enlace de perfil».

## Cómo funciona

1. `Gravatar::onAkeebaEngageUserAvatarURL` devuelve la URL local y, en `ask`, además la de Gravatar en un argumento aparte (`deferred`) del evento. `Avatar::getUserAvatarData()` (componente) los combina y `default_list.php` pinta `<img src="/media/com_engage/images/avatar-generico.svg" data-engage-gravatar="https://www.gravatar.com/avatar/<hash>?...">`, con el valor escapado. No hay `srcset`, `<picture>`, `<link rel=preload/prefetch>` ni la URL en ningún atributo que el navegador cargue solo.
2. `media/com_engage/js/gravatar.js` (activo `com_engage.gravatar`, registrado solo cuando hay avatares con `data-engage-gravatar`) muestra el aviso dentro de `#akengage-comments-section`. **La decisión es siempre del cliente**: nada depende de la sesión ni de cookies del servidor, así que es compatible con la caché de página de Joomla y con `system/engagecache` (todos los visitantes reciben el mismo HTML).
3. Al aceptar: guarda `localStorage["engage_gravatar_consent"] = "1"` (dentro de `try/catch`; sin cookies; no se envía nada al servidor) y sustituye el `src` de cada imagen por la URL de su atributo, **solo si pasa una lista blanca estricta** (`^https://www.gravatar.com/avatar/<32-64 hex>(?query)?$`; la misma expresión en PHP y en JS) y con `referrerpolicy="no-referrer"`. Si ya había consentimiento previo, carga directamente.
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

## Caché de página

Si activa la caché de página de Joomla y cambia el modo del plugin, **vacíe la caché**: las páginas ya cacheadas conservan el HTML con el modo anterior (p. ej. un `src` directo de `always`).

## Lo que no hace

- No bloquea el contacto con Gravatar por otros medios del sitio (otros plugins, plantilla, campo personalizado de avatar con una URL externa).
- El hash del email (sha256) está en `data-engage-gravatar` aunque no haya consentimiento: no se contacta con Gravatar, pero el hash va en el HTML público (no es un secreto, pero identifica el email; no se registra en logs). Si eso le molesta, use `off`.
- No es un banner de cookies ni sustituye a su política de privacidad.

## Pruebas

`tests/22-gravatar-consentimiento.php` (unitarias), `tests/joomla-live/05-gravatar.sh` (Joomla real por HTTP y navegador Chromium con peticiones de red interceptadas). Resultados: `docs/RESULTADOS-PRUEBAS-JOOMLA-REAL.md`, sección 7.
