<?php
/**
 * 0.9.5: microdatos de la lista de comentarios (aviso de Search Console «El objeto Comment debe estar anidado en un objeto CreativeWork» y «Falta el campo url
 * (en author)»): el hilo es un CreativeWork https con name (escapado) y url (canonical limpia) cuando se conocen; cada Comment es su propiedad `comment`;
 * todos los tipos en https; el autor es una Person con name y SOLO lleva url si hay un perfil real (nofollow ugc): a un invitado no se le inventa una URL.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src/component/frontend';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
$d  = $lee("$root/tmpl/comments/default.php");
$dl = $lee("$root/tmpl/comments/default_list.php");
$v  = $lee("$root/src/View/Comments/HtmlView.php");

echo "A) Estructura\n";
t_ok(preg_match('~<section id="akengage-comments-section".*?\bitemscope itemtype="https://schema.org/CreativeWork">~s', $d) === 1, 'la seccion de comentarios es un CreativeWork (https)');
t_ok(preg_match('~itemprop="comment" itemscope itemtype="https://schema.org/Comment">~', $dl) === 1, 'cada comentario es un Comment (https) con itemprop="comment": propiedad del CreativeWork');
t_ok(preg_match('~itemprop="author" itemscope itemtype="https://schema.org/Person"~', $dl) === 1, 'el autor es una Person (https)');
t_ok(!str_contains($d . $dl . $v, 'http://schema.org') && !str_contains($d . $dl, 'http://www.schema.org'), 'ningun tipo en http://');
t_ok(preg_match_all('~itemtype="([^"]+)"~', $d . $dl, $m) && !array_diff($m[1], ['https://schema.org/CreativeWork', 'https://schema.org/Comment', 'https://schema.org/Person', 'https://schema.org/SiteNavigationElement']), 'solo tipos conocidos de schema.org en https (' . implode(', ', array_unique($m[1])) . ')');
t_ok(strpos($d, 'itemtype="https://schema.org/CreativeWork"') < strpos($d, "loadTemplate('list')"), 'la lista (los Comment) va DENTRO del CreativeWork');
t_ok(substr_count($d, '</section>') === 1 && strpos($d, "loadTemplate('list')") < strrpos($d, '</section>'), 'el CreativeWork se cierra despues de la lista');

echo "B) name y url del CreativeWork\n";
t_ok(preg_match('~<meta itemprop="name" content="<\?= \$this->escape\(\(string\) \$this->title\) \?>">~', $d) === 1 && str_contains($d, "if ((string) \$this->title !== '')"), 'name = titulo del elemento, escapado, solo si se conoce');
t_ok(preg_match('~<link itemprop="url" href="<\?= \$this->escape\(\$schemaUrl\) \?>">~', $d) === 1 && str_contains($d, "(\$schemaUrl = \$this->schemaUrl()) !== ''"), 'url = canonical limpia (escapada), solo si es segura');
t_ok(str_contains($v, 'public function schemaUrl(): string') && str_contains($v, 'CommentTools::canonicalHref($this->requestUri(), (string) (TrustedBase::current()'), 'schemaUrl() usa la misma canonical (base fiable o ruta relativa), nunca el Host');

echo "C) Autor: sin url inventada\n";
preg_match('~<a href="<\?= htmlspecialchars\(\(string\) \$profile.*?itemprop="url" rel="[^"]*">~s', $dl, $pa);
t_ok($pa && str_contains($pa[0], 'rel="nofollow ugc noopener noreferrer"'), 'el unico itemprop="url" del autor es el enlace al perfil real, con nofollow ugc');
t_ok(substr_count($dl, 'itemprop="url"') === 1, 'no hay ningun otro itemprop="url" (a un invitado no se le inventa una direccion de persona)');
t_ok(str_contains($dl, "<?php if (empty(\$profile)): ?>") || str_contains($dl, 'empty($profile)'), 'sin perfil el autor solo lleva name (y la imagen)');
t_ok(substr_count($dl, 'itemprop="name"') >= 2 && !preg_match('/itemprop="email"|itemprop="identifier"/', $dl), 'el autor lleva name; ni correo ni identificador');

echo "D) Nada cambia para el aspecto\n";
t_ok(str_contains($dl, 'class="akengage-comment') && str_contains($dl, 'id="akengage-comment-<?= $comment->id ?>"'), 'las clases y el id de cada comentario siguen igual (los selectores de CSS y JS)');
t_ok(str_contains($d, 'class="akengage-outer-container') && str_contains($d, 'id="akengage-comments-section"') && str_contains($d, 'akengage-list-container'), 'el contenedor, el id de la seccion y la lista siguen igual');
t_fin();
