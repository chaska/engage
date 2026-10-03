<?php
/**
 * 0.6.6: escapado de salida. Ningún Text::sprintf / Text::plural de src (sin vendor) recibe sin escapar un dato
 * que pueda venir de un usuario (nombre, email, título, autor) cuando el resultado se muestra como HTML.
 * Análisis estático de cada llamada (hasta el cierre del paréntesis) + ejecución de la expresión de escape.
 */
require __DIR__ . '/aserciones.php';
$src = dirname(__DIR__) . '/src';
$it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$riesgo = '/\$[A-Za-z_>\-]*(name|Name|email|Email|title|Author|author|modifiedBy)\b/';
$llamadas = 0; $malas = [];
foreach ($it as $f) {
	$p = $f->getPathname();
	if (!str_ends_with($p, '.php') || str_contains($p, '/vendor/')) { continue; }
	$code = file_get_contents($p);
	preg_match_all('/Text::(sprintf|plural)\(/', $code, $m, PREG_OFFSET_CAPTURE);
	foreach ($m[0] as [$txt, $off]) {
		$i = $off + strlen($txt); $d = 1; $n = strlen($code);
		for ($j = $i; $j < $n && $d > 0; $j++) { if ($code[$j] === '(') $d++; elseif ($code[$j] === ')') $d--; }
		$args = substr($code, $i, $j - $i - 1);
		$llamadas++;
		// Se quitan las partes ya escapadas y las claves de idioma (cadenas entre comillas) antes de buscar riesgo.
		$limpio = preg_replace('/htmlspecialchars\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', '', $args);
		$limpio = preg_replace('/\'[^\']*\'/', '', $limpio);
		if (preg_match($riesgo, $limpio, $mm)) { $malas[] = substr($p, strlen($src) + 1) . ': ' . trim(preg_replace('/\s+/', ' ', $mm[0])); }
	}
}
t_ok($llamadas > 10, "se analizaron $llamadas llamadas Text::sprintf/plural");
t_ok($malas === [], 'ninguna con dato de usuario sin escapar' . ($malas ? ': ' . implode(' | ', $malas) : ''));

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
t_ok($h('"><img src=x onerror=alert(1)>') === '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', 'la expresión de escape neutraliza < > "');
t_ok($h("a' onmouseover='x") === 'a&#039; onmouseover=&#039;x', "neutraliza comillas simples (href='%s' de INREPLYTO)");
t_fin();
