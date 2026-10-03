<?php
/**
 * 0.5.0: avisos de PHP 8.4. Análisis con el tokenizador de PHP de TODO src (incluido vendor) en busca de
 * parámetros implícitamente nullable ("Tipo $x = null" sin "?"), que PHP 8.4 marca como obsoletos, y de
 * ReflectionProperty::setAccessible() (no-op desde PHP 8.1, obsoleto en PHP 8.5). No ejecuta PHP 8.4.
 */
require __DIR__ . '/aserciones.php';
$src  = dirname(__DIR__) . '/src';
$it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$hits = [];
$acc  = [];
$n    = 0;
foreach ($it as $f) {
	$path = $f->getPathname();
	if (substr($path, -4) !== '.php') { continue; }
	$n++;
	$code = file_get_contents($path);
	if (preg_match('/->setAccessible\(/', $code) && strpos($path, '/vendor/') === false) { $acc[] = $path; }
	$toks = token_get_all($code);
	$c    = count($toks);
	for ($i = 0; $i < $c; $i++) {
		if (!is_array($toks[$i]) || !in_array($toks[$i][0], [T_FUNCTION, T_FN], true)) { continue; }
		$j = $i + 1;
		while ($j < $c && $toks[$j] !== '(') { if ($toks[$j] === ';' || $toks[$j] === '{') { break; } $j++; }
		if ($j >= $c || $toks[$j] !== '(') { continue; }
		$depth = 0; $param = []; $params = [];
		for (; $j < $c; $j++) {
			$t = $toks[$j];
			if ($t === '(' || $t === '[') { $depth++; if ($depth === 1 && $t === '(') { continue; } }
			if ($t === ')' || $t === ']') { $depth--; if ($depth === 0) { $params[] = $param; break; } }
			if ($t === ',' && $depth === 1) { $params[] = $param; $param = []; continue; }
			$param[] = $t;
		}
		foreach ($params as $p) {
			$txt = '';
			foreach ($p as $t) {
				if (is_array($t)) { if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $txt .= ' '; continue; } $txt .= $t[1]; } else { $txt .= $t; }
			}
			$txt = trim(preg_replace('/\s+/', ' ', $txt));
			if (preg_match('/^(?:public |protected |private |readonly )*([\\\\\w|&?()]+) &?(?:\.\.\.)?\$\w+ = (null|NULL)$/', $txt, $m)) {
				$type = $m[1];
				if ($type[0] === '?' || stripos($type, 'null') !== false || strtolower($type) === 'mixed') { continue; }
				$hits[] = substr($path, strlen($src) + 1) . ": $txt";
			}
		}
	}
}
echo "  $n archivos PHP analizados\n";
t_ok($hits === [], 'ningún parámetro implícitamente nullable' . ($hits ? ': ' . implode('; ', $hits) : ''));
t_ok($acc === [], 'ningún setAccessible() en el código propio' . ($acc ? ': ' . implode('; ', $acc) : ''));

t_fin();
