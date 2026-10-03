<?php
/**
 * 0.4.2: (S4) mapeo de los filtros de texto de Joomla a HTML Purifier (opción htmlpurifier_config_joomla) y
 * (S7) mayúsculas de HTMLPurifier.auto.php / HTMLPurifier.includes.php. HTML Purifier REAL.
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
use Akeeba\Component\Engage\Administrator\Helper\HtmlFilter;

// ---- S7: modo de carga por argumento (proceso hijo) ----
if (($argv[1] ?? '') === 'hijo') {
	$GLOBALS['T_PARAMS']['com_engage'] = ['htmlpurifier_include' => $argv[2]];
	HtmlFilter::includeHTMLPurifier();
	echo class_exists('HTMLPurifier') && class_exists('HTMLPurifier_Config') ? "CARGADO\n" : "NO\n";
	exit(0);
}

echo "S7) carga de HTML Purifier en un sistema de archivos que distingue mayúsculas\n";
$lib = "$root/backend/vendor/ezyang/htmlpurifier/library";
t_ok(is_file("$lib/HTMLPurifier.auto.php") && is_file("$lib/HTMLPurifier.includes.php"), 'existen HTMLPurifier.auto.php y HTMLPurifier.includes.php');
t_ok(!is_file("$lib/HTMLPUrifier.auto.php"), 'el nombre con mayúsculas erróneas (HTMLPUrifier) no existe');
$src = file_get_contents("$root/backend/src/Helper/HtmlFilter.php");
t_ok(strpos($src, 'HTMLPUrifier') === false, 'HtmlFilter.php ya no contiene "HTMLPUrifier"');
foreach (['composer', 'auto', 'all'] as $modo) {
	$o = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' hijo ' . $modo . ' 2>&1'));
	t_ok($o === 'CARGADO', "includeHTMLPurifier modo '$modo' -> $o");
}

echo "S4) mapeo de filtros de Joomla\n";
HtmlFilter::includeHTMLPurifier();
function reset_filter(): void
{
	$rc = new ReflectionClass(HtmlFilter::class);
	foreach (['filterMode' => null, 'joomlaFilterSettings' => null, 'purifier' => null] as $p => $v) {
		$rp = $rc->getProperty($p); $rp->setAccessible(true); $rp->setValue(null, $v);
	}
}
function f(string $type, string $tags = '', string $attrs = ''): object
{
	return (object) ['filter_type' => $type, 'filter_tags' => $tags, 'filter_attributes' => $attrs];
}
/** $filtros: groupId => filtro. El espectador pertenece a todos los grupos indicados. */
function conf(array $filtros, string $mode = 'strict'): void
{
	$GLOBALS['T_PARAMS']['com_engage'] = ['filter_mode' => $mode, 'htmlpurifier_config_joomla' => 1];
	$GLOBALS['T_PARAMS']['com_config'] = ['filters' => (object) $filtros];
	$GLOBALS['T_GROUPS'] = array_keys($filtros);
	reset_filter();
}
function ajustes(): array
{
	$m = new ReflectionMethod(HtmlFilter::class, 'getJoomlaFilterSettings'); $m->setAccessible(true);
	return $m->invoke(null);
}

// Lista negra personalizada (CBL): etiquetas y atributos van cada uno a su lista
conf([1 => f('CBL', 'font,custom', 'title,lang')]);
$a = ajustes();
t_ok($a['filterType'] === 'blacklist', 'CBL -> blacklist');
t_ok(array_values($a['blacklistTags']) === ['font', 'custom'], 'CBL: blacklistTags = solo las etiquetas personalizadas');
t_ok(array_values($a['blacklistAttributes']) === ['title', 'lang'], 'CBL: blacklistAttributes = los atributos personalizados (antes se mezclaban en blacklistTags)');
$o = HtmlFilter::filterText('<p><font size="3">f</font> <a href="http://example.com/" title="t" lang="es">l</a></p>');
t_ok(!str_contains($o, '<font') && str_contains($o, 'href="http://example.com/"') && !str_contains($o, 'title=') && !str_contains($o, 'lang='), "CBL purifica: $o");

// Lista negra por defecto (BL) con lista blanca que la recorta
conf([1 => f('BL', 'u,b', 'title,lang'), 2 => f('WL', 'b', 'lang')]);
$a = ajustes();
t_ok($a['filterType'] === 'blacklist', 'BL -> blacklist');
t_ok(in_array('script', $a['blacklistTags']) && in_array('u', $a['blacklistTags']) && !in_array('b', $a['blacklistTags']), 'BL: etiquetas = defecto + configuradas - lista blanca (script y u sí, b no)');
t_ok(in_array('formaction', $a['blacklistAttributes']) && in_array('title', $a['blacklistAttributes']) && !in_array('lang', $a['blacklistAttributes']), 'BL: atributos = defecto + configurados - lista blanca (formaction y title sí, lang no)');
$o = HtmlFilter::filterText('<p><b>b</b><u>u</u> <a href="http://example.com/" title="t" lang="es">l</a></p><script>x()</script>');
t_ok(str_contains($o, '<b>') && !str_contains($o, '<u>') && !str_contains($o, 'title=') && str_contains($o, 'lang="es"') && !str_contains($o, '<script'), "BL purifica: $o");
conf([1 => f('BL', '', '')]);
$a = ajustes();
t_ok(in_array('script', $a['blacklistTags']) && !isset($a['blacklistTags'][99]), 'BL sin personalización: conserva la lista por defecto');
t_ok(!str_contains(HtmlFilter::filterText('<script>x()</script><p>ok</p>'), '<script'), 'BL por defecto purifica <script>');

// Lista blanca (WL): antes TypeError (whitelistTags era un bool)
conf([1 => f('WL', 'p,a,b', 'href')]);
$a = ajustes();
t_ok($a['filterType'] === 'whitelist' && array_values($a['whitelistTags']) === ['p', 'a', 'b'] && array_values($a['whitelistAttributes']) === ['href'], 'WL: whitelistTags/Attributes son las listas (no un bool)');
try {
	$o = HtmlFilter::filterText('<p>a<i>i</i><a href="http://example.com/" title="t" onclick="x()">l</a><b>b</b><u>u</u></p><script>x()</script>');
	t_ok(!str_contains($o, '<i>') && !str_contains($o, '<u>') && str_contains($o, '<b>') && str_contains($o, 'href="http://example.com/"') && !str_contains($o, 'title=') && !str_contains($o, 'onclick') && !str_contains($o, '<script'), "WL purifica sin excepción: $o");
} catch (Throwable $e) {
	t_ok(false, 'WL lanzó ' . get_class($e) . ': ' . $e->getMessage());
}
// WL con etiquetas separadas por huecos (array_unique deja huecos en los índices)
conf([1 => f('WL', 'p,p,b,b,a', 'href,href')]);
try {
	$o = HtmlFilter::filterText('<p><b>b</b><a href="http://example.com/">l</a></p>');
	t_ok(str_contains($o, '<b>') && str_contains($o, 'href='), "WL con duplicados: $o");
} catch (Throwable $e) {
	t_ok(false, 'WL con duplicados lanzó ' . $e->getMessage());
}
conf([1 => f('WL', 'p,a', '')]);
try {
	$o = HtmlFilter::filterText('<p><a href="http://example.com/">l</a></p>');
	t_ok(!str_contains($o, 'href='), "WL sin atributos permitidos: no se deja ningún atributo: $o");
} catch (Throwable $e) {
	t_ok(false, 'WL sin atributos lanzó ' . $e->getMessage());
}
t_fin();
