<?php
/**
 * 0.6.0 / 0.7.0 / 0.8.2: versión propia del paquete (3.4.2.1 y 3.4.3 ya publicadas; 3.4.4 a 3.4.13 saltadas; 3.4.14 publicada, nunca publicadas; 3.4.20 la vigente, sin publicar). Todos los manifiestos y joomla.asset.json declaran la misma versión,
 * mayor que 3.4.2 según version_compare (Joomla la trata como actualización en sitio) y distinta de la original.
 * Copyright del fork (c)2026 fork comunitario de Engage iniciado por ChasKa; GPL v3 o posterior.
 */
require __DIR__ . '/aserciones.php';
$raiz = dirname(__DIR__);
$pkg  = simplexml_load_file("$raiz/src/package/pkg_engage.xml");
$ver  = (string) $pkg->version;

t_ok($ver === '3.4.20', "pkg_engage.xml declara 3.4.20 (es '$ver')");
t_ok(version_compare($ver, '3.4.2', '>'), 'version_compare: mayor que 3.4.2');
t_ok(version_compare($ver, '3.4.2.1', '>'), 'version_compare: mayor que la 3.4.2.1 ya publicada (Joomla la ofrece como actualización)');
t_ok(version_compare($ver, '3.4.3', '>') && version_compare($ver, '3.4.4', '>'), 'version_compare: mayor que la 3.4.3 publicada y que la 3.4.4 saltada');
t_ok(version_compare($ver, '3.4.3', '>'), 'version_compare: mayor que la 3.4.3 ya publicada (inmutable)');
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

// Esquema: UN fichero de actualización nuevo (tabla de reacciones, 0.7.0), con nombre mayor que el último de la 3.4.2 y la 3.4.2.1
// (3.0.2-20220107) para que Joomla lo ejecute en ambas actualizaciones; idempotente y sin tocar la tabla de comentarios.
$sql = array_map('basename', glob("$raiz/src/component/backend/sql/updates/mysql/*.sql"));
sort($sql, SORT_NATURAL);
t_ok(in_array('3.4.3-20261007.sql', $sql, true), 'existe sql/updates/mysql/3.4.3-20261007.sql');
// 0.9.14 (paquete 3.4.20): UN fichero nuevo de esquema (tabla de denuncias), aditivo e idempotente; el anterior sigue siendo el de la 3.4.3.
t_ok(end($sql) === '3.4.18-20261027.sql' && in_array('3.4.3-20261007.sql', $sql, true), 'el ultimo fichero de esquema es 3.4.18-20261027.sql (tabla de denuncias) y el de la 3.4.3 sigue');
t_ok(version_compare('3.4.18-20261027', '3.4.3-20261007', '>'), 'su nombre es mayor que el anterior: Joomla lo ejecuta al actualizar desde la 3.4.17 y desde cualquier anterior');
$n = (string) file_get_contents("$raiz/src/component/backend/sql/updates/mysql/3.4.18-20261027.sql");
t_ok(str_contains($n, 'CREATE TABLE IF NOT EXISTS `#__engage_reports`') && !preg_match('/ALTER TABLE|DROP TABLE|`#__engage_comments`\s*\(/i', preg_replace('/^--.*$/m', '', $n)), 'solo CREATE TABLE IF NOT EXISTS de la tabla nueva (sin ALTER ni DROP ni tocar comentarios)');
t_ok(version_compare('3.4.3-20261007', '3.0.2-20220107', '>'), 'su nombre es mayor que el último esquema de la 3.4.2 / 3.4.2.1 (3.0.2-20220107)');
$s = (string) file_get_contents("$raiz/src/component/backend/sql/updates/mysql/3.4.3-20261007.sql");
t_ok(str_contains($s, 'CREATE TABLE IF NOT EXISTS `#__engage_reactions`') && !preg_match('/ALTER TABLE|DROP TABLE|`#__engage_comments`\s*\(/i', preg_replace('/^--.*$/m', '', $s)), 'solo CREATE TABLE IF NOT EXISTS de la tabla nueva (sin ALTER ni DROP ni tocar comentarios)');
t_fin();
