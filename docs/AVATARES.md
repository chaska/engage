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

## Opción B: un plugin del grupo `engage`

Un plugin del grupo `engage` que atienda el evento `onAkeebaEngageUserAvatarURL` y devuelva la dirección de la foto (`$event->setArgument('result', array_merge($event->getArgument('result', []), [$url]))`). Si su dirección es la de un avatar genérico local de Engage (`/media/com_engage/images/avatar-generico.svg`, `avatar-blanco.svg`) se trata como genérica; cualquier otra gana a ellas.

## Comprobar

Entre como el usuario con foto en un artículo con comentarios suyos: el comentario muestra su foto. Si no sale: ¿está elegido el campo? ¿el valor es una ruta de imagen de este sitio con una de las extensiones permitidas? Los invitados no tienen foto de campo (solo Gravatar o el genérico).
