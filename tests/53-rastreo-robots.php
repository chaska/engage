<?php
/**
 * 0.9.4: menos rastreo de robots. Inventario y cuenta de URLs, nofollow en TODOS los enlaces a variantes (fecha, respuesta, hilo, paginacion de
 * Joomla, modulo), meta robots / X-Robots-Tag / canonical solo en las variantes (nunca en la URL base), opcion, semaforo y manual.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/component/backend/src/Helper/ListOrdering.php";
require_once "$root/component/backend/src/Helper/CommentTools.php";
require_once "$root/component/backend/src/Helper/PanelHealth.php";

use Akeeba\Component\Engage\Administrator\Helper\CommentTools as T;
use Akeeba\Component\Engage\Administrator\Helper\PanelHealth as H;

echo "A) Variantes y meta robots\n";
t_ok(T::isVariant(['option', 'akengage_cid']) && T::isVariant(['AKENGAGE_sort']) && T::isVariant(['akengage_limitstart']) && T::isVariant(['akengage_fav']) && T::isVariant(['akengage_xyz']), 'cualquier akengage_* (comentario, pagina, orden, favoritos, desconocido) es una variante');
t_ok(!T::isVariant([]) && !T::isVariant(['option', 'view', 'id', 'catid', 'start', 'limitstart']) && !T::isVariant([3, null, ['akengage_cid']]), 'la URL base (y los parametros de Joomla) NO es una variante');
t_ok(T::robotsValue(true, false, true) === 'noindex, follow', 'variante: noindex, follow');
t_ok(T::robotsValue(true, true, true) === 'noindex, nofollow' && T::robotsValue(false, true, false) === 'noindex, nofollow', 'favoritos (personal): noindex, nofollow siempre');
t_ok(T::robotsValue(false, false, true) === null && T::robotsValue(false, false, true, 'index, follow') === null, 'URL base: nada que anadir (indexable)');
t_ok(T::robotsValue(true, false, false) === null, 'con la opcion apagada no se anade nada');
t_ok(T::robotsValue(true, false, true, 'noindex, nofollow') === 'noindex, nofollow' && T::robotsValue(true, false, true, 'index, follow') === 'noindex, follow', 'si el sitio ya puso nofollow se respeta');

echo "B) Cuenta de URLs (300 comentarios, 20 por pagina)\n";
$u = T::crawlUrls(300, 20);
t_ok($u['cid'] === 300 && $u['pages'] === 15 && $u['pagination'] === 60 && $u['tools'] === 7 && $u['total'] === 367, 'URLs distintas: 300 + 15x4 + 7 = 367 (' . json_encode($u) . ')');
t_ok(T::crawlUrls(0, 20)['total'] === 0 && T::crawlUrls(5, 0)['total'] > 0 && T::crawlUrls(-4, 20)['total'] === 0, 'casos limite sin error');
t_ok(T::crawlUrls(300, 20, true, false)['total'] < $u['total'], 'sin selector hay menos URLs');

echo "C) nofollow en el HTML que genera Joomla\n";
$h = '<ul><li><a aria-label="Go to page 2" href="/i.php?a=1&amp;b=2" class="page-link">2</a></li><li><a rel="noopener" href="/x">x</a></li><li><A REL = "NoFollow ugc" href=y>y</A></li><abbr title="a>b">z</abbr><a title="a>b" href="/q">q</a></ul>';
$o = T::addNofollow($h);
t_ok(substr_count($o, 'rel="nofollow"') === 2 && str_contains($o, 'rel="nofollow noopener"') && str_contains($o, 'rel="nofollow ugc"'), 'cada enlace acaba con nofollow y se conservan los demas valores');
t_ok(T::addNofollow($o) === $o, 'idempotente');
t_ok(str_contains($o, '<abbr title="a>b">z</abbr>') && str_contains($o, '<a title="a>b" href="/q" rel="nofollow">q</a>'), 'no toca otras etiquetas ni se confunde con un > dentro de un atributo');

echo "D) Plantillas, cabeceras y modulo\n";
foreach (['default', 'default_list'] as $t) {
	$s = $lee("$root/component/frontend/tmpl/comments/$t.php");
	$plano = preg_replace('/<\?php.*?\?>/s', '', $s);
	$plano = str_replace('<?= $this->crawlRel() ?>', ' rel="nofollow"', (string) $plano);
	$plano = preg_replace('/<\?=.*?\?>/s', 'X', (string) $plano);
	preg_match_all('/<a\b[^>]*>/s', (string) $plano, $m);
	$sin = [];
	foreach ($m[0] as $tag) {
		if (!str_contains($tag, 'nofollow') && !str_contains($tag, 'target="_blank"')) { $sin[] = substr(preg_replace('/\s+/', ' ', $tag), 0, 80); }
	}
	t_ok(!$sin, "$t.php: todo enlace lleva nofollow (o crawlRel()), salvo la consulta de IP del moderador (target=_blank, rel noopener noreferrer)" . ($sin ? ' [' . implode(' | ', $sin) . ']' : ''));
}
$dl = $lee("$root/component/frontend/tmpl/comments/default_list.php");
t_ok(substr_count($dl, '$this->crawlRel()') === 3, 'fecha del comentario, «en respuesta a» y «ver en el hilo» usan crawlRel()');
t_ok(str_contains($dl, 'rel="nofollow ugc noopener noreferrer"'), 'el perfil externo lleva nofollow ugc noopener noreferrer');
t_ok(str_contains($lee("$root/component/frontend/tmpl/comments/default.php"), '$this->paginationLinksHtml()') && !str_contains($lee("$root/component/frontend/tmpl/comments/default.php"), 'getPagesLinks()'), 'la paginacion de Joomla pasa por addNofollow');
$v = $lee("$root/component/frontend/src/View/Comments/HtmlView.php");
t_ok(str_contains($v, "setHeader('X-Robots-Tag', \$robots, true)") && str_contains($v, "setMetaData('robots', \$robots)"), 'meta robots y X-Robots-Tag con el mismo valor');
t_ok(str_contains($v, 'robotsValue($variant, $favorites, $reduce') && str_contains($v, 'CommentTools::isVariant(array_keys((array) Factory::getApplication()->getInput()->get->getArray()))') && !preg_match('/\$_(GET|SERVER|POST|REQUEST)/', $v), 'la variante se decide por los NOMBRES de la consulta (entrada GET de Joomla), sin superglobales');
t_ok(str_contains($v, 'CommentTools::trustedOrigin((string) Factory::getApplication()->get(\'live_site\'') && str_contains($v, 'CommentTools::cleanUrl('), 'el canonical sigue usando solo live_site y la URL limpia (sin akengage_*)');
foreach (['en-GB', 'es-ES'] as $lg) {
	$ini = $lee("$root/modules/site/engage_latest/language/$lg/mod_engage_latest.ini");
	t_ok(preg_match('/COMMENTED_ON=.*<a href="%s" rel="nofollow">/', $ini) === 1, "$lg: el enlace con la fecha del modulo «Ultimos comentarios» lleva nofollow");
}
$mt = $lee("$root/modules/site/engage_latest/tmpl/default.php");
t_ok(str_contains($mt, "setVar('akengage_cid'") && str_contains($mt, 'MOD_ENGAGE_LATEST_LBL_COMMENTED_ON'), 'el modulo sigue emitiendo el enlace con akengage_cid a traves de la cadena con nofollow');

echo "E) Opcion, idiomas, semaforo y manual\n";
$cx = $lee("$root/component/backend/config.xml");
t_ok(preg_match('/name="crawl_reduce"\s+type="radio"\s+layout="joomla.form.field.radio.switcher"\s+label="COM_ENGAGE_CONFIG_CRAWL_REDUCE_LABEL"\s+description="COM_ENGAGE_CONFIG_CRAWL_REDUCE_DESC"\s+default="1"/', $cx) === 1, 'config.xml: «Reducir el rastreo de robots», 1/0, 1 por defecto');
t_ok(T::options([])['crawl_reduce'] === true && T::options(['crawl_reduce' => '0'])['crawl_reduce'] === false && T::options(['crawl_reduce' => 'x'])['crawl_reduce'] === true, 'options(): valor ajeno => por defecto (1)');
foreach (['en-GB', 'es-ES'] as $lg) {
	$sys = $lee("$root/component/backend/language/$lg/com_engage.sys.ini");
	$ini = $lee("$root/component/backend/language/$lg/com_engage.ini");
	foreach (['COM_ENGAGE_CONFIG_CRAWL_REDUCE_LABEL', 'COM_ENGAGE_CONFIG_CRAWL_REDUCE_DESC'] as $k) { t_ok(str_contains($sys, "$k=\""), "$lg: $k"); }
	foreach (['TITLE', 'ON', 'ON_MANY', 'OFF_FEW', 'OFF_MANY'] as $k) { t_ok(str_contains($ini, "COM_ENGAGE_PANEL_H_CRAWL_$k=\""), "$lg: semaforo CRAWL_$k"); }
}
$base = ['params' => ['crawl_reduce' => 1, 'default_limit' => 20, 'sort_selector' => 1]];
$g = fn(array $f) => array_values(array_filter(H::evaluate($f)['items'], fn($i) => $i['id'] === 'crawl'))[0];
t_ok($g($base + ['crawl_comments' => 0])['level'] === 'ok', 'semaforo: sin comentarios, verde');
t_ok($g(['crawl_comments' => 40, 'crawl_page' => 20] + $base)['level'] === 'ok', 'semaforo: reduccion activa y pocas URLs, verde');
$w = $g(['crawl_comments' => 300, 'crawl_page' => 20] + $base);
t_ok($w['level'] === 'warn' && $w['args']['state'] === 'on_many' && $w['args']['a'] === 300 && $w['args']['b'] === 367, 'semaforo: reduccion activa pero 367 URLs, ambar (robots.txt)');
$off = $g(['crawl_comments' => 300, 'crawl_page' => 20, 'params' => ['crawl_reduce' => 0, 'sort_selector' => 1]]);
t_ok($off['level'] === 'warn' && $off['args']['state'] === 'off_many' && $off['fix']['section'] === 'advanced', 'semaforo: opcion apagada y 367 URLs, ambar con enlace a Opciones > Avanzado');
t_ok($g(['crawl_comments' => 30, 'crawl_page' => 20, 'params' => ['crawl_reduce' => 0]])['level'] === 'ok', 'semaforo: opcion apagada y pocas URLs, verde');
t_ok($g([])['level'] === 'ok', 'semaforo: sin ningun dato no falla');
$lay = $lee("$root/component/backend/src/Helper/SettingsLayout.php");
t_ok(str_contains($lay, "[\$c, 'crawl_reduce']"), 'el panel moderno de opciones incluye crawl_reduce');
$md = $lee(dirname($root) . '/docs/ROBOTS-Y-TRAFICO.md');
foreach (['Disallow: /*akengage_cid=', 'Disallow: /*akengage_', 'Disallow: /*akengage_limitstart=', '367', 'robots.txt', 'X-Robots-Tag', 'access.log', 'Search Console'] as $k) { t_ok(str_contains($md, $k), "docs/ROBOTS-Y-TRAFICO.md: $k"); }
t_ok(preg_match('/ignoran|ignora/i', $md) === 1 && preg_match('/cortafuegos/i', $md) === 1, 'el manual avisa de que los robots maliciosos ignoran robots.txt');
t_fin();
