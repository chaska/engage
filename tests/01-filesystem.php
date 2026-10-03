<?php
/**
 * 0.3.0: el código ya no usa Joomla\CMS\Filesystem\* (eliminado en Joomla 6) y las llamadas que hace
 * funcionan con la librería real joomla/filesystem 4.x.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__);

// 1) Ninguna referencia a la clase eliminada en src (sin vendor).
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src", FilesystemIterator::SKIP_DOTS));
$malos = [];
$usan  = 0;
foreach ($it as $f) {
	if (!str_ends_with($f->getPathname(), '.php') || str_contains($f->getPathname(), '/vendor/')) { continue; }
	$c = file_get_contents($f->getPathname());
	if (preg_match('/Joomla\\\\CMS\\\\Filesystem|\bJFile\b|\bJFolder\b|\bJPath\b/', $c)) { $malos[] = $f->getPathname(); }
	if (str_contains($c, 'use Joomla\\Filesystem\\')) { $usan++; }
}
t_ok($malos === [], 'no queda ninguna referencia a Joomla\\CMS\\Filesystem / JFile / JFolder / JPath en src');
t_ok($usan === 3, "3 archivos importan Joomla\\Filesystem\\* (encontrados: $usan)");

// 2) Las firmas que usa el código existen y funcionan en la librería real.
$auto = __DIR__ . '/vendor/autoload.php';
if (!is_file($auto)) { echo "  AVISO sin tests/vendor: ejecuta composer install --working-dir=tests; se omite la parte 2\n"; t_fin(); }
require $auto;
use Joomla\Filesystem\{File, Folder, Path};
$tmp = sys_get_temp_dir() . '/engage_t_' . bin2hex(random_bytes(4));
mkdir($tmp);
t_ok(File::write("$tmp/a.dat", 'hola'), 'File::write');
t_ok(file_get_contents("$tmp/a.dat") === 'hola', 'contenido escrito');
mkdir("$tmp/Carpeta"); File::write("$tmp/Carpeta/x.dat", 'x');
t_ok(Folder::move("$tmp/Carpeta", "$tmp/inter"), 'Folder::move (paso 1)');
t_ok(Folder::move("$tmp/inter", "$tmp/carpeta"), 'Folder::move (paso 2)');
t_ok(is_file("$tmp/carpeta/x.dat"), 'carpeta movida con su contenido');
t_ok(Path::find([$tmp, "$tmp/carpeta"], 'x.dat') === false || is_string(Path::find([$tmp, "$tmp/carpeta"], 'x.dat')), 'Path::find existe y devuelve ruta o false');
t_ok(Path::find([$tmp . '/carpeta'], 'x.dat') !== false, 'Path::find localiza x.dat');
t_ok(File::delete("$tmp/a.dat") && !is_file("$tmp/a.dat"), 'File::delete');
t_ok(Folder::delete("$tmp/carpeta") && !is_dir("$tmp/carpeta"), 'Folder::delete');
Folder::delete($tmp);
t_fin();
