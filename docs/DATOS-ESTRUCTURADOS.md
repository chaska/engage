# Datos estructurados (microdatos) de la lista de comentarios (0.9.5)

Engage marca los comentarios con microdatos de schema.org para que los buscadores los entiendan. Desde la 0.9.5 la estructura es esta (todo en `https://schema.org/`):

```
CreativeWork            la sección de comentarios del artículo (<section id="akengage-comments-section">)
  name                  título del elemento (escapado; solo si se conoce)
  url                   dirección limpia y canónica del artículo (base fiable de Engage, o ruta relativa)
  comment  -> Comment   uno por comentario (<article id="akengage-comment-ID" itemprop="comment">)
      dateCreated, datePublished, text
      author -> Person
          name          nombre mostrado
          url           SOLO si la persona tiene un perfil real (enlace nofollow ugc)
          image         avatar
```

## Qué avisos de Google arregla

- **«El objeto "Comment" debe estar anidado en un objeto "CreativeWork"»** (Search Console, «Foro de debate»): antes cada comentario era un `Comment` suelto, y además en `http://`. Ahora todos son la propiedad `comment` de un `CreativeWork` propio de Engage (no depende de la plantilla del artículo, que puede tener o no su propio `Article`).
- **«Falta el campo "url" (en "author")»**: **no se arregla a propósito**. Es una sugerencia no crítica. El `author` es una `Person` con `name`; solo lleva `url` cuando hay un perfil de verdad (el enlace al perfil lleva `rel="nofollow ugc noopener noreferrer"`). A un invitado **no se le inventa una dirección de persona** (ni se usa el ancla del comentario, que no identifica a nadie ni es una página de la persona) ni se publica correo, hash ni identificador: sería datos falsos y menos privacidad. Si Search Console sigue avisando de ello para los invitados, es el comportamiento esperado.

## Qué no cambia

Las clases CSS, los identificadores y el orden de las etiquetas de la plantilla (temas incluidos) son los mismos: solo se añaden atributos `itemprop`/`itemscope`, una etiqueta `meta` y un `link` dentro de la sección.

## Cómo comprobarlo

1. Herramienta de pruebas de Google «Prueba de resultados enriquecidos» o el «Validador de Schema Markup» con la dirección de un artículo con comentarios: debe verse un `CreativeWork` con los `Comment` dentro.
2. En el navegador (consola): `document.querySelectorAll('[itemtype$="/Comment"]').length` y `[...document.querySelectorAll('[itemtype$="/Comment"]')].every(e => e.closest('[itemtype="https://schema.org/CreativeWork"]'))` debe dar `true`.
