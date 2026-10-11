<?php
/**
 * 0.8.3 (H9): HTML Purifier dejaba `background:url(http://...)` en span[style] (pixel de seguimiento para quien lee el comentario) y valores de CSS que
 * recolocan el diseno (position, margenes negativos, tamanos enormes). Ejecuta el HTML Purifier REAL con la configuracion real de HtmlFilter
 * (configureStyles + restrictStyles) y la lista blanca por defecto del componente.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
require __DIR__ . '/stubs/otros-componentes.php';
foreach (['Joomla\CMS\Access\Access', 'Joomla\CMS\Filter\InputFilter'] as $c) { if (!class_exists($c)) { eval('namespace ' . substr($c, 0, strrpos($c, '\\')) . '; class ' . substr($c, strrpos($c, '\\') + 1) . ' {}'); } }
$root = dirname(__DIR__) . '/src/component/backend';
require "$root/vendor/autoload.php";
require_once "$root/src/Helper/HtmlFilter.php";

use Akeeba\Component\Engage\Administrator\Helper\HtmlFilter;

$cache = sys_get_temp_dir() . '/engage_hp_' . bin2hex(random_bytes(4)); mkdir($cache);
$mk = function (bool $nuevo) use ($cache) {
	$c = HTMLPurifier_Config::createDefault();
	$c->set('Core.Encoding', 'UTF-8'); $c->set('HTML.Doctype', 'HTML 4.01 Transitional'); $c->set('Cache.SerializerPath', $cache . ($nuevo ? '/n' : '/v'));
	@mkdir($cache . ($nuevo ? '/n' : '/v'));
	$c->set('HTML.Allowed', 'p,b,a[href],i,u,strong,em,small,big,span[style],font[size],font[color],ul,ol,li,br,img[src],img[width],img[height],code,pre,blockquote');
	if ($nuevo) { HtmlFilter::configureStyles($c); }

	return $nuevo ? fn(string $h) => HtmlFilter::restrictStyles((new HTMLPurifier($c))->purify($h)) : fn(string $h) => (new HTMLPurifier($c))->purify($h);
};
$antes = $mk(false); $ahora = $mk(true);

echo "A) El problema, con la configuracion de la 0.8.2\n";
$px = '<span style="background:url(http://tracker.test/p.gif)">x</span>';
t_ok(str_contains($antes($px), 'tracker.test'), 'reproducido: la 0.8.2 deja url(http://tracker.test/p.gif): ' . $antes($px));

echo "B) Ahora\n";
$malos = [
	$px, '<span style="background:url(//tracker.test/p.gif)">x</span>', '<span style="background-image:url(\'http://t.test/p\')">x</span>', '<span style="background-color:url(http://t.test/p)">x</span>',
	'<span style="BACKGROUND: URL(http://t.test/p)">x</span>', '<span style="background:\75rl(http://t.test/p)">x</span>', '<span style="color:red;background:url(http://t.test/p)">x</span>',
	'<span style="position:fixed;top:0;left:0">x</span>', '<span style="position:absolute;width:9999px;height:9999px">x</span>', '<span style="margin-left:-9999px">x</span>',
	'<span style="margin:-100px">x</span>', '<span style="padding:99999px">x</span>', '<span style="float:right">x</span>', '<span style="display:block;width:100000px">x</span>',
	'<span style="font-size:9999px">x</span>', '<span style="font-size:500%">x</span>', '<span style="font-size:1000em">x</span>', '<span style="font-size:10%">x</span>', '<span style="z-index:9999">x</span>',
	'<span style="color:red !important">x</span>', '<span style="content:url(http://t.test/p)">x</span>', '<span style="expression(alert(1))">x</span>', '<span style="color:expression(alert(1))">x</span>',
	'<span style="behavior:url(x.htc)">x</span>', '<span style="-moz-binding:url(http://t.test/x)">x</span>', '<span style="@import \'http://t.test/x\'">x</span>', '<span style="color:red;;;font-weight:bold;position:relative">x</span>',
	'<span style="transform:scale(1000)">x</span>', '<span style="line-height:99999px">x</span>', '<span style="text-indent:-9999px">x</span>', '<span style="letter-spacing:99999px">x</span>',
];
$mal = [];
foreach ($malos as $h) {
	$o = $ahora($h);
	if (preg_match('/url|http|position|margin|padding|width|height|float|display|z-index|expression|import|binding|behavior|content|transform|line-height|indent|spacing|!important|9999|1000|500%|10%/i', $o)) { $mal[] = substr($h, 0, 50) . ' => ' . $o; }
}
t_ok(!$mal, count($malos) . ' estilos hostiles: ninguno deja url(), http, position, margenes, tamanos ni valores extremos. ' . implode(' | ', array_slice($mal, 0, 3)));

echo "C) Lo legitimo se conserva\n";
foreach ([
	['<span style="color:red">x</span>', 'color:#ff0000'], ['<span style="color:#ff0000">x</span>', 'color:#ff0000'], ['<span style="color:rgb(255,0,0)">x</span>', 'color:'],
	['<span style="font-weight:bold">x</span>', 'font-weight:bold'], ['<span style="font-style:italic">x</span>', 'font-style:italic'], ['<span style="text-decoration:underline">x</span>', 'text-decoration:underline'],
	['<span style="text-decoration:line-through">x</span>', 'text-decoration:line-through'], ['<span style="font-size:150%">x</span>', 'font-size:150%'], ['<span style="font-size:large">x</span>', 'font-size:large'],
	['<span style="background-color:yellow">x</span>', 'background-color:#ffff00'],
] as [$h, $esp]) {
	$o = $ahora($h);
	t_ok(str_contains(str_replace(' ', '', $o), str_replace(' ', '', $esp)), "se conserva $esp -> $o");
}
$o = $ahora('<span style="color:red;background:url(http://t.test/p);font-weight:bold">x</span>');
t_ok(str_contains($o, 'color: #ff0000') && str_contains($o, 'font-weight: bold') && !str_contains($o, 'url'), 'mezcla: se quita solo lo malo -> ' . $o);
$o = $ahora('<p>Hola <b>mundo</b> <a href="https://example.com/">enlace</a> <i>cursiva</i></p>');
t_ok($o === '<p>Hola <b>mundo</b> <a href="https://example.com/">enlace</a> <i>cursiva</i></p>', 'el HTML sin estilos no cambia');
t_ok(HtmlFilter::restrictStyles('<span style="color:red">a</span><span style="position:fixed">b</span>') === '<span style="color: red">a</span><span>b</span>', 'restrictStyles quita el atributo vacio');
t_ok(HtmlFilter::restrictStyles("<span style='color:red\"><script>'>x</span>") !== null, 'restrictStyles no revienta con comillas cruzadas');

echo "D) El codigo\n";
$src = str_replace("\r\n", "\n", file_get_contents("$root/src/Helper/HtmlFilter.php"));
t_ok(str_contains($src, 'self::configureStyles($config);') && str_contains($src, 'return self::restrictStyles(self::getHTMLPurifier()->purify($text));'), 'getHTMLPurifier aplica la lista cerrada y purify() la comprueba despues');
t_fin();
