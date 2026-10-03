<?php
/**
 * 0.5.2: los manifiestos solo declaran archivos de idioma que existen (si el sitio tiene instalado ese idioma,
 * Joomla falla al instalar la extensión por un archivo declarado y ausente) y todo archivo de idioma
 * de la fuente está declarado.
 */
require __DIR__ . '/aserciones.php';
$src = dirname(__DIR__) . '/src';
$manifiestos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$p = $f->getPathname();
	if (!str_ends_with($p, '.xml') || str_contains($p, '/vendor/') || str_contains($p, '/forms/')) { continue; }
	$x = @simplexml_load_file($p);
	if ($x && $x->getName() === 'extension') { $manifiestos[] = $p; }
}
t_ok(count($manifiestos) === 13, 'manifiestos de extensión encontrados: ' . count($manifiestos) . ' (paquete + componente + módulo + 10 plugins)');
$tagsUsados = [];
foreach ($manifiestos as $m) {
	$dir = dirname($m);
	$x   = simplexml_load_file($m);
	$decl = [];
	foreach ($x->xpath('//languages') as $l) {
		$folder = trim((string) $l['folder'], '/');
		foreach ($l->language as $e) {
			$decl[] = ($folder !== '' ? "$folder/" : '') . trim((string) $e);
			$tagsUsados[(string) $e['tag']] = true;
		}
	}
	$real = [];
	$itl = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($itl as $f) {
		$rel = substr($f->getPathname(), strlen($dir) + 1);
		if (preg_match('#(^|/)language/[a-z]{2,3}-[A-Z]{2}/[^/]+\.ini$#', $rel) && !str_contains($rel, 'vendor/')) { $real[] = $rel; }
	}
	sort($decl); sort($real);
	$ausentes = array_values(array_diff($decl, $real));
	$sinDecl  = array_values(array_diff($real, $decl));
	$n = substr($m, strlen($src) + 1);
	t_ok($ausentes === [], "$n: ningún idioma declarado y ausente" . ($ausentes ? ' (' . implode(', ', $ausentes) . ')' : ''));
	t_ok($sinDecl === [], "$n: ningún archivo de idioma sin declarar" . ($sinDecl ? ' (' . implode(', ', $sinDecl) . ')' : ''));
}
$tags = array_keys($tagsUsados); sort($tags);
t_ok($tags === ['en-GB', 'es-ES'], 'idiomas declarados en todo el proyecto: ' . implode(', ', $tags));
t_fin();
