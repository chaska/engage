#!/usr/bin/env php
<?php
/**
 * Verificaciones de la Fase 1 (requiere haber ejecutado antes `php build/build.php`):
 *  (b) cada archivo declarado en cada manifiesto existe en su ZIP; el paquete contiene los ZIP declarados;
 *  (c) php -l sobre los PHP de src (sin vendor);
 *  (d) cada archivo de src tiene origen en upstream/3.4.2-instalado; los que difieren se listan como
 *      "modificados deliberadamente" (el fork los cambia a propósito; cada cambio consta en CHANGELOG.md);
 *  (e) todos los XML de src están bien formados;
 *  (f) el build es reproducible (dos ZIP del paquete con el mismo hash).
 *
 * Salida: código 0 si no hay fallos. Las referencias a idiomas que upstream no incluye
 * (de-DE, el-GR, fr-FR, nl-NL...) se informan aparte como "declarados pero ausentes en la fuente".
 */

$root = dirname(__DIR__);
$src  = "$root/src";
$up   = "$root/upstream/3.4.2-instalado";
$dist = "$root/dist";

$fallos = 0;
function ok(string $m): void { echo "  OK   $m\n"; }
function ko(string $m): void { global $fallos; $fallos++; echo "  FALLO $m\n"; }
function nota(string $m): void { echo "  AVISO $m\n"; }

function listar(string $dir): array
{
	$r  = [];
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
	foreach ($it as $f) {
		if ($f->isFile()) {
			$r[] = substr($f->getPathname(), strlen($dir) + 1);
		}
	}
	sort($r, SORT_STRING);
	return $r;
}

/** Ruta de upstream correspondiente a una ruta relativa de src (o null si no tiene). */
function origenUpstream(string $rel): ?string
{
	if (preg_match('#^package/language/#', $rel)) {
		return null; // nuevo en esta fase
	}
	if (preg_match('#^package/(.+)$#', $rel, $m)) {
		return "admin/manifests/{$m[1]}";
	}
	if ($rel === 'component/engage.xml') {
		return 'admin/com_engage/engage.xml';
	}
	if (preg_match('#^component/backend/language/([^/]+)/(.+)$#', $rel, $m)) {
		return "admin/idioma/{$m[1]}/{$m[2]}";
	}
	if (preg_match('#^component/frontend/language/([^/]+)/(.+)$#', $rel, $m)) {
		return "user/idioma/{$m[1]}/{$m[2]}";
	}
	if (preg_match('#^component/backend/(.+)$#', $rel, $m)) {
		return "admin/com_engage/{$m[1]}";
	}
	if (preg_match('#^component/frontend/(.+)$#', $rel, $m)) {
		return "user/com_engage/{$m[1]}";
	}
	if (preg_match('#^component/media/(.+)$#', $rel, $m)) {
		return "user/media/com_engage/{$m[1]}";
	}
	if (preg_match('#^modules/site/engage_latest/language/([^/]+)/(.+)$#', $rel, $m)) {
		return "user/idioma/{$m[1]}/{$m[2]}";
	}
	if (preg_match('#^modules/site/engage_latest/(.+)$#', $rel, $m)) {
		return "user/mod_engage_latest/{$m[1]}";
	}
	if (preg_match('#^plugins/([^/]+)/([^/]+)/language/([^/]+)/(.+)$#', $rel, $m)) {
		return "admin/idioma/{$m[3]}/{$m[4]}";
	}
	if (preg_match('#^plugins/([^/]+)/([^/]+)/(.+)$#', $rel, $m)) {
		return "user/plugin/{$m[1]}/{$m[2]}/{$m[3]}";
	}
	return null;
}

/** Entradas que un manifiesto declara: lista de [ruta en el ZIP, descripción]. */
function declarados(SimpleXMLElement $x, string $base = ''): array
{
	$res = [];
	$walk = function (SimpleXMLElement $n, string $pref) use (&$walk, &$res) {
		foreach ($n->children() as $c) {
			$nom = $c->getName();
			if (in_array($nom, ['files', 'languages', 'media'], true)) {
				$folder = (string) $c['folder'];
				$p      = $folder !== '' ? "$folder/" : '';
				foreach ($c->children() as $e) {
					$en = $e->getName();
					if (in_array($en, ['folder', 'filename', 'language', 'file'], true)) {
						$res[] = [$pref . $p . trim((string) $e), "<$nom> <$en>", $en === 'language' ? (string) $e['tag'] : ''];
					}
				}
			} elseif ($nom === 'administration') {
				$walk($c, 'backend-ctx:');
			} elseif ($nom === 'install' || $nom === 'uninstall') {
				foreach ($c->xpath('.//file') ?: [] as $f) {
					$res[] = ['backend/' . trim((string) $f), "<$nom> sql", ''];
				}
			} elseif ($nom === 'update') {
				foreach ($c->xpath('.//schemapath') ?: [] as $f) {
					$res[] = ['backend/' . trim((string) $f), '<schemapath>', ''];
				}
			}
		}
	};
	$walk($x, '');
	return $res;
}

echo "== (b) Manifiestos frente a los ZIP\n";
if (!is_file("$dist/pkg_engage-3.4.2.zip")) {
	exit("Falta dist/. Ejecuta antes php build/build.php\n");
}
$pkg = simplexml_load_file("$src/package/pkg_engage.xml");
$ver = (string) $pkg->version;
$pz  = new ZipArchive();
$pz->open("$dist/pkg_engage-$ver.zip");
$pnom = [];
for ($i = 0; $i < $pz->numFiles; $i++) {
	$pnom[] = $pz->getNameIndex($i);
}
$declZip = 0;
foreach ($pkg->files->file as $f) {
	$declZip++;
	$n = trim((string) $f);
	in_array($n, $pnom, true) ? null : ko("el paquete no contiene $n");
}
echo "  ZIP de extensiones declarados en pkg_engage.xml: $declZip; presentes en el paquete: " . count(array_filter($pnom, fn($n) => str_ends_with($n, '.zip'))) . "\n";
if ($fallos === 0) { ok('el paquete contiene todos los ZIP declarados'); }
foreach (['pkg_engage.xml', 'script.engage.php'] as $n) {
	in_array($n, $pnom, true) ? ok("paquete contiene $n") : ko("paquete sin $n");
}

// Manifiesto del paquete frente a archivos del paquete.
$ausentes = [];
$tot      = 0;
$ext = [];
foreach (declarados($pkg) as [$ruta, $d, $tag]) {
	if ($d === '<files> <file>') {
		continue;
	}
	$tot++;
	if (!in_array($ruta, $pnom, true)) {
		$ausentes[] = $ruta;
	}
}
echo "  Paquete: $tot rutas declaradas (scriptfile aparte), ausentes: " . count($ausentes) . "\n";
in_array((string) $pkg->scriptfile, $pnom, true) ? ok('scriptfile presente') : ko('scriptfile ausente');
foreach ($ausentes as $a) { nota("declarado y ausente en la fuente: pkg_engage.xml -> $a"); }

$totDecl = $totOk = 0;
$ausTodos = [];
foreach ($pkg->files->file as $f) {
	$zn = trim((string) $f);
	$z  = new ZipArchive();
	$z->open("$dist/$zn");
	$nom = [];
	for ($i = 0; $i < $z->numFiles; $i++) {
		$nom[] = $z->getNameIndex($i);
	}
	// Manifiesto raíz del ZIP
	$raiz = array_values(array_filter($nom, fn($n) => !str_contains($n, '/') && str_ends_with($n, '.xml')));
	if (count($raiz) !== 1) {
		ko("$zn: se esperaba 1 manifiesto en la raíz, hay " . count($raiz));
		continue;
	}
	$mx = simplexml_load_string($z->getFromName($raiz[0]));
	if ((string) $mx['type'] !== (string) $f['type']) {
		ko("$zn: type del manifiesto difiere del paquete");
	}
	$aus = 0;
	foreach (declarados($mx) as [$ruta, $d, $tag]) {
		$totDecl++;
		// Admin: la carpeta de <administration><files folder="backend"> ya lleva el prefijo
		$ruta = str_replace('backend-ctx:', '', $ruta);
		$esDir = str_contains($d, '<folder>') || $d === '<schemapath>';
		$existe = false;
		if ($esDir) {
			foreach ($nom as $n) {
				if (str_starts_with($n, $ruta . '/')) { $existe = true; break; }
			}
		} else {
			$existe = in_array($ruta, $nom, true);
		}
		if ($existe) {
			$totOk++;
		} else {
			$aus++;
			$ausTodos[$tag !== '' ? 'idioma' : 'otro'][] = "$zn -> $ruta";
		}
	}
}
echo "  Extensiones: $totDecl rutas declaradas, $totOk presentes, " . ($totDecl - $totOk) . " ausentes\n";
foreach ($ausTodos['otro'] ?? [] as $a) { ko("declarado y ausente (no idioma): $a"); }
$idiomas = $ausTodos['idioma'] ?? [];
$porTag = [];
foreach ($idiomas as $a) {
	preg_match('#language/([A-Za-z-]+)/#', $a, $m);
	$porTag[$m[1] ?? '?'] = ($porTag[$m[1] ?? '?'] ?? 0) + 1;
}
ksort($porTag);
if ($idiomas) {
	nota(count($idiomas) . ' archivos de idioma declarados en los manifiestos pero que upstream no incluye (por idioma: ' . json_encode($porTag) . '). Ver README.');
}

echo "== (c) php -l sobre src (sin vendor)\n";
$n = $mal = 0;
foreach (listar($src) as $rel) {
	if (!str_ends_with($rel, '.php') || str_contains($rel, '/vendor/')) { continue; }
	$n++;
	exec('php -l ' . escapeshellarg("$src/$rel") . ' 2>&1', $out, $rc);
	if ($rc !== 0) { $mal++; ko("php -l $rel: " . implode(' ', $out)); }
	$out = [];
}
echo "  $n PHP comprobados, $mal con errores\n";

echo "== (d) Contenido idéntico a upstream\n";
$iguales = $dif = $nuevos = 0;
$modificados = [];
$usados = [];
foreach (listar($src) as $rel) {
	$o = origenUpstream($rel);
	if ($o === null) { $nuevos++; echo "  nuevo (sin origen): $rel\n"; continue; }
	if (!is_file("$up/$o")) { $dif++; ko("sin origen en upstream: $rel (esperado $o)"); continue; }
	$usados[$o] = true;
	if (hash_file('sha256', "$src/$rel") === hash_file('sha256', "$up/$o")) { $iguales++; } else { $modificados[] = $rel; }
}
$ausUp = array_values(array_filter(listar($up), fn($r) => !isset($usados[$r])));
echo "  idénticos: $iguales; modificados deliberadamente: " . count($modificados) . "; sin origen: $dif; nuevos: $nuevos; archivos de upstream no copiados a src: " . count($ausUp) . "\n";
foreach ($ausUp as $r) { ko("upstream no copiado: $r"); }
foreach ($modificados as $r) { nota("modificado deliberadamente respecto a upstream (ver CHANGELOG.md): $r"); }

echo "== (e) XML bien formados\n";
$n = 0;
libxml_use_internal_errors(true);
foreach (listar($src) as $rel) {
	if (!preg_match('/\.xml$/', $rel) || str_contains($rel, '/vendor/')) { continue; }
	$n++;
	$d = new DOMDocument();
	if (!$d->load("$src/$rel")) { ko("XML mal formado: $rel"); }
	libxml_clear_errors();
}
echo "  $n XML comprobados\n";

echo "== (f) Reproducibilidad\n";
$h1 = hash_file('sha256', "$dist/pkg_engage-$ver.zip");
exec('php ' . escapeshellarg("$root/build/build.php") . ' > /dev/null 2>&1');
$h2 = hash_file('sha256', "$dist/pkg_engage-$ver.zip");
$h1 === $h2 ? ok("dos builds dan el mismo SHA-256 ($h1)") : ko('el build no es reproducible');

echo "== (g) Pruebas ejecutables (tests/run.php)\n";
if (is_file("$root/tests/run.php")) {
	exec('php ' . escapeshellarg("$root/tests/run.php") . ' 2>&1', $tout, $trc);
	echo '  ' . implode("\n  ", array_slice($tout, -3)) . "\n";
	$trc === 0 ? ok('pruebas ejecutables') : ko('pruebas ejecutables con fallos');
}

echo "\n" . ($fallos ? "$fallos FALLO(S)\n" : "Sin fallos.\n");
exit($fallos ? 1 : 0);
