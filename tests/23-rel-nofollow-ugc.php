<?php
/**
 * 0.6.18: todo enlace del texto de un comentario lleva rel="nofollow ugc noreferrer" (ugc = contenido generado por
 * usuarios, Google). Se combina con rel ya presente (sin duplicar ni perder noopener), es idempotente y no toca
 * otras etiquetas salvo quitarles rel. HTMLPurifier REAL; ataques y casos limite.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__) . '/src/component';
$tmp = sys_get_temp_dir() . '/engage_t_' . bin2hex(random_bytes(4));
mkdir("$tmp/admin/components", 0777, true); mkdir("$tmp/cache");
symlink("$root/backend", "$tmp/admin/components/com_engage");
define('JPATH_ADMINISTRATOR', "$tmp/admin");
define('JPATH_CACHE', "$tmp/cache");
require "$root/backend/src/Helper/HtmlFilter.php";
require "$root/backend/src/Helper/BBCode.php";
require "$root/backend/src/Service/Html/Engage.php";
$GLOBALS['T_PARAMS']['com_engage'] = ['filter_mode' => 'strict', 'htmlpurifier_config_joomla' => 0];
$GLOBALS['T_PARAMS']['com_config'] = ['filters' => (object) [1 => (object) ['filter_type' => 'NONE', 'filter_tags' => '', 'filter_attributes' => '']]];
$GLOBALS['T_GROUPS'] = [1];
$svc = (new ReflectionClass(\Akeeba\Component\Engage\Administrator\Service\Html\Engage::class))->newInstanceWithoutConstructor();
$rel = new ReflectionMethod($svc, 'processRelAttributes'); $rel->setAccessible(true);
$R = fn(string $h) => $rel->invoke($svc, $h);
$OK = 'rel="nofollow ugc noreferrer"';

// Etiqueta <a> directa (lo que quede tras el purificador, p. ej. modo "joomla" o configuracion con rel permitido)
$casos = [
	'sin rel'                 => ['<a href="https://e.com/">x</a>', "<a $OK href=\"https://e.com/\">x</a>"],
	'rel nofollow'            => ['<a rel="nofollow" href="https://e.com/">x</a>', "<a $OK href=\"https://e.com/\">x</a>"],
	'noopener nofollow'       => ['<a href="https://e.com/" rel="noopener nofollow">x</a>', '<a rel="nofollow ugc noreferrer noopener" href="https://e.com/">x</a>'],
	'mayusculas y espacios'   => ["<A HREF=\"https://e.com/\" REL = \"  NoFollow   NOOPENER \">x</A>", '<A rel="nofollow ugc noreferrer noopener" HREF="https://e.com/">x</A>'],
	'comillas simples'        => ["<a rel='external' href='https://e.com/'>x</a>", "<a rel=\"nofollow ugc noreferrer external\" href='https://e.com/'>x</a>"],
	'rel sin comillas'        => ['<a rel=noopener href=https://e.com/>x</a>', '<a rel="nofollow ugc noreferrer noopener" href=https://e.com/>x</a>'],
	'dos rel (el 1o gana en navegador)' => ["<a rel='me' href=\"https://e.com/\" rel=\"nofollow\">x</a>", '<a rel="nofollow ugc noreferrer me" href="https://e.com/">x</a>'],
	'rel dentro de otro atributo' => ['<a title="x rel=\'z\' y" href="https://e.com/">x</a>', "<a $OK title=\"x rel='z' y\" href=\"https://e.com/\">x</a>"],
	'> dentro de atributo'    => ['<a title="a>b" href="https://e.com/">x</a>', "<a $OK title=\"a>b\" href=\"https://e.com/\">x</a>"],
	'sin href: no se toca'    => ['<a name="ancla">x</a>', '<a name="ancla">x</a>'],
	'otras etiquetas pierden rel' => ['<span rel="nofollow">x</span><link rel="stylesheet" href="x">', '<span>x</span><link href="x">'],
	'valor con basura'        => ['<a rel="x&quot;onclick=alert(1) y" href="https://e.com/">x</a>', null],
];
foreach ($casos as $n => [$in, $esp]) {
	$o = $R($in);
	if ($esp !== null) { t_ok($o === $esp, "$n: $o"); }
	t_ok($R($o) === $o, "$n: idempotente");
}
$o = $R($casos['valor con basura'][0]);
t_ok(preg_match('/^<a rel="nofollow ugc noreferrer( [a-z0-9_-]+)*" href="https:\/\/e\.com\/">x<\/a>$/', $o) === 1 && !str_contains($o, 'onclick=alert(1) '), 'valor de rel hostil: solo quedan fichas [a-z0-9_-] dentro de las comillas');
$o = $R('<a href="https://a.com">1</a> y <a rel="noopener" href="https://b.com">2</a> y <a href="/interno">3</a>');
t_ok(substr_count($o, 'rel="nofollow ugc noreferrer') === 3, 'varios enlaces: los tres llevan rel (el interno escrito por el usuario tambien: es contenido del usuario)');
t_ok($R('') === '' && $R('sin etiquetas') === 'sin etiquetas', 'vacio y texto plano intactos');

// Camino completo con el purificador real (HTML y BBCode)
$abre = function (string $h): array { preg_match_all('/<a\b[^>]*>/i', $h, $m); return $m[0]; };
$todos = function (string $h) use ($abre): bool {
	foreach ($abre($h) as $a) { if (!str_contains($a, 'href=')) { continue; } if (substr_count($a, 'rel=') !== 1 || !str_contains($a, 'rel="nofollow ugc noreferrer')) { return false; } }
	return true;
};
$entradas = [
	'<p>hola <a href="https://e.com/">uno</a> <a href=\'https://f.com/?a=1&b=2\' rel=\'noopener\'>dos</a></p>',
	'<p>x <a href="javascript:alert(1)">mal</a> <a href="JaVaScRiPt:alert(1)" rel="nofollow">mal2</a> <a href="https://ok.com" onclick="alert(1)">ok</a></p>',
	'texto [url=https://e.com/]enlace[/url] y [url]https://g.com/[/url] y [url=javascript:alert(1)]malo[/url]',
	'<p>enlace <a href="https://e.com/" rel="noopener nofollow">x</a></p>',
];
foreach ($entradas as $i => $e) {
	foreach (['strict', 'htmlpurifier'] as $modo) {
		$GLOBALS['T_PARAMS']['com_engage']['filter_mode'] = $modo;
		$rp = new ReflectionClass(\Akeeba\Component\Engage\Administrator\Helper\HtmlFilter::class);
		foreach (['filterMode', 'joomlaFilterSettings', 'purifier'] as $p) { $x = $rp->getProperty($p); $x->setAccessible(true); $x->setValue(null, null); }
		$o = $svc->processCommentTextForDisplay($e);
		t_ok($todos($o), "entrada $i ($modo): todos los <a> con href (los saneados quedan sin href) con un unico rel=\"nofollow ugc noreferrer\": " . implode(' ', $abre($o)));
		t_ok(!preg_match('/javascript:|onclick|<script/i', $o), "entrada $i ($modo): sin javascript:/onclick");
		t_ok($svc->processCommentTextForDisplay($o) === $o || $todos($svc->processCommentTextForDisplay($o)), "entrada $i ($modo): segunda pasada sin duplicar");
	}
}
// Fuente: BBCode y ausencia de restos del rel antiguo
t_ok(str_contains(file_get_contents("$root/backend/src/Helper/BBCode.php"), '<a rel="nofollow ugc noreferrer" href='), 'BBCode emite nofollow ugc noreferrer');
t_ok(!str_contains(file_get_contents("$root/backend/src/Service/Html/Engage.php"), 'rel="nofollow noreferrer"'), 'Engage.php ya no emite el rel antiguo');
t_ok(str_contains(file_get_contents("$root/../modules/site/engage_latest/tmpl/default.php"), "processCommentTextForDisplay', \$comment->body"), 'modulo engage_latest usa la misma funcion');
t_fin();
