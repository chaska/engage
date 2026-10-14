# Robots, rastreadores y contador de visitas (0.9.4)

## Qué pasa

Engage da a cada comentario, a cada página de la lista, a cada orden y al filtro de favoritos **su propia dirección**. Todas son la misma página del artículo con un parámetro más (`akengage_cid`, `akengage_limitstart`, `akengage_sort`, `akengage_fav`, `akengage_limit`). Un robot que las recorre carga el artículo entero cada vez, y Joomla **suma una visita al contador del artículo en cada carga**. Con un libro de visitas de cientos de comentarios, unos pocos robots pueden hacer subir el contador a decenas de miles de visitas.

## Qué direcciones rastreables emite Engage

| Dónde | Enlace | Cuántas |
|---|---|---|
| Fecha de cada comentario | `?akengage_cid=ID#akengage-comment-ID` | una por comentario |
| «En respuesta a…» y «Ver en el hilo» | la misma dirección del permalink | las mismas (sin duplicar) |
| Paginación de la lista | `?akengage_limitstart=N` | una por página y por orden |
| Selector de orden | `?akengage_sort=newest\|oldest\|top` | 3 |
| Solo mis favoritos | `?akengage_fav=1` (se pinta oculto; solo funciona con sesión) | 1 o 2 |
| Módulo «Últimos comentarios» | enlace con la fecha: `?akengage_cid=ID` | los últimos N, en todas las páginas del sitio |
| Copiar enlace, moderación, reacciones, responder | botones (no son enlaces) | 0 |

**Cuenta para un artículo con 300 comentarios y 20 por página:** 300 (permalinks) + 60 (15 páginas × 4 series: sin orden, newest, oldest y top) + 7 (barra de orden y favoritos) = **367 direcciones distintas**, cada una con el artículo completo. El panel de control calcula esta cifra con los datos reales (punto «Rastreo de robots»).

## Qué hace la opción «Reducir el rastreo de robots» (Opciones > Avanzado, activada por defecto)

- Todos los enlaces anteriores llevan `rel="nofollow"` (incluida la paginación de Joomla y la fecha del comentario); los de módulo, siempre.
- Toda petición con algún parámetro `akengage_*` recibe `<meta name="robots" content="noindex, follow">` y la cabecera `X-Robots-Tag: noindex, follow` (la lista «solo mis favoritos», que es personal, `noindex, nofollow`). Si el sitio ya puso `nofollow` en esa meta, se respeta.
- Esas mismas peticiones añaden `<link rel="canonical">` a la dirección base del artículo. **Solo si Joomla tiene `live_site`** en `configuration.php` (la cabecera `Host` de una petición no es de fiar y nunca se imprime). Si el sitio ya declara su propio canonical, no se añade otro.
- **La dirección base del artículo no cambia**: sin meta robots añadida, sin cabecera, sin canonical de Engage; sigue indexable.
- No se oculta nada a las personas ni se sirve un contenido distinto a los robots (no hay «cloaking»): la página es la misma para todos.

Esto **no baja el contador por sí solo**: un robot que obedece `nofollow` deja de descubrir las variantes, pero las que ya conoce las seguirá pidiendo un tiempo (y Joomla sigue sumando visita en cada una). Para cortarlo del todo, use `robots.txt`.

## robots.txt listo para pegar

Añada al `robots.txt` de la raíz del sitio (las reglas con `*` las entienden Google, Bing y los demás grandes buscadores):

```
User-agent: *
Disallow: /*akengage_cid=
Disallow: /*akengage_limitstart=
Disallow: /*akengage_limit=
Disallow: /*akengage_sort=
Disallow: /*akengage_fav=
Disallow: /*akengage_
```

La última línea cubre cualquier parámetro `akengage_` y hace innecesarias las anteriores; se dejan por claridad. **Atención:** si bloquea con `robots.txt`, el robot ya no ve el `noindex` ni el `canonical` de esas páginas. Para direcciones que ya están indexadas, déjelas rastreables unas semanas con la opción activada para que se desindexen y bloquee después.

Cómo comprobarlo:

1. **Google Search Console** > Configuración > robots.txt (o el informe «Inspección de URL» con una dirección `…?akengage_cid=123`): debe decir «bloqueada por robots.txt» o «excluida por la etiqueta noindex».
2. **Bing Webmaster Tools** > Herramientas > Probador de robots.txt.
3. En su navegador: `curl -I "https://su-sitio/su-articulo?akengage_cid=1"` debe mostrar `X-Robots-Tag: noindex, follow`; la misma dirección sin parámetros, no.

## Los robots maliciosos ignoran robots.txt

`robots.txt` es una petición de cortesía: los robots que se comportan mal (y los que se hacen pasar por Google) la ignoran. Si el contador sigue subiendo:

- Limite las peticiones por IP en el hosting o en un cortafuegos/CDN (Cloudflare, ModSecurity, `fail2ban`, limitación de peticiones del panel del hosting).
- Bloquee por cortafuegos las IP o los agentes de usuario que no sean de buscadores reales. Los buscadores reales se verifican por DNS inverso (Google, Bing).
- Active la caché de páginas de Joomla (con el plugin «Engage Cache» si usa la caché conservadora): cada variante se sirve entonces sin tocar la base de datos, aunque el contador siga sumando.

## Cómo ver en los registros de acceso quién genera las visitas

En el hosting suele estar en «Registros de acceso» o `access.log`. Cuente las peticiones con `akengage_` por agente de usuario (el último campo entre comillas) y por IP (el primer campo):

```
grep 'akengage_' access.log | awk -F'"' '{print $6}' | sort | uniq -c | sort -rn | head -20
grep 'akengage_' access.log | awk '{print $1}'      | sort | uniq -c | sort -rn | head -20
```

Mire también cuántas peticiones por hora llegan a la dirección del artículo sin parámetros frente a las que llevan `akengage_`: si casi todas llevan parámetros y vienen de pocas IP, es un robot recorriendo las variantes.

## Lo que no hace Engage

- No cambia el contador de visitas de Joomla (es del núcleo; Engage no lo toca).
- No bloquea ni limita robots: eso corresponde al servidor, al cortafuegos o al CDN.
