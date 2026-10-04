<?php
/**
 * 0.6.20: paridad de los archivos de idioma en-GB / es-ES de TODAS las extensiones de src/ (sin vendor).
 * Para cada par: mismo conjunto y MISMO ORDEN de claves, mismos marcadores (%s %d %1$s {PLACEHOLDER}),
 * mismas etiquetas HTML y mismas URLs, sintaxis válida (parse_ini_file en modo RAW, como el analizador de Joomla),
 * sin cadenas vacías, sin dobles espacios nuevos, sin palabras inglesas sueltas, y ninguna cadena es-ES idéntica
 * a la inglesa salvo la lista blanca explícita de nombres propios y palabras comunes a ambos idiomas.
 * Además, todo en-GB con claves debe tener su es-ES.
 */
require __DIR__ . '/aserciones.php';
$src = dirname(__DIR__) . '/src';

// Valores (en minúsculas) que pueden ser idénticos al inglés: nombres propios, ejemplos y palabras iguales en español.
$listaBlanca = [
	'akeeba engage', 'html purifier', 'composer', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'spam',
	'john doe', 'john.doe@example.com',
];
// Palabras inglesas que no deben aparecer sueltas en una cadena es-ES (tras quitar HTML, código, URLs y marcadores).
$inglesas = ['the', 'you', 'your', 'and', 'with', 'will', 'please', 'are', 'this', 'that', 'from', 'was', 'were', 'not', 'hello', 'comment', 'comments', 'email', 'emails'];
// "email/emails" se admite en español de este proyecto (terminología ya usada: "Dirección de email"); se quitan de la lista:
$inglesas = array_values(array_diff($inglesas, ['email', 'emails']));

function leerIni(string $f): array
{
	$claves = [];
	$valores = [];
	$lineas = preg_split('/\n/', (string) file_get_contents($f));
	foreach ($lineas as $l) {
		if ($l === '' || $l[0] === ';' || $l[0] === '[') { continue; }
		if (!preg_match('/^([A-Z][A-Z0-9_]*)="(.*)"\s*$/', $l, $m)) { return ['error' => 'línea no válida: ' . substr($l, 0, 60)]; }
		$claves[]  = $m[1];
		$valores[] = $m[2];
	}
	return ['claves' => $claves, 'valores' => $valores];
}
function multiconj(string $re, string $s): array
{
	preg_match_all($re, $s, $m);
	$o = $m[0];
	sort($o);
	return $o;
}
function soloTexto(string $v): string
{
	$v = preg_replace('#<style.*?</style>#s', ' ', $v);
	$v = preg_replace('#<code>.*?</code>#s', ' ', $v);
	$v = preg_replace('#<[^>]+>#', ' ', $v);
	$v = preg_replace('#https?://\S+|\S+\.(css|php|js)\b#', ' ', $v);
	$v = preg_replace('#\{[A-Za-z_]+\}|%(\d+\$)?[sdu]|%[a-z.]+%|\\\\n#', ' ', $v);
	$v = preg_replace('#[A-Z][A-Z0-9_]{5,}#', ' ', $v);
	return $v;
}

$ficheros = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$p = $f->getPathname();
	if (str_contains($p, '/vendor/')) { continue; }
	if (preg_match('#/language/en-GB/[^/]+\.ini$#', $p)) { $ficheros[] = $p; }
}
sort($ficheros);
t_ok(count($ficheros) >= 20, 'archivos en-GB encontrados: ' . count($ficheros));

$totalClaves = 0;
$pares = 0;
foreach ($ficheros as $en) {
	$es  = str_replace('/language/en-GB/', '/language/es-ES/', $en);
	$n   = substr($en, strlen($src) + 1);
	$e   = leerIni($en);
	t_ok(!isset($e['error']), "$n: en-GB con sintaxis de claves válida" . ($e['error'] ?? ''));
	if (isset($e['error'])) { continue; }
	if ($e['claves'] === []) {
		// sin cadenas que traducir: no se exige es-ES
		t_ok(true, "$n: sin claves (nada que traducir)");
		continue;
	}
	if (!is_file($es)) { t_ok(false, "$n: falta el es-ES"); continue; }
	$pares++;
	$n2 = substr($es, strlen($src) + 1);
	$s  = leerIni($es);
	t_ok(!isset($s['error']), "$n2: es-ES con sintaxis de claves válida" . ($s['error'] ?? ''));
	if (isset($s['error'])) { continue; }

	// Sintaxis como Joomla (RAW) sin avisos
	foreach ([$en, $es] as $f) {
		set_error_handler(function ($no, $msg) { throw new ErrorException($msg); });
		try { $ok = is_array(parse_ini_file($f, false, INI_SCANNER_RAW)); } catch (Throwable $x) { $ok = false; }
		restore_error_handler();
		t_ok($ok, substr($f, strlen($src) + 1) . ': parse_ini_file (RAW) sin errores');
	}
	$raw = (string) file_get_contents($es);
	t_ok(mb_check_encoding($raw, 'UTF-8') && !str_starts_with($raw, "\xEF\xBB\xBF") && !str_contains($raw, "\r"), "$n2: UTF-8 sin BOM y finales de línea LF");

	// Claves: mismo conjunto, mismo orden
	$sobran = array_values(array_unique(array_diff($s['claves'], $e['claves'])));
	$faltan = array_values(array_unique(array_diff($e['claves'], $s['claves'])));
	t_ok($sobran === [], "$n2: ninguna clave huérfana" . ($sobran ? ' (' . implode(', ', $sobran) . ')' : ''));
	t_ok($faltan === [], "$n2: ninguna clave ausente" . ($faltan ? ' (' . implode(', ', array_slice($faltan, 0, 5)) . (count($faltan) > 5 ? '…' : '') . ')' : ''));
	t_ok($s['claves'] === $e['claves'], "$n2: mismas claves en el mismo orden que en-GB");

	$mal = ['marc' => [], 'tags' => [], 'urls' => [], 'vacio' => [], 'ident' => [], 'ingl' => [], 'dobles' => [], 'esc' => []];
	foreach ($e['claves'] as $i => $k) {
		$ve = $e['valores'][$i];
		$vs = $s['valores'][$i] ?? null;
		if ($vs === null) { continue; }
		$totalClaves++;
		if (multiconj('/%(?:\d+\$)?[sdu]|%[a-z.]+%|\{[A-Za-z_]+\}/', $ve) !== multiconj('/%(?:\d+\$)?[sdu]|%[a-z.]+%|\{[A-Za-z_]+\}/', $vs)) { $mal['marc'][] = $k; }
		if (multiconj('/<\/?[a-zA-Z][^>]*>/', $ve) !== multiconj('/<\/?[a-zA-Z][^>]*>/', $vs)) {
			// permitido: la etiqueta cambia solo en atributos de texto visible (title="...") -> se compara por nombre de etiqueta
			$nom = function (string $x): array {
				preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9]*)/', $x, $m);
				$o = [];
				foreach ($m[2] as $i => $t) { $o[] = $m[1][$i] . strtolower($t); }
				sort($o);
				return $o;
			};
			$a = $nom($ve); $b = $nom($vs);
			if ($a !== $b) { $mal['tags'][] = $k; }
			// href y src deben ser idénticos
			if (multiconj('/(?:href|src)=\\\\?["\'][^"\'\\\\]*/', $ve) !== multiconj('/(?:href|src)=\\\\?["\'][^"\'\\\\]*/', $vs)) { $mal['tags'][] = $k . ' (href/src)'; }
		}
		if (multiconj('#https?://[^\s"\'<>\\\\]+#', $ve) !== multiconj('#https?://[^\s"\'<>\\\\]+#', $vs)) { $mal['urls'][] = $k; }
		if (substr_count($ve, '\\n') !== substr_count($vs, '\\n') || substr_count($ve, '"') !== substr_count($vs, '"')) { $mal['esc'][] = $k; }
		if (($ve === '') !== ($vs === '') || ($ve !== '' && trim($vs) === '')) { $mal['vacio'][] = $k; }
		if ($vs !== '' && strtolower(trim($vs)) === strtolower(trim($ve)) && !in_array(strtolower(trim($vs)), $listaBlanca, true)) { $mal['ident'][] = $k; }
		if (preg_match('/  /', $vs) && !preg_match('/  /', $ve)) { $mal['dobles'][] = $k; }
		if (preg_match_all('/\b(' . implode('|', $inglesas) . ')\b/i', soloTexto($vs), $mm)) { $mal['ingl'][] = $k . ' [' . implode(',', array_unique($mm[1])) . ']'; }
	}
	$etq = [
		'marc' => 'mismos marcadores (%s %d %1$s {X})', 'tags' => 'mismas etiquetas HTML y href/src', 'urls' => 'mismas URLs',
		'esc' => 'mismos saltos \\n y comillas', 'vacio' => 'sin cadenas vacías (salvo las vacías en inglés)',
		'ident' => 'ninguna cadena idéntica al inglés (fuera de la lista blanca)', 'dobles' => 'sin dobles espacios',
		'ingl' => 'sin palabras inglesas sueltas',
	];
	foreach ($etq as $c => $txt) {
		t_ok($mal[$c] === [], "$n2: $txt" . ($mal[$c] ? ' -> ' . implode('; ', array_slice($mal[$c], 0, 5)) : ''));
	}
}
t_ok($pares >= 15, "pares en-GB/es-ES comprobados: $pares, claves comparadas: $totalClaves");

// Erratas conocidas que no deben volver
$todo = '';
foreach (glob($src . '/{component/backend,component/frontend,modules/site/*,plugins/*/*,package}/language/es-ES/*.ini', GLOB_BRACE) as $f) { $todo .= file_get_contents($f); }
foreach (['Advanzado', 'usarios', 'Plugind', 'Comentarioss', 'eligje', 'qeu ', 'Descendiente', 'No hay ideas', 'Una idea'] as $err) {
	t_ok(!str_contains($todo, $err), "errata «{$err}» ausente de los es-ES");
}
t_fin();
