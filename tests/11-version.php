<?php
/**
 * 0.6.0: versión propia del paquete. Todos los manifiestos y joomla.asset.json declaran la misma versión,
 * mayor que 3.4.2 según version_compare (Joomla la trata como actualización en sitio) y distinta de la original.
 * Copyright del fork (c)2026 fork comunitario de Engage iniciado por ChasKa; GPL v3 o posterior.
 */
require __DIR__ . '/aserciones.php';
$raiz = dirname(__DIR__);
$pkg  = simplexml_load_file("$raiz/src/package/pkg_engage.xml");
$ver  = (string) $pkg->version;

t_ok($ver === '3.4.2.1', "pkg_engage.xml declara 3.4.2.1 (es '$ver')");
t_ok(version_compare($ver, '3.4.2', '>'), 'version_compare: mayor que 3.4.2');
t_ok($ver !== '3.4.2', 'distinta de la versión original');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raiz/src", FilesystemIterator::SKIP_DOTS));
$n  = 0;
foreach ($it as $f) {
	$p = $f->getPathname();
	if (!str_ends_with($p, '.xml') || str_contains($p, '/vendor/')) { continue; }
	$x = simplexml_load_file($p);
	if ($x === false || $x->getName() !== 'extension') { continue; }
	$n++;
	t_ok((string) $x->version === $ver, 'versión de ' . substr($p, strlen($raiz) + 1) . ' = ' . (string) $x->version);
}
t_ok($n === 13, "13 manifiestos de extensión (hay $n)");

$asset = json_decode(file_get_contents("$raiz/src/component/media/joomla.asset.json"), true);
t_ok(($asset['version'] ?? '') === $ver, 'joomla.asset.json: misma versión (cache-busting)');

// Sin ficheros SQL de actualización nuevos: el esquema no cambia.
$sql = glob("$raiz/src/component/backend/sql/updates/mysql/*.sql");
t_ok(!preg_grep('/3\.4\.2\.1/', $sql), 'sin SQL de actualización para 3.4.2.1 (esquema sin cambios)');
t_fin();
