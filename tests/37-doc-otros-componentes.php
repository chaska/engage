<?php
/**
 * 0.8.2: la documentacion para terceros (docs/COMENTARIOS-EN-OTROS-COMPONENTES.md) es la que se prueba en el Joomla real:
 *  - el ejemplo de plugin de la documentacion es, byte a byte, el de tests/joomla-live/lib/tercero-prueba (lo que ejecuta 21-otros-componentes.sh);
 *  - la ficha del ejemplo trae TODAS las claves que documenta la tabla y la documentacion no promete ninguna que Meta no valide;
 *  - README, docs y LEEME enlazan el documento; el CHANGELOG anota la entrada; los plugins de prueba no estan en el paquete.
 */
require __DIR__ . '/aserciones.php';
$raiz = dirname(__DIR__);
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
$doc  = $lee("$raiz/docs/COMENTARIOS-EN-OTROS-COMPONENTES.md");
$base = "$raiz/tests/joomla-live/lib/tercero-prueba";

foreach (['terceroprueba.xml', 'services/provider.php', 'src/Extension/Terceroprueba.php'] as $f) {
	t_ok(str_contains($doc, rtrim($lee("$base/$f"))), "el ejemplo de la documentacion contiene, sin cambios, $f");
}
$cls = $lee("$base/src/Extension/Terceroprueba.php");
$claves = ['type', 'title', 'category', 'author_name', 'author_email', 'url', 'public_url', 'published', 'published_on', 'access', 'parent_access', 'category_published', 'parameters'];
foreach ($claves as $k) {
	t_ok(str_contains($cls, "'$k'") && preg_match('/^\| `' . $k . '` \|/m', $doc) === 1, "clave `$k`: la devuelve el ejemplo y la explica la tabla");
}
$meta = $lee("$raiz/src/component/frontend/src/Helper/Meta.php");
foreach (['comments_enabled', 'comments_close_after', 'default_publish', 'comments_show', 'comments_ordering', 'comments_notify_author', 'comments_notify_users'] as $p) {
	t_ok(str_contains($doc, "`$p`") && str_contains($meta, "'$p'"), "parametro `$p`: documentado y reconocido por Meta");
}
t_ok(str_contains($doc, 'Meta::deleteAssetData(') && str_contains($meta, 'public static function deleteAssetData('), 'el metodo de borrado documentado existe');
t_ok(str_contains($doc, 'onContentAfterDisplay') && str_contains($doc, 'onAkeebaEngageGetAssetMeta'), 'los dos eventos estan explicados');
foreach (['Contador', 'Borrado', 'moduleclass_sfx', 'CVE-2026-40383', '#__content', 'Lista de comprobaci'] as $n) { t_ok(str_contains($doc, $n), "la documentacion trata: $n"); }
t_ok(!str_contains($doc, "\r"), 'el documento va con LF');

foreach (['README.md' => 'docs/COMENTARIOS-EN-OTROS-COMPONENTES.md', 'docs/PANEL.md' => null] as $f => $enlace) { if ($enlace) { t_ok(str_contains($lee("$raiz/$f"), '(' . $enlace . ')') || str_contains($lee("$raiz/$f"), 'COMENTARIOS-EN-OTROS-COMPONENTES.md'), "$f enlaza el documento"); } }
t_ok(str_contains($lee("$raiz/docs/REACCIONES.md") . $lee("$raiz/docs/ORDEN-Y-FAVORITOS.md") . $lee("$raiz/docs/PUBLICAR-RELEASE.md") . $lee("$raiz/tests/joomla-live/LEEME.md"), 'COMENTARIOS-EN-OTROS-COMPONENTES.md'), 'enlazado tambien desde otros documentos de docs/ o tests/');
t_ok(str_contains($lee("$raiz/CHANGELOG.md"), '## 0.8.2'), 'el CHANGELOG tiene la entrada 0.8.2');
t_ok(str_contains($lee("$raiz/tests/joomla-live/LEEME.md"), '21-otros-componentes.sh'), 'LEEME de tests/joomla-live documenta el script 21');

// Los plugins de prueba no entran en el paquete
$zip = new ZipArchive();
$pkg = glob("$raiz/dist/pkg_engage-*.zip");
if ($pkg && $zip->open(end($pkg)) === true) {
	$mal = [];
	for ($i = 0; $i < $zip->numFiles; $i++) { if (preg_match('/tercero/i', $zip->getNameIndex($i))) { $mal[] = $zip->getNameIndex($i); } }
	t_ok($mal === [], 'el paquete no contiene ningun plugin de prueba de terceros');
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raiz/src", FilesystemIterator::SKIP_DOTS));
$en = [];
foreach ($it as $f) { if (preg_match('/tercero(prueba|hostil|vista)/i', (string) file_get_contents($f->getPathname()))) { $en[] = $f->getPathname(); } }
t_ok($en === [], 'src/ no nombra los plugins de prueba');
t_fin();
