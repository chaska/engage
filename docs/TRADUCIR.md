# Cómo traducir Engage a su idioma

Engage viene en inglés (en-GB) y español (es-ES). Cualquiera puede añadir otro idioma. No hace falta saber programar: solo traducir textos.

Esta guía es para alguien que conoce Joomla y poco de informática. Si algo no se entiende, abra un [issue](https://github.com/chaska/engage/issues) y se mejora la guía.

## En resumen

1. Cree el idioma con una orden (o copie las carpetas a mano).
2. Traduzca el texto que hay entre comillas, nada más.
3. Compruebe con la herramienta.
4. Pruébelo en su Joomla.
5. Envíelo.

## 1. Crear el idioma nuevo

Necesita PHP en línea de comandos y una copia del proyecto (descárguela de GitHub o haga un *fork*).

```
php build/idiomas.php plantilla fr-FR
```

Cambie `fr-FR` por su código: dos letras en minúscula, un guion y dos letras en mayúscula (`pt-BR`, `de-DE`, `it-IT`). Debe ser el mismo código que usa Joomla para ese idioma.

La orden copia en-GB en una carpeta nueva `fr-FR` dentro de **cada** extensión y añade los archivos a los manifiestos (los `.xml` que le dicen a Joomla qué archivos de idioma instalar). Después solo queda traducir.

**Si prefiere hacerlo a mano:** dentro de cada carpeta `language/` copie `en-GB` con el nombre nuevo y ejecute `php build/idiomas.php manifiestos fr-FR` para que los manifiestos los incluyan.

Son 13 extensiones y 14 carpetas `language/en-GB` que hay que copiar (el componente tiene dos: administración y parte pública):

| Extensión | Carpeta |
|---|---|
| Componente, administración | `src/component/backend/language/` |
| Componente, parte pública | `src/component/frontend/language/` |
| Módulo «Últimos comentarios» | `src/modules/site/engage_latest/language/` |
| Paquete | `src/package/language/` |
| Plugins (10) | `src/plugins/<grupo>/<nombre>/language/` |

Cada carpeta tiene archivos `.ini` y `.sys.ini`. Los **dos** hay que traducir. Los `.sys.ini` son los textos que Joomla enseña al instalar y en las listas de extensiones. Algunos archivos de en-GB están vacíos (no tienen textos): se copian igual y no hay nada que traducir.

## 2. Qué traducir y qué no

Cada línea tiene este aspecto:

```
COM_ENGAGE_COMMENTS_SHOW="Show comments"
```

**Traduzca** solo lo que está entre comillas: `"Show comments"`.

**No toque:**

- El nombre de la clave (`COM_ENGAGE_COMMENTS_SHOW`). Si lo cambia, Joomla no encuentra el texto.
- Los marcadores: `%s`, `%d`, `%1$s`, `%2$d`, `{summary}`. Se sustituyen por nombres, números o fechas. Deben salir **los mismos y en la misma cantidad**. Puede cambiar su orden dentro de la frase si usa `%1$s`, `%2$s` (con número).
- Las etiquetas HTML (`<p>`, `<strong>`, `<a href="...">`). Déjelas donde están y traduzca solo el texto entre ellas. No se pueden añadir etiquetas, atributos ni direcciones web nuevos.
- Los nombres propios y de productos: Engage, Joomla, Akismet, Gravatar, HTML Purifier, JBCookies.
- Las líneas que empiezan por `;` (son comentarios) y las que están entre corchetes (`[Common]`).

**Comillas dentro del texto:** no escriba `"` dentro de un texto. Use comillas del idioma («texto», “texto”) o escriba `\"`.

**Saltos de línea:** cada texto va en **una sola línea**. Guarde el archivo en UTF-8 **sin BOM**.

## 3. Tratamiento y glosario

Use un tono claro y cercano, sin jerga. En idiomas con tratamiento formal e informal, use el **formal** (en español, «usted»): es una herramienta para administrar un sitio. Sea coherente en todos los archivos.

Glosario de partida (adáptelo a como Joomla traduce estos términos en su idioma; mire su propio Joomla):

| Inglés | Español (es-ES) |
|---|---|
| comment | comentario |
| reply | respuesta |
| moderate / moderator | moderar / moderador |
| publish / unpublish | publicar / despublicar |
| spam / possible spam | spam / posible spam |
| email template | plantilla de email |
| report (a comment) | denunciar |
| reaction, like, favourite | reacción, me gusta, favorito |
| control panel | panel de control |
| options | opciones |
| permissions | permisos |
| guest / registered user | invitado / usuario registrado |
| unsubscribe | darse de baja |

Mantenga igual un mismo término en toda la traducción. Si Joomla ya tiene una palabra para algo en su idioma (por ejemplo «Publicado» o «Papelera»), use esa.

## 4. Comprobarlo con la herramienta

```
php build/idiomas.php comprobar fr-FR
```

Compara su traducción con en-GB (la fuente de verdad) en todas las extensiones. Falla (y dice dónde) si:

- falta o sobra alguna clave;
- los marcadores no coinciden;
- hay un texto vacío donde el original tiene texto;
- aparece una etiqueta HTML, un atributo o una dirección web que no está en el original de esa clave;
- hay `<script>`, `on...=`, `javascript:`, `data:`, caracteres de control o comillas sin escapar;
- el archivo no tiene el formato `CLAVE="texto"`, o falta en el manifiesto.

Con `php build/idiomas.php comprobar todos` se comprueban todos los idiomas. También avisa (sin fallar) si más de la mitad de los textos siguen iguales al inglés. `php build/build.php` repite esta comprobación antes de empaquetar: un idioma roto no llega al ZIP.

## 5. Probarlo en su Joomla

1. Su Joomla debe tener instalado el idioma (el paquete de idioma de Joomla para `fr-FR`) y elegido en Sistema › Idiomas de contenido o en su perfil.
2. Genere el paquete: `php build/build.php`. Queda en `dist/pkg_engage-<versión>.zip`.
3. En Joomla, Sistema › Instalar › Extensiones, suba el ZIP (se instala encima).
4. Cambie su idioma de administración al nuevo y recorra: Panel de control, Comentarios, Opciones (todas las categorías), Plantillas de correo, Permisos y Denuncias.
5. Mire también la web pública: formulario de comentarios, botones y aviso de privacidad.

Busque textos cortados, que se salgan de su sitio o que suenen raros.

## 6. Enviarlo

**Opción A: pull request.** Haga un *fork* del repositorio en GitHub, cree una rama, suba las carpetas del idioma y los manifiestos cambiados y abra un *pull request*. La plantilla le da una lista de comprobación.

**Opción B: issue.** Si no usa Git, abra un [issue de traducción](https://github.com/chaska/engage/issues/new?template=traduccion.yml), adjunte los archivos (puede comprimirlos en un ZIP) y alguien de la comunidad los incorporará.

No incluya datos personales en los archivos ni en el texto del envío.

## 7. Criterios de aceptación

Una traducción se acepta cuando:

1. **Está completa:** todas las extensiones, todos los archivos `.ini` y `.sys.ini`, todas las claves.
2. **Pasa la herramienta:** `php build/idiomas.php comprobar xx-XX` sin errores.
3. **Alguien la ha revisado:** una persona que hable el idioma lee la traducción (no solo la herramienta) y no encuentra errores de sentido ni textos engañosos.
4. **Se ha probado** en un Joomla real, al menos el panel, las opciones y el formulario público.
5. **Es su trabajo y se publica con la misma licencia** que el proyecto: GNU GPL v3 o posterior.

Un idioma a medias no se incorpora (se queda en una rama hasta que esté completo).

## 8. Créditos

Si quiere, su nombre o alias aparecerá en [`NOTICE.md`](../NOTICE.md) (sección «Traducciones»). **Es opcional.** Nunca hace falta dar su nombre real, su correo ni ningún dato personal: puede usar solo su usuario de GitHub o un alias, o no aparecer.
