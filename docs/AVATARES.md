# Avatares y fotos de usuario (0.9.8)

Junto a cada comentario Engage muestra, por este orden:

1. **La foto del usuario**, si el comentario es de un usuario registrado y hay una (campo de usuario o plugin, abajo).
2. **Gravatar**, según el modo del plugin (ver `docs/GRAVATAR-CONSENTIMIENTO.md`): en «siempre» la foto de Gravatar; en «preguntar» (por defecto) el avatar genérico y, si la persona lo acepta, su Gravatar; en «desactivado» el genérico.
3. **El avatar genérico** de Engage.

Desde la 0.9.8 **una foto real gana siempre a un avatar genérico**, da igual el orden de los plugins del grupo `engage`.

## Opción A: un campo personalizado de usuario

1. En Joomla: **Usuarios > Campos** > Nuevo. Contexto *Usuario*; tipo **Medios (imagen)**, *Lista de imágenes*, *URL* o *Texto* (con la ruta de la imagen). Publicado, y que el usuario pueda editarlo en su perfil.
2. En Engage: **Opciones > Diseño > «Campo de usuario con la foto»** y elija ese campo (por defecto «Ninguno»). Solo un superadministrador de Engage puede cambiarlo.
3. Cada usuario sube su foto en su perfil. Sale en todos sus comentarios (con o sin Gravatar, en cualquier modo).

**Qué se acepta (el valor lo escribe el propio usuario, así que no es de fiar):** solo una imagen **de este mismo sitio** (ruta relativa como `images/avatares/foto.jpg`, o una URL https de la dirección base del sitio), de tipo `jpg`, `jpeg`, `png`, `gif`, `webp` o `avif`. Se rechazan sin avisar (y se usa el siguiente método): `javascript:`, `data:` y cualquier otro esquema, otros dominios (no se cargan fotos de terceros: privacidad), rutas con `..` (también codificado), comillas, espacios, caracteres de control, otras extensiones (`.svg`, `.php`…) y rutas de más de 512 caracteres. El HTML que se imprime va escapado.

## Opción B: un plugin del grupo `engage` (contrato para plugins de foto)

**Evento:** `onAkeebaEngageUserAvatarURL`. **Argumentos** (en este orden): `0` = `Joomla\CMS\User\User` del autor del comentario; `1` = tamaño en píxeles (entero); `2` = **contexto** (cadena, 0.9.9). Se responde añadiendo una cadena a `result`: `$event->setArgument('result', array_merge($event->getArgument('result', []), [$url]))`. Para Gravatar existe además el argumento `deferred` (solo lo usa ese plugin).

**Contexto:** Engage solo llama al evento con contexto `page`: una página interactiva que ve **una** persona (la lista de comentarios, la pantalla de administración). En cualquier otro caso (**correos**, vista previa y correo de prueba, exportaciones, cualquier salida que se guarde o se envíe a otra persona) **no se llama a los plugins**: allí solo se usan fuentes públicas por naturaleza (el campo de usuario validado, como dirección absoluta de la base fiable, o el avatar genérico de Engage). Un plugin puede respetar el contexto (`$event->getArgument(2)`), pero Engage ya no se lo pregunta fuera de `page`.

**Qué se acepta como resultado:** cualquier cadena no vacía que ponga el administrador del sitio con ese plugin. A diferencia del campo de usuario (que lo escribe la persona y se valida de forma estricta), **no se exige extensión ni se filtra**: vale `/index.php?option=com_mifoto&task=photo.show&u=…&s=64&v=…` (con subcarpeta delante si la hay). Se imprime tal cual en `<img src>` **escapada** (`&amp;`).

**Foto que depende de quien mira** (p. ej. MiFoto, que sirve la imagen con la cookie de sesión de quien la pide y responde 404 vacío si no tiene permiso): es correcto, y Engage lo respeta así: la dirección es la misma para todos, **la imagen la decide el servidor de la foto al cargarla**. Por eso Engage:

- la pide **solo** en la página interactiva (contexto `page`) y no la guarda en la base de datos, ni en un fichero, ni la manda por correo, ni la incluye en exportaciones;
- el HTML de la lista de comentarios de una persona registrada **nunca se sirve a otra**: Joomla no pone en la caché de páginas ni en la de vistas lo que ve una persona con sesión (los tests con caché conservadora y progresiva, con y sin caché de páginas y el plugin Engage Cache, y cuatro espectadores —invitado, propietario, moderador y otro registrado— lo comprueban). Lo que ve un **invitado** sí puede cachearse para todos los invitados, y para un invitado la foto depende de lo que el plugin conteste a un invitado: no ponga en la dirección nada que identifique a otra persona distinta del autor del comentario.

**Prioridad entre respuestas:** campo de usuario (si está elegido y tiene una foto válida) > cualquier **foto** de un plugin (la primera) > Gravatar > avatar genérico local. Las fotos de plugin ganan en los tres modos de Gravatar y con cualquier orden de plugins; cuando gana una foto no hay `data-engage-gravatar` ni aviso de consentimiento para ese comentario.

**Genéricos:** las rutas locales `/media/com_engage/images/avatar-generico.svg` y `avatar-blanco.svg` se tratan como «sin foto»; cualquier otra cosa cuenta como foto (el nombre del genérico en la *consulta* de una URL no cuenta).

## Comprobar

Entre como el usuario con foto en un artículo con comentarios suyos: el comentario muestra su foto. Si no sale: ¿está elegido el campo? ¿el valor es una ruta de imagen de este sitio con una de las extensiones permitidas? Los invitados no tienen foto de campo (solo Gravatar o el genérico).

## Qué acepta Engage del resultado de un plugin de fotos (0.9.12)

El resultado de un plugin del grupo `engage` va a un `<img src>`: es código de un tercero y no se da por bueno. `Avatar::pickAvatar` solo acepta:

- una **ruta del propio sitio que empiece por una sola «/»** (nunca «//» ni «/\», que para el navegador son otro servidor). La dirección de MiFoto (`/index.php?option=com_mifoto&view=...&id=...`) sigue valiendo;
- una dirección **https de la base de confianza** del sitio (la misma que usan los correos);
- una dirección de avatar de **Gravatar** (`https://www.gravatar.com/avatar/<hash>`).

Se descarta todo lo demás: otros dominios, `http:`, `javascript:`, `data:`, rutas relativas sin «/», cadenas con espacios, comillas, ángulos, barras invertidas o caracteres de control, y las de más de 2000 caracteres. Si todo lo que devuelven los plugins se descarta, se usa el avatar genérico local.
