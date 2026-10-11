<?php
/**
 * 0.8.3 (H3): [url] e [img] dejaban pasar `javascript:` (y otros protocolos) cuando filter_mode = joomla, porque filter_var(FILTER_SANITIZE_URL) no mira el
 * protocolo y en ese modo nada mas limpia el HTML. Ejecuta el codigo REAL de BBCode::parseBBCode y BBCode::safeUrl.
 *  A) Solo http, https y mailto en [url]; solo http y https en [img]; direcciones relativas sin dos puntos antes de la primera / ? #
 *  B) Variantes de ofuscacion: mayusculas, tabuladores y saltos de linea dentro del protocolo, espacios y controles al inicio, entidades (decimal, hex,
 *     con y sin punto y coma, anidadas, &colon;, &Tab;, &NewLine;), data:, vbscript:, file:, blob:, ftp:, jar:, view-source:, comillas y angulos
 *  C) El texto de un enlace rechazado queda como texto inerte y el resultado de los enlaces buenos no cambia
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
require_once dirname(__DIR__) . '/src/component/backend/src/Helper/BBCode.php';

use Akeeba\Component\Engage\Administrator\Helper\BBCode;

$linkOk = fn(string $h): bool => (bool) preg_match('/<a [^>]*href="/i', $h);
$hrefs  = function (string $h): array { preg_match_all('/href="([^"]*)"/i', $h, $m); return $m[1]; };
$srcs   = function (string $h): array { preg_match_all('/src="([^"]*)"/i', $h, $m); return $m[1]; };

echo "A) Protocolos permitidos\n";
foreach (['http://example.com/a?b=1&c=2', 'https://example.com', 'HTTPS://EXAMPLE.COM', 'mailto:ana@example.com', 'MAILTO:ana@example.com', '/ruta/relativa', 'pagina.html', '//otro.host/x', '#ancla', '?q=1', 'ruta/a:b'] as $u) {
	$h = BBCode::parseBBCode("[url=$u]texto[/url]");
	t_ok($linkOk($h) && count($hrefs($h)) === 1, "[url] permitido: $u");
}
foreach (['http://example.com/i.png', 'https://example.com/i.png?x=1&y=2', 'HTTP://EXAMPLE.COM/I.PNG', '/img/x.png'] as $u) {
	$h = BBCode::parseBBCode("[img]{$u}[/img]");
	t_ok(count($srcs($h)) === 1, "[img] permitido: $u");
}
$h = BBCode::parseBBCode('[url=https://example.com/a?b=1&c=2]texto[/url]');
t_ok($h === '<a rel="nofollow ugc noreferrer" href="https://example.com/a?b=1&amp;c=2">texto</a>', 'el enlace normal sale como siempre (rel nofollow ugc noreferrer, & escapado): ' . $h);
$h = BBCode::parseBBCode('[url]https://example.com/[/url]');
t_ok($h === '<a rel="nofollow ugc noreferrer" href="https://example.com/">https://example.com/</a>', '[url] sin parametro: el texto es la direccion');
t_ok(BBCode::parseBBCode('[img]https://example.com/i.png[/img]') === '<img src="https://example.com/i.png" />', '[img] normal sin cambios');

echo "B) Variantes de ofuscacion rechazadas\n";
$malas = [
	'javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", "java\nscript:alert(1)", "java\rscript:alert(1)", " javascript:alert(1)",
	"\x01javascript:alert(1)", "\x00javascript:alert(1)", 'java script:alert(1)', "jav&#x09;ascript:alert(1)", 'jav&#9;ascript:alert(1)', 'jav&Tab;ascript:alert(1)', 'jav&NewLine;ascript:alert(1)',
	'&#106;avascript:alert(1)', '&#x6A;avascript:alert(1)', '&#106avascript:alert(1)', '&#0000106avascript:alert(1)', 'javascript&colon;alert(1)', 'javascript&#58;alert(1)', 'javascript&#x3a;alert(1)',
	'&amp;#106;avascript:alert(1)', '&amp;amp;#106;avascript:alert(1)', 'javascript:/*http://*/alert(1)', 'data:text/html,<script>alert(1)</script>', 'DATA:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
	'vbscript:msgbox(1)', 'VBScript:msgbox(1)', 'file:///etc/passwd', 'blob:http://x/1', 'ftp://example.com/x', 'jar:http://x!/y', 'view-source:http://x', 'about:blank', 'tel:+34123456789',
	'x" onmouseover="alert(1)', "x' onmouseover='alert(1)", 'http://x/"><script>alert(1)</script>', 'http://x/<b>', 'http://x/\\evil', 'javascript:alert(1)//http://ok',
	"\u{00a0}javascript:alert(1)", "\u{200b}javascript:alert(1)", "\u{feff}javascript:alert(1)", "java\u{2028}script:alert(1)", 'a:b', 'x:y/z',
];
$mal = [];
foreach ($malas as $u) {
	foreach (["[url=%s]clic[/url]", "[url]%s[/url]", "[URL = %s ]clic[/URL]"] as $fmt) {
		$h = BBCode::parseBBCode(sprintf($fmt, $u));
		if ($hrefs($h) !== [] || stripos($h, 'href') !== false || stripos($h, '<script') !== false) { $mal[] = 'url:' . json_encode($u); }
	}
	$h = BBCode::parseBBCode("[img]{$u}[/img]");
	if ($srcs($h) !== [] || stripos($h, 'src=') !== false || stripos($h, '<script') !== false) { $mal[] = 'img:' . json_encode($u); }
}
t_ok(!$mal, count($malas) . ' URL hostiles x [url=], [url]..[/url], [URL = ] e [img]: ningun href ni src sale; ' . implode(' ', array_slice($mal, 0, 4)));
t_ok(BBCode::safeUrl('mailto:a@b.c', ['http', 'https']) === null, '[img] no admite mailto');
t_ok(BBCode::safeUrl('mailto:a@b.c', ['http', 'https', 'mailto']) === 'mailto:a@b.c', '[url] admite mailto');
t_ok(BBCode::safeUrl(str_repeat('a', 3000), ['http']) === null, 'URL de mas de 2048 caracteres: no');
t_ok(BBCode::safeUrl('', ['http']) === null && BBCode::safeUrl("\t \n", ['http']) === null, 'URL vacia: no');

echo "C) Texto inerte\n";
$h = BBCode::parseBBCode('[url=javascript:alert(1)]<b onclick=x>clic</b>[/url]');
t_ok(!str_contains($h, '<') && str_contains($h, 'clic'), 'el texto del enlace rechazado queda sin etiquetas (escapado): ' . $h);
$h = BBCode::parseBBCode('[url=javascript:alert(1)][/url]');
t_ok($h === '', 'enlace rechazado y vacio: nada');
$h = BBCode::parseBBCode("antes [url=javascript:alert(1)]x[/url] [b]negrita[/b] [url=https://ok.test]ok[/url] despues");
t_ok(str_contains($h, '<strong>negrita</strong>') && $hrefs($h) === ['https://ok.test'], 'el resto del mensaje sigue funcionando junto a un enlace rechazado');
t_fin();
