<?php
/**
 * 0.5.1: HTMLPurifier vendorizado es la 4.19.1 oficial y funciona (carga, purificación, sin avisos
 * de PHP al ejecutarlo, rapidez ante el patrón de retroceso catastrófico de Core.AggressivelyFixLt).
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src/component/backend/vendor';

t_ok(trim(file_get_contents("$root/ezyang/htmlpurifier/VERSION")) === '4.19.1', 'VERSION = 4.19.1');
$inst = require "$root/composer/installed.php";
t_ok(($inst['versions']['ezyang/htmlpurifier']['pretty_version'] ?? '') === 'v4.19.1'
	&& ($inst['versions']['ezyang/htmlpurifier']['reference'] ?? '') === '5e0539132d934f936fbae2c88b55d58f9438623f', 'installed.php: v4.19.1, commit 5e0539132d (etiqueta oficial)');
t_ok(is_file("$root/ezyang/htmlpurifier/LICENSE") && str_contains(file_get_contents("$root/ezyang/htmlpurifier/LICENSE"), 'GNU LESSER GENERAL PUBLIC LICENSE'), 'LICENSE (LGPL 2.1) conservada');
t_ok(str_contains(file_get_contents("$root/ezyang/htmlpurifier/composer.json"), '~8.4.0'), 'composer.json declara soporte de PHP 8.4');

$avisos = [];
set_error_handler(function ($no, $msg, $file, $line) use (&$avisos) { $avisos[] = "[$no] $msg ($file:$line)"; return true; });
error_reporting(E_ALL);
require "$root/autoload.php";
t_ok(HTMLPurifier::VERSION === '4.19.1', 'HTMLPurifier::VERSION = ' . HTMLPurifier::VERSION);

$cache = sys_get_temp_dir() . '/engage_hp_' . bin2hex(random_bytes(4));
mkdir($cache);
$c = HTMLPurifier_Config::createDefault();
$c->set('Core.Encoding', 'UTF-8');
$c->set('HTML.Doctype', 'HTML 4.01 Transitional');
$c->set('Cache.SerializerPath', $cache);
$c->set('HTML.Allowed', 'p,b,a[href],i,u,strong,em,small,big,span[style],font[size],font[color],ul,ol,li,br,img[src],img[width],img[height],code,pre,blockquote');
$p = new HTMLPurifier($c);

$ataques = [
	'<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<a href="javascript:alert(1)">x</a>',
	'<a href="JaVaScRiPt:alert(1)">x</a>', '<a href="java&#x09;script:alert(1)">x</a>', '<iframe src="//evil"></iframe>',
	'<svg onload=alert(1)>', '<span style="background:url(javascript:alert(1))">x</span>', '<span style="expression(alert(1))">x</span>',
	'<p onclick="alert(1)">x</p>', '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
	'<<b>script>alert(1)<</b>/script>', '<img src="javascript:alert(1)">', '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
	"<a href=\"vbscript:msgbox(1)\">x</a>", '<form action="//evil"><input name=x></form>', '<object data="x"></object>', '<meta http-equiv="refresh" content="0;url=//evil">',
];
foreach ($ataques as $a) {
	$o = $p->purify($a);
	$malo = (bool) preg_match('/<script|<iframe|<svg|<form|<object|<meta|<style|onerror|onclick|onload|javascript:|vbscript:|data:text|expression\(/i', $o);
	t_ok(!$malo, 'purifica: ' . substr($a, 0, 50) . '  ->  ' . substr($o, 0, 60));
}
t_ok(str_contains($p->purify('<p>Hola <b>mundo</b> <a href="https://example.com/">enlace</a></p>'), '<a href="https://example.com/">enlace</a>'), 'conserva HTML legítimo (p, b, a[href])');

// Retroceso catastrófico en Core.AggressivelyFixLt (corregido en 4.19.0)
$c2 = HTMLPurifier_Config::createDefault();
$c2->set('Cache.SerializerPath', $cache);
$c2->set('Core.AggressivelyFixLt', true);
$t = microtime(true);
(new HTMLPurifier($c2))->purify(str_repeat('<', 3000) . str_repeat('a', 3000));
$seg = microtime(true) - $t;
t_ok($seg < 5, sprintf('entrada de retroceso catastrófico procesada en %.2f s (< 5 s)', $seg));

// Nulos y rutas de error que con 4.17 emitían deprecaciones
$c3 = HTMLPurifier_Config::createDefault();
$c3->set('Cache.SerializerPath', $cache);
$c3->set('Core.NormalizeNewlines', false);
(new HTMLPurifier($c3))->purify("<p>a\r\nb</p>");
restore_error_handler();
t_ok($avisos === [], 'ningún aviso/deprecación de PHP ' . PHP_VERSION . ' durante todo el uso' . ($avisos ? ': ' . implode(' | ', array_slice($avisos, 0, 3)) : ''));
exec('rm -rf ' . escapeshellarg($cache));
t_fin();
