# Respuestas: posición, sangría y cita (0.6.21)

## Cómo se muestran

Una respuesta se muestra siempre **anidada bajo el comentario al que contesta** (el orden «Orden de los comentarios» ascendente/descendente solo se aplica entre comentarios hermanos, es decir, del mismo nivel y con el mismo padre). El nivel máximo de anidación (`max_level`, 1 a 6, por defecto 3) limita a quién se puede contestar: responder a un comentario del último nivel cuelga la respuesta de su padre. Con `max_level = 1` no hay anidación: el botón «Responder» crea un comentario de primer nivel (así está documentado en el propio ajuste).

Cada respuesta lleva una línea «En respuesta a <nombre>» enlazada al comentario padre (ancla `#akengage-comment-<id>`; si el padre está en otra página de la paginación se enlaza con `akengage_cid`, que ya salta a la página correcta). Si el padre no está disponible para el visitante (sin publicar, borrado) no se muestra su nombre: sale «En respuesta a otro comentario» sin enlace.

## Opciones (Componentes > Engage > Opciones > pestaña «Respuestas»)

| Opción | Valores | Por defecto |
|---|---|---|
| `reply_show_quote` Mostrar a quién contesta una respuesta | Sí / No | Sí |
| `reply_indent` Sangría de las respuestas | Ninguna / Pequeña (0,75 rem) / Media (1,25 rem) / Grande (2,25 rem) por nivel; en pantallas de menos de 576 px: 0,5 / 0,75 / 1,25 rem | Media |
| `reply_style` Marca visual de las respuestas | Línea lateral (aspecto original) / Fondo suave / Ninguna | Línea lateral |

Se aplican con **clases CSS** en las listas (`akengage-reply-indent--<valor>`, `akengage-reply-style--<valor>`), sin estilos en línea (compatible con CSP) y funcionan con la caché de página, porque son HTML estático. Las opciones se ocultan si `max_level = 1`. Los valores se comprueban contra una lista blanca antes de convertirse en clase.

La hoja `media/com_engage/css/replies.css` se carga siempre en las páginas con comentarios, también cuando está activado «Cargar CSS personalizado» (que carga `comments.css`; desde la 0.6.21 esa hoja ya no suma su propio margen para no duplicar la sangría).

## Personalizar desde su CSS

Archivo recomendado: el CSS personalizado de su plantilla (en Cassiopeia `media/templates/site/cassiopeia/css/user.css`; en Helix Ultimate, «Custom CSS» en el panel de la plantilla). La variable `--akengage-reply-indent` tiene prioridad sobre la opción:

```css
/* sangría exacta de 3 rem por nivel */
:root { --akengage-reply-indent: 3rem; }
/* color del fondo suave de las respuestas (reply_style = soft) */
:root { --akengage-reply-bg: rgba(64, 181, 184, .12); }
```

Si prefiere sustituir por completo una hoja de Engage, Joomla permite una sustitución (override) en `templates/<su plantilla>/css/com_engage/replies.css` (o `comments.css`), que se carga en lugar de la original.
