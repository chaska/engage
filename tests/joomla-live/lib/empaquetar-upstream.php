<?php
/**
 * Reconstruye un paquete instalable pkg_engage-3.4.2.zip a partir del arbol "instalado" de upstream/3.4.2-instalado
 * (que tiene otra disposicion: admin/, user/, idioma/...). No modifica upstream/.
 *
 * Uso: php empaquetar-upstream.php <upstream/3.4.2-instalado> <directorio_salida> <build/build.php del fork>
 * Resultado: <salida>/dist/pkg_engage-3.4.2.zip (se genera con el MISMO build.php del fork, asi la disposicion de los ZIP es la misma).
 *
 * Limitacion conocida: el arbol instalado solo contiene los idiomas en-GB y es-ES; los demas idiomas que citan los
 * manifiestos originales (de-DE, el-GR, fr-FR, nl-NL) no existen en disco y sus lineas <language> se quitan de los
 * manifiestos de la copia temporal para que Joomla no falle por ficheros inexistentes.
 */
[$_, $up, $out, $buildPhp] = $argv + [null, null, null, null];
if (!$up || !$out || !$buildPhp) { fwrite(STDERR, "Uso: php empaquetar-upstream.php <upstream> <salida> <build.php>\n"); exit(2); }
$up = rtrim(realpath($up), '/'); @mkdir($out, 0777, true); $out = rtrim(realpath($out), '/');

function rmrf(string $d): void { if (!is_dir($d)) { return; } foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir() ? rmdir($f) : unlink($f); } rmdir($d); }
function cp(string $from, string $to): void { @mkdir(dirname($to), 0777, true); if (!copy($from, $to)) { fwrite(STDERR, "No se puede copiar $from\n"); exit(1); } }
function cpdir(string $from, string $to, array $skip = []): void
{
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS)) as $f) {
		$rel = substr($f->getPathname(), strlen($from) + 1);
		if (in_array($rel, $skip, true)) { continue; }
		cp($f->getPathname(), "$to/$rel");
	}
}
/** Quita del manifiesto las lineas <language> cuyo fichero no existe bajo $base (carpeta indicada en <languages folder=...>). */
function podarIdiomas(string $xmlFile, string $base): void
{
	$x = file_get_contents($xmlFile);
	$x = preg_replace_callback('#<languages\s+folder="([^"]+)">(.*?)</languages>#s', function ($m) use ($base) {
		$lineas = preg_replace_callback('#^[ \t]*<language\s+tag="[^"]+">([^<]+)</language>[ \t]*\r?\n#m', fn($l) => is_file("$base/{$m[1]}/" . trim($l[1])) ? $l[0] : '', $m[2]);
		return preg_match('#<language\s#', $lineas) ? "<languages folder=\"{$m[1]}\">$lineas</languages>" : '';
	}, $x);
	file_put_contents($xmlFile, $x);
}

rmrf("$out/src"); rmrf("$out/dist"); rmrf("$out/build");
$src = "$out/src";

// --- Componente
$comp = "$src/component";
cp("$up/admin/com_engage/engage.xml", "$comp/engage.xml");
cpdir("$up/admin/com_engage", "$comp/backend", ['engage.xml']);
cpdir("$up/user/com_engage", "$comp/frontend");
cpdir("$up/user/media/com_engage", "$comp/media");
foreach (glob("$up/admin/idioma/*", GLOB_ONLYDIR) as $d) { foreach (glob("$d/com_engage*.ini") as $f) { cp($f, "$comp/backend/language/" . basename($d) . '/' . basename($f)); } }
foreach (glob("$up/user/idioma/*", GLOB_ONLYDIR) as $d) { foreach (glob("$d/com_engage.ini") as $f) { cp($f, "$comp/frontend/language/" . basename($d) . '/' . basename($f)); } }
// el manifiesto mezcla dos carpetas de idiomas (frontend/language y backend/language): se poda con la ruta de cada una
$x = file_get_contents("$comp/engage.xml");
$x = preg_replace_callback('#<languages\s+folder="([^"]+)">(.*?)</languages>#s', function ($m) use ($comp) {
	$l = preg_replace_callback('#^[ \t]*<language\s+tag="[^"]+">([^<]+)</language>[ \t]*\r?\n#m', fn($l) => is_file("$comp/{$m[1]}/" . trim($l[1])) ? $l[0] : '', $m[2]);
	return "<languages folder=\"{$m[1]}\">$l</languages>";
}, $x);
file_put_contents("$comp/engage.xml", $x);

// --- Modulo
$mod = "$src/modules/site/engage_latest";
cpdir("$up/user/mod_engage_latest", $mod);
foreach (glob("$up/user/idioma/*", GLOB_ONLYDIR) as $d) { foreach (glob("$d/mod_engage_latest*.ini") as $f) { cp($f, "$mod/language/" . basename($d) . '/' . basename($f)); } }
podarIdiomas("$mod/mod_engage_latest.xml", $mod);

// --- Plugins
foreach (glob("$up/user/plugin/*/*", GLOB_ONLYDIR) as $pd) {
	$name = basename($pd); $group = basename(dirname($pd));
	$dest = "$src/plugins/$group/$name";
	cpdir($pd, $dest);
	foreach (glob("$up/admin/idioma/*", GLOB_ONLYDIR) as $d) { foreach (glob("$d/plg_{$group}_{$name}*.ini") as $f) { cp($f, "$dest/language/" . basename($d) . '/' . basename($f)); } }
	foreach (glob("$dest/*.xml") as $mx) { podarIdiomas($mx, $dest); }
}

// --- Paquete
cp("$up/admin/manifests/pkg_engage.xml", "$src/package/pkg_engage.xml");
cp("$up/admin/manifests/script.engage.php", "$src/package/script.engage.php");
foreach (glob("$up/admin/idioma/*", GLOB_ONLYDIR) as $d) { foreach (glob("$d/pkg_engage*.ini") as $f) { cp($f, "$src/package/language/" . basename($d) . '/' . basename($f)); } }
podarIdiomas("$src/package/pkg_engage.xml", "$src/package");

// El build.php del fork, tal cual, sobre este arbol
@mkdir("$out/build"); cp($buildPhp, "$out/build/build.php");
passthru(PHP_BINARY . ' ' . escapeshellarg("$out/build/build.php"), $rc);
exit($rc);
