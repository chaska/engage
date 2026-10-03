#!/usr/bin/env php
<?php
/**
 * Genera en dist/ los ZIP instalables de cada extensión y el paquete pkg_engage-<versión>.zip.
 *
 * Uso: php build/build.php
 *
 * Sin dependencias externas (solo ext-zip y ext-simplexml). La lista de extensiones, y los
 * nombres de los ZIP, se leen de <files> en src/package/pkg_engage.xml.
 */

if (PHP_SAPI !== 'cli') {
	exit("Solo CLI\n");
}

$root    = dirname(__DIR__);
$srcDir  = $root . '/src';
$distDir = $root . '/dist';
$pkgXml  = $srcDir . '/package/pkg_engage.xml';

function fallo(string $m): void
{
	fwrite(STDERR, "ERROR: $m\n");
	exit(1);
}

$xml = simplexml_load_file($pkgXml);
if ($xml === false) {
	fallo("No se puede leer $pkgXml");
}

$version = trim((string) $xml->version);
if ($version === '') {
	fallo('pkg_engage.xml no declara <version>');
}

// Fecha fija (creationDate del paquete) para que los ZIP sean reproducibles.
$mtime = strtotime((string) $xml->creationDate . ' 00:00:00 UTC') ?: 0;

/** Directorios de origen de cada extensión según su <file> en el manifiesto del paquete. */
function origen(string $srcDir, SimpleXMLElement $f): string
{
	$tipo = (string) $f['type'];
	$id   = (string) $f['id'];

	switch ($tipo) {
		case 'component':
			return "$srcDir/component";
		case 'module':
			return "$srcDir/modules/" . ((string) $f['client'] ?: 'site') . '/' . preg_replace('/^mod_/', '', $id);
		case 'plugin':
			return "$srcDir/plugins/" . (string) $f['group'] . '/' . $id;
	}

	fallo("Tipo de extensión no soportado: $tipo");
}

/** Lista de archivos (rutas relativas con "/"), ordenada de forma determinista. */
function listar(string $dir): array
{
	$res = [];
	$it  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ($it as $fi) {
		if (!$fi->isFile()) {
			continue;
		}
		$rel = substr($fi->getPathname(), strlen($dir) + 1);
		if (in_array(basename($rel), ['.DS_Store', 'Thumbs.db', 'desktop.ini'], true)) {
			continue;
		}
		$res[] = $rel;
	}

	sort($res, SORT_STRING);

	return $res;
}

/** @param array<string,string> $archivos ruta en el ZIP => ruta en disco */
function crearZip(string $destino, array $archivos, int $mtime): void
{
	global $conMtime;

	if (is_file($destino)) {
		unlink($destino);
	}

	$zip = new ZipArchive();
	if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		fallo("No se puede crear $destino");
	}

	ksort($archivos, SORT_STRING);

	foreach ($archivos as $dentro => $disco) {
		$zip->addFile($disco, $dentro);
		$zip->setCompressionName($dentro, ZipArchive::CM_DEFLATE);
		if (method_exists($zip, 'setMtimeName')) {
			$zip->setMtimeName($dentro, $mtime);
		}
	}

	if (!$zip->close()) {
		fallo("No se puede cerrar $destino");
	}
}

if (!is_dir($distDir) && !mkdir($distDir, 0777, true)) {
	fallo("No se puede crear $distDir");
}
foreach (glob($distDir . '/*.zip') ?: [] as $viejo) {
	unlink($viejo);
}

$paquete = [];

foreach ($xml->files->file as $f) {
	$zipName = trim((string) $f);
	$dir     = origen($srcDir, $f);

	if (!is_dir($dir)) {
		fallo("No existe el directorio de origen $dir para $zipName");
	}

	$archivos = [];
	foreach (listar($dir) as $rel) {
		$archivos[$rel] = "$dir/$rel";
	}

	crearZip("$distDir/$zipName", $archivos, $mtime);
	$paquete[$zipName] = "$distDir/$zipName";
	printf("  %-36s %5d archivos\n", $zipName, count($archivos));
}

// Paquete: manifiesto, script, idiomas y los ZIP de las extensiones.
$archivos = [];
foreach (listar("$srcDir/package") as $rel) {
	$archivos[$rel] = "$srcDir/package/$rel";
}
foreach ($paquete as $nombre => $ruta) {
	$archivos[$nombre] = $ruta;
}

$final = "$distDir/pkg_engage-$version.zip";
crearZip($final, $archivos, $mtime);

printf("\nPaquete: %s (%d entradas, %d bytes)\n", $final, count($archivos), filesize($final));
