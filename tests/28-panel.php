<?php
/**
 * 0.6.24: panel de control. Estaticas y con datos simulados (sin Joomla): archivos, manifiesto (submenu), Dispatcher,
 * idiomas (todas las claves usadas existen en en-GB y es-ES), semaforos con hechos simulados, serie y geometria del grafico,
 * iconos, seguridad estatica de plantilla/JS/CSS (escapado, sin estilos ni scripts en linea, sin recursos externos, sin confirm()),
 * contraste WCAG de los tres disenos calculado sobre las variables del CSS, version del fork frente al CHANGELOG y finales de
 * linea. El comportamiento en navegador y contra la base de datos real esta en tests/joomla-live/11-panel.sh.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => (string) file_get_contents($f);
$lf   = fn(string $f): string => str_replace("\r\n", "\n", $lee($f));
foreach (['PanelHealth', 'PanelChart', 'PanelIcons', 'PanelData'] as $k) { require_once "$c/backend/src/Helper/$k.php"; }
use Akeeba\Component\Engage\Administrator\Helper\PanelChart;
use Akeeba\Component\Engage\Administrator\Helper\PanelData;
use Akeeba\Component\Engage\Administrator\Helper\PanelHealth as H;
use Akeeba\Component\Engage\Administrator\Helper\PanelIcons;

echo "A) Archivos, manifiesto, Dispatcher, activos\n";
foreach (['backend/src/Controller/ControlpanelController.php', 'backend/src/View/Controlpanel/HtmlView.php', 'backend/tmpl/controlpanel/default.php', 'backend/src/Helper/PanelHealth.php',
	'backend/src/Helper/PanelData.php', 'backend/src/Helper/PanelChart.php', 'backend/src/Helper/PanelIcons.php', 'media/css/panel.css', 'media/js/panel.js', 'media/js/panel-theme.js'] as $f) {
	t_ok(is_file("$c/$f"), "existe $f");
}
$x = simplexml_load_string($lee("$c/engage.xml"));
$sub = $x->xpath('//administration/submenu/menu');
t_ok(count($sub) === 4 && (string) $sub[0]['link'] === 'option=com_engage&view=controlpanel' && (string) $sub[1]['link'] === 'option=com_engage&view=comments' && (string) $sub[2]['link'] === 'option=com_engage&view=emailtemplates' && (string) $sub[3]['link'] === 'option=com_engage&view=settings', 'el manifiesto declara el submenu: panel, comentarios, plantillas de email y opciones (0.6.25)');
t_ok((string) $x->administration->menu === 'COM_ENGAGE' && !isset($x->administration->menu['link']), 'el elemento principal sigue siendo COM_ENGAGE sin enlace propio (index.php?option=com_engage, que abre el panel)');
$disp = $lf("$c/backend/src/Dispatcher/Dispatcher.php");
t_ok(str_contains($disp, "protected \$defaultController = 'controlpanel';"), 'el Dispatcher abre por defecto el panel de control');
t_ok(str_contains($disp, 'applyViewAndController') && str_contains($disp, "\$view       = \$this->mapView(\$view);"), 'las URL con view= explicito (comments, emailtemplates) siguen igual');
$assets = json_decode($lee("$c/media/joomla.asset.json"), true);
$by = [];
foreach ($assets['assets'] as $a) { $by[$a['type'] . ':' . $a['name']] = $a; }
t_ok(($by['style:com_engage.panel']['uri'] ?? '') === 'com_engage/panel.css', 'activo style com_engage.panel');
t_ok(($by['script:com_engage.panel.theme']['uri'] ?? '') === 'com_engage/panel-theme.js' && !isset($by['script:com_engage.panel.theme']['attributes']['defer']), 'activo panel-theme.js SIN defer (se ejecuta antes de pintar)');
t_ok(($by['script:com_engage.panel']['attributes']['defer'] ?? false) === true, 'panel.js con defer');
$vista = $lf("$c/backend/src/View/Controlpanel/HtmlView.php");
t_ok(str_contains($vista, "useStyle('com_engage.panel')") && str_contains($vista, "useScript('com_engage.panel.theme')"), 'la vista carga hoja y scripts');
t_ok(str_contains($vista, "authorise('core.edit.state', 'com_engage')") && str_contains($vista, "authorise('core.delete', 'com_engage')") && str_contains($vista, "authorise('core.admin', 'com_engage')"), 'la vista calcula permisos reales (edit.state, delete, admin/options)');

echo "B) Idiomas: todas las claves usadas existen en en-GB y es-ES\n";
$tpl = $lf("$c/backend/tmpl/controlpanel/default.php") . $vista;
preg_match_all("/'(COM_ENGAGE_[A-Z0-9_]+)'/", $tpl, $m);
$claves = array_unique($m[1]);
$ini = [];
foreach (['en-GB', 'es-ES'] as $l) {
	$ini[$l] = [];
	foreach (['com_engage.ini', 'com_engage.sys.ini'] as $f) { $ini[$l] += parse_ini_string($lee("$c/backend/language/$l/$f"), false, INI_SCANNER_RAW) ?: []; }
}
foreach (['en-GB', 'es-ES'] as $l) {
	$f = array_filter($claves, fn($k) => !isset($ini[$l][$k]) && !in_array($k, ['COM_ENGAGE', 'COM_ENGAGE_TITLE_COMMENTS', 'COM_ENGAGE_TITLE_EMAILTEMPLATES', 'COM_ENGAGE_PANEL_THEME_', 'COM_ENGAGE_PANEL_H_', 'COM_ENGAGE_PANEL_FIX_'], true));
	t_ok(!$f, "$l: las " . count($claves) . ' claves literales de plantilla y vista existen' . ($f ? ' (faltan: ' . implode(', ', $f) . ')' : ''));
}
// claves compuestas: semaforos (id x estado), temas, arreglos
$estados = [];
foreach ([[], ['php' => '7.4.0'], ['php' => '8.1.5'], ['php' => '8.3.0']] as $f) { foreach (H::evaluate($f)['items'] as $i) { $estados[$i['id'] . '|' . strtoupper((string) ($i['args']['state'] ?? 'ok'))] = 1; } }
foreach (['en-GB', 'es-ES'] as $l) {
	$faltan = [];
	foreach (['LIGHT', 'MID', 'DARK', 'AUTO'] as $t) { if (!isset($ini[$l]["COM_ENGAGE_PANEL_THEME_$t"])) { $faltan[] = $t; } }
	foreach (['PLUGIN', 'SETTINGS', 'UPDATESITES'] as $t) { if (!isset($ini[$l]["COM_ENGAGE_PANEL_FIX_$t"])) { $faltan[] = "FIX_$t"; } }
	foreach (['PLUGIN_CONTENT', 'PLUGIN_EMAIL', 'PLUGIN_CACHE', 'ANTISPAM', 'MODERATION', 'GRAVATAR', 'HTMLFILTER', 'PURIFIER_CACHE', 'PHP', 'DATABASE', 'UPDATESITE'] as $id) { if (!isset($ini[$l]["COM_ENGAGE_PANEL_H_{$id}_TITLE"])) { $faltan[] = "H_{$id}_TITLE"; } }
	// todos los estados posibles de cada semaforo (los que PanelHealth puede producir, recorridos con hechos variados)
	$todos = ['PLUGIN_CONTENT' => ['OK', 'MISSING', 'DISABLED'], 'PLUGIN_EMAIL' => ['OK', 'MISSING', 'DISABLED'], 'PLUGIN_CACHE' => ['NOCACHE', 'ACTIVE', 'DISABLED', 'MISSING'], 'ANTISPAM' => ['BOTH', 'CAPTCHA', 'AKISMET', 'NONE'],
		'MODERATION' => ['MODERATED', 'INSTANT_PROTECTED', 'INSTANT_OPEN'], 'GRAVATAR' => ['DISABLED', 'OFF', 'ALWAYS', 'JBCOOKIES_NOGROUP', 'ASK_JBCOOKIES', 'ASK_ENGAGE'], 'HTMLFILTER' => ['UNKNOWN', 'JOOMLA', 'DANGEROUS', 'IMG', 'OK'],
		'PURIFIER_CACHE' => ['UNUSED', 'WRITABLE', 'UNKNOWN', 'NOTWRITABLE'], 'PHP' => ['UNSUPPORTED', 'DATED', 'OK'], 'DATABASE' => ['UNKNOWN', 'OLD', 'OK'], 'UPDATESITE' => ['NONE', 'FORK', 'DISABLED', 'OTHER']];
	foreach ($todos as $id => $ests) { foreach ($ests as $e) { if (!isset($ini[$l]["COM_ENGAGE_PANEL_H_{$id}_{$e}"])) { $faltan[] = "H_{$id}_{$e}"; } } }
	t_ok(!$faltan, "$l: cadenas de temas, arreglos y los " . array_sum(array_map('count', $todos)) . ' estados de semaforo' . ($faltan ? ' (faltan: ' . implode(', ', $faltan) . ')' : ''));
}
$sys = ['en-GB' => parse_ini_string($lee("$c/backend/language/en-GB/com_engage.sys.ini"), false, INI_SCANNER_RAW), 'es-ES' => parse_ini_string($lee("$c/backend/language/es-ES/com_engage.sys.ini"), false, INI_SCANNER_RAW)];
foreach (['en-GB', 'es-ES'] as $l) {
	t_ok(isset($sys[$l]['COM_ENGAGE_MENU_CONTROLPANEL'], $sys[$l]['COM_ENGAGE_MENU_COMMENTS'], $sys[$l]['COM_ENGAGE_MENU_EMAILTEMPLATES']), "$l: las 3 cadenas del menu estan en com_engage.sys.ini (Joomla las lee de ahi)");
}
t_ok(!preg_match('/\b(puede|usted)\b.*\btu\b/i', implode(' ', $ini['es-ES'])), 'es-ES: trato de usted (la prueba tests/25 vigila el tuteo)');
t_ok(str_contains($ini['es-ES']['COM_ENGAGE_PANEL_DLG_DELETE_TEXT'], 'deshacer') && str_contains($ini['es-ES']['COM_ENGAGE_PANEL_H_ANTISPAM_NONE'], 'Elija'), 'es-ES: textos del dialogo y de los semaforos en usted (Elija, Quítela...)');

echo "C) Semaforos con hechos simulados\n";
$ok = [
	'php' => '8.3.6', 'db_version' => '10.11.14-MariaDB', 'purifier_cache' => true, 'can_edit_plugins' => true,
	'plugins' => ['content/engage' => ['exists' => true, 'enabled' => true, 'id' => 10], 'engage/email' => ['exists' => true, 'enabled' => true, 'id' => 11], 'system/engagecache' => ['exists' => true, 'enabled' => true, 'id' => 12],
		'system/cache' => ['exists' => true, 'enabled' => false, 'id' => 13], 'engage/gravatar' => ['exists' => true, 'enabled' => true, 'id' => 14], 'engage/akismet' => ['exists' => true, 'enabled' => true, 'id' => 15]],
	'params' => ['default_publish' => '0', 'filter_mode' => 'strict', 'htmlpurifier_configstring' => 'p,b,a[href],i'], 'gravatar' => ['mode' => 'ask', 'consent_source' => 'engage'], 'akismet' => ['key' => 'abc'],
	'captcha_effective' => 'recaptcha', 'captcha_enabled' => true, 'update_sites' => [['location' => 'https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml', 'enabled' => 1]],
];
$ev = fn(array $over = [], array $plug = []) => H::evaluate(array_replace_recursive($ok, $over, $plug ? ['plugins' => $plug] : []));
$lv = fn(array $r, string $id) => array_values(array_filter($r['items'], fn($i) => $i['id'] === $id))[0];
$r0 = $ev();
t_ok($r0['level'] === 'ok' && $r0['counts'] === ['ok' => 11, 'warn' => 0, 'bad' => 0], 'configuracion sana: 11 puntos verdes, nivel global ok');
t_ok(count(array_filter($r0['items'], fn($i) => $i['fix'] !== null)) === 0, 'sin avisos no hay botones de arreglo');
$r = $ev([], ['content/engage' => ['enabled' => false]]);
t_ok($lv($r, 'plugin_content')['level'] === 'bad' && $lv($r, 'plugin_content')['fix'] === ['kind' => 'plugin', 'id' => 10] && $r['level'] === 'bad', 'plugin de contenido desactivado: ROJO con enlace al plugin (id 10) y nivel global rojo');
$r = H::evaluate(array_replace($ok, ['can_edit_plugins' => false, 'plugins' => ['content/engage' => ['exists' => true, 'enabled' => false, 'id' => 10]] + $ok['plugins']]));
t_ok($lv($r, 'plugin_content')['level'] === 'bad' && $lv($r, 'plugin_content')['fix'] === null, 'sin permiso para editar plugins no se ofrece el enlace');
$r = H::evaluate(array_replace($ok, ['plugins' => ['content/engage' => ['exists' => false]] + $ok['plugins']]));
t_ok($lv($r, 'plugin_content')['args']['state'] === 'missing' && $lv($r, 'plugin_content')['level'] === 'bad', 'plugin de contenido no instalado: ROJO (missing)');
$r = $ev([], ['engage/email' => ['enabled' => false]]);
t_ok($lv($r, 'plugin_email')['level'] === 'warn', 'plugin de correo desactivado: AMBAR');
$r = $ev([], ['system/cache' => ['enabled' => true], 'system/engagecache' => ['enabled' => false]]);
t_ok($lv($r, 'plugin_cache')['level'] === 'warn' && $lv($r, 'plugin_cache')['fix']['id'] === 12, 'cache de pagina de Joomla activa y la de Engage apagada: AMBAR con enlace');
$r = $ev([], ['system/cache' => ['enabled' => true]]);
t_ok($lv($r, 'plugin_cache')['level'] === 'ok', 'ambas cachés activas: verde');
// antispam
$r = H::evaluate(array_replace($ok, ['captcha_effective' => '', 'akismet' => ['key' => '']]));
t_ok($lv($r, 'antispam')['level'] === 'warn' && $lv($r, 'antispam')['fix'] === ['kind' => 'settings', 'section' => 'antispam'], 'sin captcha ni clave de Akismet: AMBAR con enlace a Opciones > antispam');
$r = H::evaluate(array_replace($ok, ['captcha_effective' => 'recaptcha', 'captcha_enabled' => false, 'akismet' => ['key' => '']]));
t_ok($lv($r, 'antispam')['level'] === 'warn', 'captcha elegido pero con el plugin de captcha desactivado: AMBAR');
$r = H::evaluate(array_replace($ok, ['captcha_effective' => '', 'akismet' => ['key' => 'k']]));
t_ok($lv($r, 'antispam')['args']['state'] === 'akismet' && $lv($r, 'antispam')['level'] === 'ok', 'solo Akismet con clave: verde');
$r = H::evaluate(array_replace_recursive($ok, ['captcha_effective' => '', 'akismet' => ['key' => 'k'], 'plugins' => ['engage/akismet' => ['enabled' => false]]]));
t_ok($lv($r, 'antispam')['level'] === 'warn', 'Akismet con clave pero plugin desactivado: AMBAR');
// moderacion
$r = H::evaluate(array_replace_recursive($ok, ['params' => ['default_publish' => '1']]));
t_ok($lv($r, 'moderation')['level'] === 'ok' && $lv($r, 'moderation')['args']['state'] === 'instant_protected', 'publicacion inmediata CON antispam: verde');
$r = H::evaluate(array_replace($ok, ['captcha_effective' => '', 'akismet' => [], 'params' => ['default_publish' => '1'] + $ok['params']]));
t_ok($lv($r, 'moderation')['level'] === 'bad' && $lv($r, 'moderation')['fix']['section'] === 'moderation', 'publicacion inmediata SIN antispam: ROJO con enlace a Opciones > moderacion');
$r = H::evaluate(array_replace($ok, ['params' => $ok['params']]));
t_ok($lv($r, 'moderation')['level'] === 'ok' && $lv($r, 'moderation')['args']['state'] === 'moderated', 'moderacion previa: verde');
// gravatar
foreach ([['always', 'warn'], ['off', 'ok'], ['ask', 'ok']] as [$mode, $niv]) {
	$r = H::evaluate(array_replace($ok, ['gravatar' => ['mode' => $mode, 'consent_source' => 'engage']]));
	t_ok($lv($r, 'gravatar')['level'] === $niv, "Gravatar modo $mode: $niv");
}
$r = H::evaluate(array_replace($ok, ['gravatar' => ['mode' => 'ask', 'consent_source' => 'jbcookies', 'jbcookies_group' => '']]));
t_ok($lv($r, 'gravatar')['level'] === 'warn' && $lv($r, 'gravatar')['args']['state'] === 'jbcookies_nogroup', 'consentimiento JBCookies sin grupo: AMBAR');
$r = H::evaluate(array_replace($ok, ['gravatar' => ['mode' => 'ask', 'consent_source' => 'jbcookies', 'jbcookies_group' => 'marketing']]));
t_ok($lv($r, 'gravatar')['level'] === 'ok' && $lv($r, 'gravatar')['args']['state'] === 'ask_jbcookies', 'consentimiento JBCookies con grupo: verde');
$r = H::evaluate(array_replace_recursive($ok, ['plugins' => ['engage/gravatar' => ['enabled' => false]], 'gravatar' => ['mode' => 'always']]));
t_ok($lv($r, 'gravatar')['level'] === 'ok', 'plugin de Gravatar desactivado (aunque diga always): verde, no se envia nada');
// filtro HTML
$wl = fn(string $mode, string $w, $j = 0) => $lv(H::evaluate(array_replace($ok, ['params' => ['default_publish' => '0', 'filter_mode' => $mode, 'htmlpurifier_configstring' => $w, 'htmlpurifier_config_joomla' => $j]])), 'htmlfilter');
t_ok($wl('strict', 'p,b,a[href],img[src],img[width]')['level'] === 'warn' && $wl('strict', 'p,b,a[href],img[src],img[width]')['args']['state'] === 'img', 'lista con img[src]: AMBAR (el visitante contacta servidores externos)');
t_ok($wl('strict', 'p,IMG[SRC|alt]')['level'] === 'warn', 'img[SRC|alt] en mayusculas tambien se detecta');
t_ok($wl('strict', 'p,img[width],img[height]')['level'] === 'ok', 'img solo con width/height (sin src): no avisa');
t_ok($wl('htmlpurifier', 'p,b,a[href]')['level'] === 'ok' && $wl('strict', "p\n,b ,a[href]")['level'] === 'ok', 'lista sin imagenes: verde (modos htmlpurifier y strict, separadores raros)');
foreach (['script', 'iframe', 'object', 'embed', 'style', 'form'] as $t) { t_ok($wl('strict', "p,$t")['level'] === 'bad', "etiqueta peligrosa $t en la lista: ROJO"); }
t_ok($wl('strict', 'p,iframe', 1)['level'] === 'ok', 'con "usar el filtro de Joomla" la lista propia no cuenta');
t_ok($wl('joomla', 'p')['level'] === 'warn' && $wl('nuevo', 'p')['level'] === 'warn', 'modo joomla o desconocido: AMBAR');
// cache de HTML Purifier
t_ok($lv(H::evaluate(array_replace($ok, ['purifier_cache' => false])), 'purifier_cache')['level'] === 'warn' && $lv(H::evaluate(array_replace($ok, ['purifier_cache' => null])), 'purifier_cache')['args']['state'] === 'unknown', 'carpeta de cache no escribible: AMBAR; sin poder comprobar: AMBAR');
t_ok($lv(H::evaluate(array_replace($ok, ['purifier_cache' => false, 'params' => ['filter_mode' => 'joomla'] + $ok['params']])), 'purifier_cache')['level'] === 'ok', 'modo joomla: la carpeta no hace falta (verde)');
// versiones
foreach ([['7.4.33', 'bad'], ['8.0.30', 'bad'], ['8.1.0', 'warn'], ['8.1.27', 'warn'], ['8.2.0', 'ok'], ['8.4.1', 'ok'], ['', 'bad']] as [$v, $niv]) { t_ok($lv(H::evaluate(array_replace($ok, ['php' => $v])), 'php')['level'] === $niv, "PHP '$v': $niv"); }
foreach ([['10.11.14-MariaDB-0ubuntu0.24.04', 'ok', 'MariaDB'], ['5.5.5-10.3.39-MariaDB', 'bad', 'MariaDB'], ['10.4.0-MariaDB', 'ok', 'MariaDB'], ['8.0.12', 'bad', 'MySQL'], ['8.0.13', 'ok', 'MySQL'], ['8.4.2 mysql', 'ok', 'MySQL'], ['5.7.44', 'bad', 'MySQL'], ['raro', 'warn', 'MySQL'], ['', 'warn', 'MySQL']] as [$v, $niv, $n]) {
	$i = $lv(H::evaluate(array_replace($ok, ['db_version' => $v])), 'database');
	t_ok($i['level'] === $niv && $i['args']['name'] === $n, "base de datos '$v': $niv ($n)");
}
t_ok(H::parseDbVersion('5.5.5-10.4.0-MariaDB') === ['version' => '10.4.0', 'maria' => true] && H::parseDbVersion('8.0')['version'] === '8.0.0', 'parseDbVersion: prefijo 5.5.5- de MariaDB y version corta');
// sitio de actualizacion
foreach ([[[], 'warn', 'none'], [[['location' => 'https://cdn.akeeba.com/updates/pkgengage.xml', 'enabled' => 1]], 'warn', 'other'], [[['location' => 'https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml', 'enabled' => 0]], 'warn', 'disabled'],
	[[['location' => 'https://cdn.akeeba.com/x', 'enabled' => 1], ['location' => 'https://raw.githubusercontent.com/Chaska/Engage/main/u.xml', 'enabled' => 1]], 'ok', 'fork']] as [$s, $niv, $est]) {
	$i = $lv(H::evaluate(array_replace($ok, ['update_sites' => $s])), 'updatesite');
	t_ok($i['level'] === $niv && $i['args']['state'] === $est && ($niv === 'ok' ? $i['fix'] === null : $i['fix'] === ['kind' => 'updatesites']), "sitio de actualizacion $est: $niv");
}
$r = H::evaluate([]);
t_ok(count($r['items']) === 11 && in_array($r['level'], ['bad'], true), 'sin ningun dato (todo desconocido) no falla: 11 puntos y nivel rojo, sin excepciones');
t_ok(H::whitelistAllows('p,b,A[href]', 'a') && !H::whitelistAllows('p,span', 'img') && H::whitelistAllows('img', 'img'), 'whitelistAllows: nombres con y sin atributos, mayusculas');

echo "D) Serie y geometria del grafico\n";
$hoy = new DateTimeImmutable('2026-10-04 15:30:00', new DateTimeZone('Europe/Madrid'));
$s = PanelChart::buildSeries([['d' => '2026-10-04', 'n' => '3'], ['d' => '2026-09-05', 'n' => 2], ['d' => '2026-09-04', 'n' => 9], ['d' => 'malo', 'n' => 5], ['d' => '2026-10-01', 'n' => -4]], $hoy, 30);
t_ok(count($s) === 30 && $s[0]['date'] === '2026-09-05' && $s[29]['date'] === '2026-10-04', 'serie de 30 dias terminada hoy (UTC), 05-sep a 04-oct');
t_ok($s[29]['n'] === 3 && $s[0]['n'] === 2 && array_sum(array_column($s, 'n')) === 5, 'rellena ceros; ignora filas fuera de rango, fechas malformadas y negativos');
t_ok(count(PanelChart::buildSeries([], $hoy, 0)) === 1 && count(PanelChart::buildSeries([], $hoy, 9999)) === 366, 'limites del numero de dias (1..366)');
$g = PanelChart::geometry($s);
t_ok($g['max'] === 4 && count($g['bars']) === 30 && $g['total'] === 5 && $g['peak'] === 3, 'escala minima 4, 30 barras, total y pico');
t_ok(PanelChart::scaleMax([['n' => 5]]) === 8 && PanelChart::scaleMax([['n' => 17]]) === 20 && PanelChart::scaleMax([]) === 4, 'escala: multiplos de 4');
$cero = array_filter($g['bars'], fn($b) => $b['n'] === 0);
t_ok(count(array_filter($cero, fn($b) => $b['path'] !== '')) === 0 && count(array_filter($g['bars'], fn($b) => $b['n'] > 0 && str_starts_with($b['path'], 'M'))) === 2, 'los dias sin comentarios no dibujan barra; los demas, un trazado');
t_ok($g['grid'][0]['v'] === 0 && $g['grid'][2]['v'] === 4 && $g['grid'][0]['y'] === 150.0 && $g['grid'][2]['y'] < $g['grid'][0]['y'], 'rejilla: base, mitad y maximo');
foreach ($g['bars'] as $b) { if ($b['path'] !== '' && preg_match_all('/-?\d+(\.\d+)?/', $b['path'], $mm) && max($mm[0]) > 600.01) { t_ok(false, 'barra fuera del lienzo'); } }
t_ok(true, 'todas las barras caen dentro del lienzo de 600 de ancho');

echo "E) Iconos y seguridad estatica de plantilla, JS y CSS\n";
$plant = $lf("$c/backend/tmpl/controlpanel/default.php");
preg_match_all("/I::icon\('([a-z]+)'/", $plant, $mm);
preg_match_all("/=> '([a-z]+)'/", $plant, $m2);
$usados = array_unique(array_merge($mm[1], $m2[1], ['ok', 'warn', 'bad']));
$nombres = PanelIcons::names();
t_ok(!array_diff(array_filter($usados, fn($u) => in_array($u, ['chat', 'mail', 'sliders', 'lock', 'info', 'chart', 'shield', 'heart', 'flag', 'trash', 'check', 'chevron', 'sun', 'dim', 'moon', 'half', 'eye', 'code', 'log', 'book', 'external', 'ok', 'warn', 'bad'], true)), $nombres), 'todos los iconos que usa la plantilla existen en la hoja de simbolos');
t_ok(str_contains(PanelIcons::sprite(), 'aria-hidden="true"') && substr_count(PanelIcons::sprite(), '<symbol') === count($nombres) && PanelIcons::icon('no-existe') === '' && !str_contains(PanelIcons::icon('chat', '"><script>'), '<script>'), 'iconos: sprite oculto, icono desconocido = vacio, clase escapada');
preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $plant, $ex);
$sinEscapar = [];
foreach ($ex[1] as $e) {
	if (preg_match('/^(\$e\(|\$url\(|I::icon\(|PanelUi::themeSwitcher\(\)|\(int\)|\$quickToken$)/', $e)) { continue; }
	$sinEscapar[] = $e;
}
t_ok(!$sinEscapar, 'toda salida <?= de la plantilla va escapada ($e, $url), es un icono, un entero o el token' . ($sinEscapar ? ' -> ' . implode(' | ', array_slice($sinEscapar, 0, 3)) : ''));
t_ok(substr_count($plant, '<?=') === count($ex[1]) && !str_contains($plant, 'echo $') , 'no hay echo sin escapar');
t_ok(!preg_match('/\sstyle\s*=/i', $plant) && !preg_match('/<script/i', $plant) && !preg_match('/\son[a-z]+\s*=/i', $plant), 'plantilla sin style="", sin <script> y sin handlers on* (CSP estricta)');
preg_match_all('#https?://[^\s"\'<>)]+#', $plant, $urls);
t_ok(!$urls[0], 'la plantilla no contiene URL literales (las del enlace van en PanelData, con rel noopener)');
t_ok(substr_count($plant, 'target="_blank" rel="noopener noreferrer"') === 4, 'los 4 enlaces externos llevan rel="noopener noreferrer" (son enlaces del usuario, ninguna carga automatica)');
$js = $lee("$c/media/js/panel.js") . $lee("$c/media/js/panel-theme.js");
$js = preg_replace('#/\*.*?\*/#s', '', $js);
$js = preg_replace('#(?<!:)//[^\n]*#', '', $js);
t_ok(!preg_match('/\b(confirm|alert|prompt)\s*\(/', $js) && !str_contains($js, 'innerHTML') && !str_contains($js, 'eval(') && !str_contains($js, 'document.write'), 'JS sin confirm()/alert(), sin innerHTML, eval ni document.write');
t_ok(!preg_match('/\b(fetch|XMLHttpRequest|sendBeacon|WebSocket|EventSource|import\()/', $js) && !preg_match('#https?://#', preg_replace('#http://www\.w3\.org/2000/svg#', '', $js)), 'JS sin ninguna peticion de red ni URL externa (solo el espacio de nombres SVG)');
t_ok(substr_count($js, 'try') >= 4 && str_contains($js, "w.localStorage.getItem(KEY)") && str_contains($js, 'catch (e)'), 'localStorage siempre dentro de try/catch');
$css = $lee("$c/media/css/panel.css");
t_ok(!preg_match('/@import|url\(|https?:\/\/|@font-face/', preg_replace('#/\*.*?\*/#s', '', $css)), 'CSS sin @import, url(), fuentes ni direcciones externas');
t_ok(substr_count($css, '{') === substr_count($css, '}'), 'CSS con llaves equilibradas');
$fuera = [];
foreach (preg_split('/}/', preg_replace('#/\*.*?\*/#s', '', $css)) as $bloque) {
	if (!str_contains($bloque, '{')) { continue; }
	[$sel] = explode('{', $bloque, 2);
	foreach (explode(',', $sel) as $s1) {
		$s1 = trim(preg_replace('/@(media|container)[^{]*$/', '', trim($s1)));
		if ($s1 === '' || str_starts_with($s1, '@') || str_starts_with($s1, 'from') || str_starts_with($s1, 'to') || str_starts_with($s1, '.eg-sprite') || preg_match('/^(\d+%)$/', $s1)) { continue; }
		if (!str_contains($s1, '.eg-admin') && !str_contains($s1, '.eg-sprite')) { $fuera[] = $s1; }
	}
}
t_ok(!$fuera, 'TODOS los selectores del CSS estan bajo .eg-admin (no rompe Atum ni otras pantallas)' . ($fuera ? ' -> ' . implode(' | ', array_slice($fuera, 0, 3)) : ''));
t_ok(str_contains($css, 'prefers-reduced-motion: reduce') && str_contains($css, ':focus-visible') && str_contains($css, 'forced-colors') && str_contains($css, 'prefers-color-scheme: dark') && str_contains($css, '@container eg'), 'CSS con prefers-reduced-motion, :focus-visible, forced-colors, modo oscuro del dispositivo y adaptacion al ancho del propio panel');
t_ok(preg_match_all('/--eg-admin-[a-z0-9-]+:/', $css) > 120 && !preg_match('/--eg-(?!admin|from|to|d-from|d-to)[a-z-]+:/', preg_replace('#/\*.*?\*/#s', '', $css)) , 'sistema de diseno con variables --eg-admin-* (las auxiliares locales: --eg-from/--eg-to/--eg-d-*)');

echo "F) Excerpt y datos hostiles\n";
t_ok(PanelData::excerpt("<p>Hola <b>mundo</b></p>\0<script>alert(1)</script>") === 'Hola mundo alert(1)', 'excerpt: sin etiquetas ni NUL (el escapado final lo hace la plantilla)');
t_ok(mb_strlen(PanelData::excerpt(str_repeat('á', 100000), 140)) === 140 && str_ends_with(PanelData::excerpt(str_repeat('á ', 5000)), '…'), 'excerpt: cadena enorme recortada a 140 caracteres con elipsis, multibyte intacto');
t_ok(PanelData::excerpt('&lt;img src=x onerror=1&gt;') === '<img src=x onerror=1>' , 'excerpt devuelve texto plano: las entidades se decodifican y por eso la plantilla DEBE escapar (lo hace)');
$datos = $lee("$c/backend/src/Helper/PanelData.php");
t_ok(substr_count($datos, '->bind(') >= 14 && !preg_match('/\$_(GET|POST|REQUEST)|input->/', $datos), 'PanelData: consultas con parametros enlazados y sin leer la peticion');
t_ok(!preg_match('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER)\b/', $datos), 'PanelData es solo de lectura (ni INSERT, UPDATE, DELETE ni DDL)');
t_ok(!preg_match('/curl|file_get_contents|fsockopen|stream_context|http_request|HttpFactory/i', $datos . $lee("$c/backend/src/View/Controlpanel/HtmlView.php") . $lee("$c/backend/src/Helper/PanelHealth.php")), 'ni la vista ni los ayudantes del panel abren conexiones de red');

echo "G) Acciones rapidas: tareas existentes con retorno al panel; version; finales de linea\n";
$cc = $lee("$c/backend/src/Controller/CommentsController.php");
t_ok(str_contains($cc, "protected function onAfterPublish()\r\n") && str_contains($cc, "protected function onAfterDelete(): void\r\n") && substr_count($cc, 'applyReturnUrl()') === 2, 'CommentsController: hooks onAfterPublish/onAfterDelete PROTEGIDOS y con la firma del controlador del frontend (que los redefine)');
$fe = $lee("$c/frontend/src/Controller/CommentsController.php");
t_ok(preg_match('/protected function onAfterPublish\(\)/', $fe) && preg_match('/protected function onAfterDelete\(\): void/', $fe), 'el controlador del frontend sigue declarando las mismas firmas (si no, error fatal al publicar: fallo real de la primera version)');
t_ok(str_contains($plant, 'task') && str_contains($vista, "'task' => 'comments.publish'") && str_contains($vista, "'task' => 'comments.possiblespam'") && str_contains($vista, "'task' => 'comments.delete'"), 'las 3 acciones usan las tareas existentes comments.publish / possiblespam / delete');
t_ok(str_contains($plant, "HTMLHelper::_('form.token')") && str_contains($plant, 'name="return"'), 'el formulario lleva el token CSRF de Joomla y la direccion de retorno (interna)');
preg_match('/## (\d+\.\d+\.\d+)/', $lee("$root/CHANGELOG.md"), $mv);
t_ok(($mv[1] ?? '') === PanelData::FORK_VERSION, 'PanelData::FORK_VERSION (' . PanelData::FORK_VERSION . ') coincide con la primera entrada del CHANGELOG (' . ($mv[1] ?? '?') . ')');
foreach (['backend/src/Dispatcher/Dispatcher.php', 'backend/src/Controller/CommentsController.php', 'engage.xml'] as $f) {
	$raw = $lee("$c/$f");
	t_ok(substr_count($raw, "\r\n") === substr_count($raw, "\n") && substr_count($raw, "\n") > 5, "$f conserva finales de linea CRLF en TODAS las lineas");
}
foreach (['media/css/panel.css', 'media/js/panel.js', 'backend/tmpl/controlpanel/default.php', 'backend/language/es-ES/com_engage.ini'] as $f) { t_ok(!str_contains($lee("$c/$f"), "\r"), "$f (nuevo/LF) sin retornos de carro"); }

echo "H) Contraste WCAG de los tres disenos (calculado sobre las variables de panel.css)\n";
$lum = function (string $h): float { $h = ltrim($h, '#'); $c = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; foreach ($c as &$v) { $v /= 255; $v = $v <= .03928 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4; } return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2]; };
$cr = fn(string $a, string $b): float => (max($lum($a), $lum($b)) + .05) / (min($lum($a), $lum($b)) + .05);
$bloques = [];
preg_match_all('/(?:^|\n)((?:html[^{]*)?\.eg-admin[^{]*)\{([^}]*)\}/', $css, $bm, PREG_SET_ORDER);
foreach ($bm as $b) {
	if (!str_contains($b[2], '--eg-admin-bg:')) { continue; }
	preg_match_all('/(--eg-admin-[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/', $b[2], $vv, PREG_SET_ORDER);
	$v = []; foreach ($vv as $p) { $v[$p[1]] = $p[2]; }
	$n = str_contains($b[1], 'data-eg-theme="mid"') ? 'mid' : (str_contains($b[1], 'data-eg-theme="dark"') ? 'dark' : 'light');
	$bloques[$n] = $v;
}
preg_match('/@media \(prefers-color-scheme: dark\) \{\s*html:not\(\[data-eg-theme\]\) \.eg-admin \{([^}]*)\}/', $css, $am);
preg_match_all('/(--eg-admin-[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/', $am[1] ?? '', $vv, PREG_SET_ORDER);
$auto = []; foreach ($vv as $p) { $auto[$p[1]] = $p[2]; }
t_ok(array_keys($bloques) === ['light', 'mid', 'dark'] || count($bloques) === 3, 'se leen los 3 bloques de variables (claro, medio, oscuro)');
t_ok($auto === $bloques['dark'] || array_diff_assoc($bloques['dark'], $auto) === [], 'el bloque "automatico" oscuro tiene los mismos valores que el diseno oscuro');
$peor = [];
foreach ($bloques as $n => $v) {
	$pares = [
		['text', 'bg', 4.5], ['text', 'surface', 4.5], ['text', 'surface-2', 4.5], ['text-2', 'bg', 4.5], ['text-2', 'surface', 4.5], ['text-2', 'surface-2', 4.5],
		['accent-text', 'surface', 4.5], ['accent-text', 'surface-2', 4.5], ['accent-text', 'bg', 4.5], ['accent-text', 'accent-soft', 4.5], ['on-accent', 'accent', 4.5],
		['ok-text', 'ok-bg', 4.5], ['warn-text', 'warn-bg', 4.5], ['bad-text', 'bad-bg', 4.5], ['ok-text', 'surface', 4.5], ['warn-text', 'surface', 4.5], ['bad-text', 'surface', 4.5],
		['ok-text', 'surface-2', 3], ['text', 'accent-soft', 4.5],
		['chart', 'surface', 3], ['line', 'surface', 3], ['line', 'bg', 3], ['focus', 'surface', 3], ['focus', 'bg', 3], ['focus', 'surface-2', 3], ['bg', 'text', 4.5],
	];
	foreach ($pares as [$a, $b2, $min]) {
		$ra = $cr($v["--eg-admin-$a"], $v["--eg-admin-$b2"]);
		$peor[$n] = min($peor[$n] ?? 99, $ra / $min * 1.0);
		t_ok($ra >= $min, sprintf('%s: %s sobre %s = %.2f:1 (minimo %s)', $n, $a, $b2, $ra, $min));
	}
	foreach (['blue', 'violet', 'green', 'orange', 'slate', 'red'] as $col) {
		foreach (['from', 'to'] as $e) { $ra = $cr('#ffffff', $v["--eg-admin-$col-$e"]); t_ok($ra >= 3, sprintf('%s: glifo blanco sobre degradado %s (%s) = %.2f:1 (minimo 3)', $n, $col, $e, $ra)); }
	}
	foreach (['green' => 'ok', 'orange' => 'warn', 'red' => 'bad'] as $col => $_) { $ra = $cr('#ffffff', $v["--eg-admin-$col-to"]); t_ok($ra >= 4.5, sprintf('%s: inicial blanca del avatar %s sobre su color = %.2f:1 (minimo 4,5)', $n, $col, $ra)); }
}
t_fin();
