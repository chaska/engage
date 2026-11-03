#!/usr/bin/env php
<?php
/**
 * Idiomas de Engage (0.9.17): comprobar y crear traducciones.
 *
 *   php build/idiomas.php comprobar [xx-XX|todos]   compara cada traduccion con en-GB (la fuente de verdad); sale con 1 si algo falla
 *   php build/idiomas.php plantilla xx-XX           crea el idioma nuevo copiando en-GB en TODAS las extensiones y lo anade a los manifiestos
 *   php build/idiomas.php manifiestos [xx-XX]       anade a los manifiestos los archivos de idioma que existan en las carpetas y falten
 *
 * Lo comprueba tambien build/build.php antes de empaquetar (un idioma roto o malicioso no llega al ZIP). ENGAGE_BUILD_SRC cambia el arbol de fuentes (pruebas).
 * Reglas para las traducciones: mismas claves que en-GB, mismos marcadores (%s, %d, %1$s, {nombre}), sin etiquetas HTML ni atributos ni direcciones que
 * no esten en el texto original de esa clave, sin <script>, on*=, javascript:/data:, sin caracteres de control, .ini bien formado y sin textos vacios
 * donde el original tiene texto.
 */

/** Extensiones con carpetas language/: [raiz de la extension => [carpetas language]]. */
function idiomasExtensiones(string $src): array
{
	$out = [];
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$p = str_replace('\\', '/', $f->getPathname());
		if (!$f->isFile() || !preg_match('#^(.*/language)/en-GB/[^/]+\.ini$#', $p, $m) || str_contains($p, '/vendor/')) { continue; }
		$out[$m[1]] = true;
	}
	$dirs = array_keys($out);
	sort($dirs);
	return $dirs;
}

/** Etiquetas de idioma presentes (distintas de en-GB) en las carpetas language/. */
function idiomasEtiquetas(string $src): array
{
	$t = [];
	foreach (idiomasExtensiones($src) as $d) {
		foreach (glob("$d/*", GLOB_ONLYDIR) ?: [] as $x) { if (basename($x) !== 'en-GB') { $t[basename($x)] = true; } }
	}
	$t = array_keys($t);
	sort($t);
	return $t;
}

/** Lee un .ini de idioma. @return array{claves:array<string,string>,errores:string[]} */
function idiomasLeerIni(string $ruta): array
{
	$errores = [];
	$avisos = [];
	$claves = [];
	$b = (string) @file_get_contents($ruta);
	if (str_starts_with($b, "\xEF\xBB\xBF")) { $errores[] = 'empieza con BOM (guarde el archivo como UTF-8 sin BOM)'; $b = substr($b, 3); }
	if (!preg_match('//u', $b)) { return ['claves' => [], 'errores' => ['no es UTF-8 valido']]; }
	if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $b)) { $errores[] = 'lleva caracteres de control'; }
	if (preg_match('/\x{202A}-\x{202E}|[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{FEFF}]/u', $b)) { $errores[] = 'lleva caracteres invisibles de direccion o de ancho cero'; }
	foreach (preg_split('/\r\n|\n|\r/', $b) as $i => $l) {
		$n = $i + 1;
		if (trim($l) === '' || $l[0] === ';' || preg_match('/^\[[A-Za-z0-9_ -]+\]\s*$/', $l)) { continue; }
		if (!preg_match('/^([A-Za-z0-9_.-]+)="(.*)"\s*$/', $l, $m)) { $errores[] = "linea $n: formato invalido (use CLAVE=\"texto\" en una sola linea)"; continue; }
		if (preg_match('/\\\\$/', $m[2]) && !preg_match('/\\\\\\\\$/', $m[2])) { $errores[] = "linea $n: el texto termina en una barra invertida suelta"; }
		if (in_array(strtoupper($m[1]), ['YES', 'NO', 'NULL', 'FALSE', 'ON', 'OFF', 'NONE', 'TRUE'], true)) { $errores[] = "linea $n: la clave {$m[1]} es una palabra reservada del .ini"; }
		if (preg_match('/(?<!\\\\)"/', $m[2])) { $errores[] = "linea $n: comilla sin escapar dentro del texto (escribala como \\\" o use « » “ ”)"; }
		if (isset($claves[$m[1]]) && $claves[$m[1]] !== $m[2]) { $errores[] = "linea $n: clave repetida con otro texto: {$m[1]}"; }
		elseif (isset($claves[$m[1]])) { $avisos[] = "linea $n: clave repetida (mismo texto): {$m[1]}"; }
		$claves[$m[1]] = $m[2];
	}
	// Lo que Joomla lee DE VERDAD (LanguageHelper::parseIniFile: parse_ini_file en modo RAW y despues \" -> "): debe ser exactamente lo que se ha entendido aqui.
	set_error_handler(static function ($n, $m) { throw new Exception($m); }, E_WARNING | E_NOTICE | E_DEPRECATED);
	try { $j = parse_ini_string(preg_replace('/^\xEF\xBB\xBF/', '', $b), false, INI_SCANNER_RAW); }
	catch (Throwable $e) { $j = null; $errores[] = 'PHP no lo lee como .ini: ' . $e->getMessage(); }
	finally { restore_error_handler(); }
	if (is_array($j)) {
		$j = str_replace('\\"', '"', $j);
		foreach ($claves as $k => $v) { if (!array_key_exists($k, $j) || $j[$k] !== str_replace('\\"', '"', $v)) { $errores[] = "Joomla leeria distinto el texto de $k"; } }
		foreach (array_diff_key($j, $claves) as $k => $_) { $errores[] = "Joomla leeria una clave que aqui no se ve: $k"; }
	}
	return ['claves' => $claves, 'errores' => $errores, 'avisos' => $avisos];
}

function idiomasMarcadores(string $s): array
{
	preg_match_all('/%%|%(?:\d+\$)?[-+0]*\d*(?:\.\d+)?[sdufcx]|\{[A-Za-z_]+\}/', $s, $m);
	sort($m[0]);
	return $m[0];
}

/** Problemas de seguridad/forma de UN texto traducido respecto al original. @return string[] */
function idiomasTexto(string $orig, string $trad): array
{
	$p = [];
	if (trim($orig) !== '' && trim($trad) === '') { return ['texto vacio (el original tiene texto)']; }
	$q = static fn(string $x): int => preg_match_all('/(?<!\\\\)"/', $x);
	if ($q($trad) !== $q($orig)) { $p[] = 'comillas sin escapar dentro del texto (escribalas como \\" o use « » “ ”)'; }
	if (idiomasMarcadores($orig) !== idiomasMarcadores($trad)) {
		$p[] = 'marcadores distintos: original [' . implode(' ', idiomasMarcadores($orig)) . '], traduccion [' . implode(' ', idiomasMarcadores($trad)) . ']';
	}
	// Las etiquetas se miran en el texto TAL CUAL (&lt;nombre&gt; es texto, no una etiqueta); las direcciones y esquemas, ademas, con las entidades descodificadas (&#106;avascript:)
	$d = $trad;
	$do = $orig;
	$dd = html_entity_decode($trad, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$ddo = html_entity_decode($orig, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$tags = static function (string $s): array {
		$t = []; $a = [];
		if (preg_match_all('/<\s*\/?\s*([A-Za-z][A-Za-z0-9]*)([^>]*)>?/', $s, $m, PREG_SET_ORDER)) {
			foreach ($m as $x) {
				$t[] = strtolower($x[1]);
				if (preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=/', $x[2], $at)) { foreach ($at[1] as $n) { $a[] = strtolower($n); } }
			}
		}
		return [array_values(array_unique($t)), array_values(array_unique($a))];
	};
	[$to, $ao] = $tags($do); [$tt, $at] = $tags($d);
	foreach (array_diff($tt, $to) as $t) { $p[] = "etiqueta HTML que no esta en el original: <$t>"; }
	foreach (array_diff($at, $ao) as $a) { $p[] = "atributo HTML que no esta en el original: $a=" . (str_starts_with($a, 'on') ? ' (manejador de eventos)' : ''); }
	if (preg_match('/<\s*(script|iframe|object|embed|style|link|meta|base|form|svg|math)\b/i', $d) && !preg_match('/<\s*(script|iframe|object|embed|style|link|meta|base|form|svg|math)\b/i', $do)) { $p[] = 'etiqueta peligrosa (script, iframe, style...)'; }
	$limpio = preg_replace('/[\x00-\x20\x7F]+/', '', $dd);
	if (preg_match('/(javascript|vbscript|data)\s*:/i', $limpio) && !preg_match('/(javascript|vbscript|data)\s*:/i', preg_replace('/[\x00-\x20\x7F]+/', '', $ddo))) { $p[] = 'esquema javascript:, vbscript: o data:'; }
	$urls = static function (string $s): array {
		preg_match_all('#(?:[A-Za-z][A-Za-z0-9+.-]*:)?//[^\s"\'<>)]+|\bwww\.[^\s"\'<>)]+#i', $s, $m);
		return array_values(array_unique(array_map('strtolower', $m[0])));
	};
	foreach (array_diff(array_unique(array_merge($urls($d), $urls($dd))), array_unique(array_merge($urls($do), $urls($ddo)))) as $u) { $p[] = "direccion externa que no esta en el original: $u"; }
	if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $trad)) { $p[] = 'caracteres de control'; }
	return $p;
}

/** Archivos de idioma listados en los manifiestos: [carpeta language absoluta][etiqueta][nombre] = true. */
function idiomasManifiestos(string $src): array
{
	$res = []; $manif = [];
	foreach (idiomasExtensiones($src) as $dir) {
		$raiz = $dir;
		while (strlen($raiz) > strlen($src)) {
			$raiz = dirname($raiz);
			$xs = array_filter(glob("$raiz/*.xml") ?: [], fn($f) => preg_match('#<(extension|install)\b#', (string) file_get_contents($f)));
			if ($xs) { $manif[$dir] = reset($xs); break; }
		}
	}
	foreach (array_unique($manif) as $xmlf) {
		$x = @simplexml_load_file($xmlf);
		if (!$x) { continue; }
		foreach ($x->xpath('//languages') as $lg) {
			$carp = rtrim(dirname($xmlf) . '/' . trim((string) $lg['folder']), '/');
			foreach ($lg->language as $l) { $res[$carp][(string) $l['tag']][basename((string) $l)] = true; }
			$res[$carp] = $res[$carp] ?? [];
		}
	}
	return [$res, $manif];
}

/** @return array{errores:string[],avisos:string[],resumen:array<string,array>} */
function idiomasComprobar(string $src, string $que = 'todos'): array
{
	$errores = []; $avisos = []; $resumen = [];
	$tags = $que === 'todos' ? idiomasEtiquetas($src) : [$que];
	$tags = array_unique(array_merge(['en-GB'], $tags));
	[$listados] = idiomasManifiestos($src);
	foreach ($tags as $tag) {
		if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag)) { $errores[] = "$tag: etiqueta de idioma no valida (use por ejemplo fr-FR)"; continue; }
		$tot = 0; $igual = 0; $hay = false;
		foreach (idiomasExtensiones($src) as $dir) {
			$rel = ltrim(substr($dir, strlen($src)), '/');
			foreach (glob("$dir/en-GB/*.ini") as $fo) {
				$nombre = basename($fo);
				$o = idiomasLeerIni($fo);
				$ft = "$dir/$tag/$nombre";
				foreach ($tag === 'en-GB' ? $o['avisos'] : [] as $av) { $avisos[] = "en-GB $rel/$nombre: $av"; }
				if ($tag === 'en-GB') {
					foreach ($o['errores'] as $e) { $errores[] = "en-GB $rel/$nombre: $e"; }
					$ft = $fo; $t = $o;
				} elseif (!is_file($ft)) {
					if ($o['claves']) { $errores[] = "$tag: falta el archivo $rel/$tag/$nombre"; }
					continue;
				} else {
					$t = idiomasLeerIni($ft); $hay = true;
					foreach ($t['avisos'] as $av) { $avisos[] = "$tag $rel/$nombre: $av"; }
					foreach ($t['errores'] as $e) { $errores[] = "$tag $rel/$nombre: $e"; }
					foreach (array_diff_key($o['claves'], $t['claves']) as $k => $_) { $errores[] = "$tag $rel/$nombre: falta la clave $k"; }
					foreach (array_diff_key($t['claves'], $o['claves']) as $k => $_) { $errores[] = "$tag $rel/$nombre: sobra la clave $k (no existe en en-GB)"; }
					foreach ($t['claves'] as $k => $v) {
						if (!isset($o['claves'][$k])) { continue; }
						$tot++; if ($v === $o['claves'][$k] && preg_match('/[A-Za-z]{4,}/', $v)) { $igual++; }
						foreach (idiomasTexto($o['claves'][$k], $v) as $pr) { $errores[] = "$tag $rel/$nombre: $k: $pr"; }
					}
				}
			}
			if ($tag !== 'en-GB') {
				foreach (glob("$dir/$tag/*.ini") ?: [] as $fx) { if (!is_file("$dir/en-GB/" . basename($fx))) { $errores[] = "$tag $rel/$tag/" . basename($fx) . ': no existe en en-GB (sobra)'; } }
			}
			// manifiestos: cada archivo presente debe estar listado, y cada listado debe existir
			foreach (glob("$dir/$tag/*.ini") ?: [] as $fx) {
				if (!isset($listados[$dir][$tag][basename($fx)])) { $errores[] = "$tag: $rel/$tag/" . basename($fx) . ' no esta en el manifiesto de la extension (ejecute: php build/idiomas.php manifiestos ' . $tag . ')'; }
			}
			foreach ($listados[$dir][$tag] ?? [] as $n => $_) { if (!is_file("$dir/$tag/$n")) { $errores[] = "$tag: el manifiesto lista $rel/$tag/$n pero el archivo no existe"; } }
		}
		if ($tag !== 'en-GB') {
			if (!$hay) { $errores[] = "$tag: no hay ningun archivo de ese idioma"; }
			$resumen[$tag] = ['textos' => $tot, 'iguales_al_ingles' => $igual];
			if ($tot && $igual / $tot > 0.5) { $avisos[] = "$tag: mas de la mitad de los textos ($igual de $tot) siguen iguales al ingles"; }
		}
	}
	return ['errores' => array_values(array_unique($errores)), 'avisos' => array_values(array_unique($avisos)), 'resumen' => $resumen];
}

/** Anade a cada manifiesto las lineas <language> que falten para $tag. @return string[] cambios */
function idiomasManifiestosAplicar(string $src, string $tag): array
{
	$cambios = [];
	[$listados, $manif] = idiomasManifiestos($src);
	$porXml = [];
	foreach ($manif as $dir => $xmlf) { $porXml[$xmlf][] = $dir; }
	foreach ($porXml as $xmlf => $dirs) {
		$b = (string) file_get_contents($xmlf);
		$nl = str_contains($b, "\r\n") ? "\r\n" : "\n";
		foreach ($dirs as $dir) {
			$rel = trim(substr($dir, strlen(dirname($xmlf))), '/');
			foreach (glob("$dir/$tag/*.ini") ?: [] as $fx) {
				$n = basename($fx);
				if (isset($listados[$dir][$tag][$n])) { continue; }
				$pat = '#(<languages\s+folder="' . preg_quote($rel, '#') . '"\s*>)(.*?)([ \t]*</languages>)#s';
				if (!preg_match($pat, $b, $m, PREG_OFFSET_CAPTURE)) { continue; }
				preg_match('/^([ \t]*)<language /m', $m[2][0], $ind);
				$sangria = $ind[1] ?? "\t\t";
				$linea = $sangria . '<language tag="' . $tag . '">' . "$tag/$n" . '</language>';
				$cuerpo = rtrim($m[2][0], " \t\r\n");
				$b = substr($b, 0, $m[2][1]) . $cuerpo . $nl . $linea . $nl . $m[3][0] . substr($b, $m[3][1] + strlen($m[3][0]));
				$cambios[] = basename($xmlf) . ": $tag/$n";
				$listados[$dir][$tag][$n] = true;
			}
		}
		file_put_contents($xmlf, $b);
	}
	return $cambios;
}

function idiomasPlantilla(string $src, string $tag): array
{
	if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag) || $tag === 'en-GB') { return ['error' => 'Use una etiqueta como fr-FR o pt-BR (dos letras en minuscula, guion, dos en mayuscula).']; }
	$n = 0;
	foreach (idiomasExtensiones($src) as $dir) { if (is_dir("$dir/$tag")) { return ['error' => "El idioma $tag ya existe (" . ltrim(substr($dir, strlen($src)), '/') . "/$tag)."]; } }
	foreach (idiomasExtensiones($src) as $dir) {
		mkdir("$dir/$tag", 0755, true);
		foreach (glob("$dir/en-GB/*.ini") as $f) {
			copy($f, "$dir/$tag/" . basename($f)); chmod("$dir/$tag/" . basename($f), 0644);
			$n++;
		}
	}
	$m = idiomasManifiestosAplicar($src, $tag);
	return ['archivos' => $n, 'manifiestos' => $m];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
	$src = getenv('ENGAGE_BUILD_SRC') ?: dirname(__DIR__) . '/src';
	$cmd = $argv[1] ?? '';
	if ($cmd === 'comprobar') {
		$que = $argv[2] ?? 'todos';
		$r = idiomasComprobar($src, $que);
		$tags = $que === 'todos' ? array_merge(['en-GB'], idiomasEtiquetas($src)) : ['en-GB', $que];
		echo 'Idiomas comprobados: ' . implode(', ', array_unique($tags)) . "\n";
		foreach ($r['resumen'] as $t => $s) { echo "  $t: {$s['textos']} textos; {$s['iguales_al_ingles']} iguales al ingles\n"; }
		foreach ($r['avisos'] as $a) { echo "AVISO  $a\n"; }
		foreach ($r['errores'] as $e) { echo "ERROR  $e\n"; }
		if ($r['errores']) { printf("\nFALLA: %d problema(s). Corrijalos y vuelva a ejecutar.\n", count($r['errores'])); exit(1); }
		echo "\nTodo correcto.\n";
		exit(0);
	}
	if ($cmd === 'plantilla' && isset($argv[2])) {
		$r = idiomasPlantilla($src, $argv[2]);
		if (isset($r['error'])) { fwrite(STDERR, 'ERROR: ' . $r['error'] . "\n"); exit(1); }
		echo "Creado {$argv[2]}: {$r['archivos']} archivos copiados de en-GB y " . count($r['manifiestos']) . " lineas anadidas a los manifiestos.\n";
		echo "Ahora traduzca el texto entre comillas de cada archivo {$argv[2]}/*.ini (no las claves ni los marcadores) y ejecute: php build/idiomas.php comprobar {$argv[2]}\n";
		exit(0);
	}
	if ($cmd === 'manifiestos') {
		$tags = isset($argv[2]) ? [$argv[2]] : idiomasEtiquetas($src);
		$n = 0; foreach ($tags as $t) { $c = idiomasManifiestosAplicar($src, $t); $n += count($c); foreach ($c as $x) { echo "anadido $x\n"; } }
		echo "$n lineas anadidas.\n";
		exit(0);
	}
	fwrite(STDERR, "Uso:\n  php build/idiomas.php comprobar [xx-XX|todos]\n  php build/idiomas.php plantilla xx-XX\n  php build/idiomas.php manifiestos [xx-XX]\n");
	exit(2);
}
