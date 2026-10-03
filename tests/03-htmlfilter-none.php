<?php
/**
 * 0.4.1: HtmlFilter purifica siempre (también con el filtro "Sin filtrar" de Joomla del espectador, S2) y el
 * HTML de los comentarios se purifica al mostrarlo (processFlatComment). Usa HTMLPurifier REAL (src/.../vendor).
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

function reset_filter(): void
{
	$rc = new ReflectionClass(HtmlFilter::class);
	foreach (['filterMode' => null, 'joomlaFilterSettings' => null, 'purifier' => null] as $p => $v) {
		$rp = $rc->getProperty($p); $rp->setAccessible(true); $rp->setValue(null, $v);
	}
}
/** Configura modo, "usar filtros de Joomla" y filtros de grupo del espectador. */
function conf(string $mode, int $joomlaCfg, string $viewerFilter): void
{
	$GLOBALS['T_PARAMS']['com_engage'] = ['filter_mode' => $mode, 'htmlpurifier_config_joomla' => $joomlaCfg];
	$GLOBALS['T_PARAMS']['com_config'] = new stdClass();
	$f = new stdClass();
	$f->filters = (object) [1 => (object) ['filter_type' => $viewerFilter, 'filter_tags' => '', 'filter_attributes' => '']];
	$GLOBALS['T_PARAMS']['com_config'] = ['filters' => $f->filters];
	$GLOBALS['T_GROUPS'] = [1];
	reset_filter();
}

$ataque = '<p>hola</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)" onclick="alert(4)">enlace</a>'
	. '<iframe src="//evil"></iframe><span style="background:url(javascript:alert(5))">s</span><svg onload=alert(6)></svg><b>negrita</b>';
function seguro(string $o): bool
{
	foreach (['<script', 'onerror', 'onclick', 'onload', 'javascript:', '<iframe', '<svg'] as $mal) {
		if (stripos($o, $mal) !== false) { return false; }
	}
	return true;
}

HtmlFilter::includeHTMLPurifier();
t_ok(class_exists('HTMLPurifier'), 'HTMLPurifier real cargado por includeHTMLPurifier (modo composer)');

foreach (['strict', 'htmlpurifier'] as $modo) {
	foreach ([0, 1] as $cfgJ) {
		foreach (['NONE', 'NH', 'BL', 'CBL', 'WL'] as $tipo) {
			if ($cfgJ && $tipo === 'WL') { continue; } // error previo del mapeo de listas blancas (S4): se prueba en 04-htmlfilter-mapeo.php
			conf($modo, $cfgJ, $tipo);
			$o = HtmlFilter::filterText($ataque);
			t_ok(seguro($o), "modo=$modo config_joomla=$cfgJ espectador=$tipo -> sin código activo");
			if ($tipo === 'NONE') {
				t_ok(str_contains($o, 'hola') && str_contains($o, '<b>negrita</b>'), '   y conserva el contenido legítimo (p, b)');
			}
		}
	}
}

// Espectador "Sin filtrar": antes devolvía el texto tal cual; ahora no.
conf('strict', 0, 'NONE');
t_ok(HtmlFilter::filterText($ataque) !== $ataque, 'espectador Sin filtrar en strict: la salida difiere de la entrada (se purifica)');

// filterTextForDisplay
conf('strict', 0, 'NONE');
t_ok(seguro(HtmlFilter::filterTextForDisplay($ataque)), 'filterTextForDisplay strict/NONE: seguro');
conf('htmlpurifier', 0, 'NONE');
t_ok(seguro(HtmlFilter::filterTextForDisplay($ataque)), 'filterTextForDisplay htmlpurifier/NONE: seguro');
conf('joomla', 0, 'NONE');
t_ok(HtmlFilter::filterTextForDisplay($ataque) === $ataque, 'filterTextForDisplay modo joomla: sin cambios (decisión documentada)');
t_ok(HtmlFilter::filterTextForDisplay(null) === '', 'filterTextForDisplay(null) -> cadena vacía');

// Camino completo: processCommentTextForDisplay (lo usan frontend, módulo y correo) con HTML y espectador sin filtros
require "$root/backend/src/Helper/BBCode.php";
require "$root/backend/src/Service/Html/Engage.php";
$svc = (new ReflectionClass(\Akeeba\Component\Engage\Administrator\Service\Html\Engage::class))->newInstanceWithoutConstructor();
foreach (['strict', 'htmlpurifier'] as $modo) {
	conf($modo, 0, 'NONE');
	$o = $svc->processCommentTextForDisplay($ataque);
	t_ok(seguro($o) && str_contains($o, 'hola'), "processCommentTextForDisplay ($modo, espectador Sin filtrar): HTML de ataque neutralizado");
	t_ok(str_contains($o, 'nofollow') || !str_contains($o, '<a '), '   los enlaces restantes llevan nofollow');
}
conf('strict', 0, 'NONE');
$o = $svc->processCommentTextForDisplay('texto plano con <script>alert(1)</script> [b]negrita[/b]');
t_ok(seguro($o), 'comentario no-HTML (ruta BBCode): seguro');

// Módulo mod_engage_latest (S3): comprobación estática de la plantilla (no hay Joomla para renderizarla).
$tpl = file_get_contents($root . '/../modules/site/engage_latest/tmpl/default.php');
t_ok(!preg_match('/<\?=\s*\$comment->body/', $tpl), 'plantilla del módulo: no imprime $comment->body directamente');
t_ok(str_contains($tpl, "processCommentTextForDisplay', \$comment->body"), 'plantilla del módulo: pasa el cuerpo por processCommentTextForDisplay');
t_ok(str_contains($tpl, 'htmlspecialchars((string) $comment->user_name'), 'plantilla del módulo: escapa user_name');
t_fin();
