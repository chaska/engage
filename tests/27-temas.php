<?php
/**
 * 0.6.23: temas visuales (opcion theme). Comprobaciones estaticas de config.xml, idiomas (en-GB y es-ES), activos, Dispatcher,
 * vista, plantilla y hojas CSS. Que cada tema se lea sobre fondo claro y oscuro (contraste WCAG medido en Chromium) se prueba
 * contra un Joomla real en tests/joomla-live/10-temas.sh.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", file_get_contents("$root/$f"));
$temas = ['modern' => 'moderno.css', 'minimal' => 'minimalista.css', 'dark' => 'oscuro.css'];

echo "A) config.xml\n";
$x = simplexml_load_string($lee('backend/config.xml'));
t_ok($x !== false, 'config.xml es XML valido');
$f = $x->xpath('//fieldset[@name="appearance"]/field[@name="theme"]');
t_ok(count($f) === 1, 'hay un campo "theme" en la pestana "appearance"');
t_ok((string) $f[0]['type'] === 'list' && (string) $f[0]['default'] === 'classic' && (string) $f[0]['validate'] === 'options', 'tipo lista, validate=options y valor por defecto "classic" (sin cambios para instalaciones existentes)');
$valores = array_map(fn($o) => (string) $o['value'], $f[0]->xpath('option'));
t_ok($valores === ['classic', 'modern', 'minimal', 'dark'], 'valores: classic, modern, minimal, dark');
t_ok(count($x->xpath('//field[@name="theme"]')) === 1, 'el nombre "theme" no esta duplicado');

echo "B) Idiomas (en-GB y es-ES)\n";
$claves = ['COM_ENGAGE_CONFIG_APPEARANCE_FIELDSET_LABEL', 'COM_ENGAGE_CONFIG_APPEARANCE_FIELDSET_DESC', 'COM_ENGAGE_CONFIG_THEME_LABEL', 'COM_ENGAGE_CONFIG_THEME_DESC',
	'COM_ENGAGE_CONFIG_THEME_CLASSIC', 'COM_ENGAGE_CONFIG_THEME_MODERN', 'COM_ENGAGE_CONFIG_THEME_MINIMAL', 'COM_ENGAGE_CONFIG_THEME_DARK'];
foreach (['en-GB', 'es-ES'] as $l) {
	$ini = parse_ini_string($lee("backend/language/$l/com_engage.sys.ini"), false, INI_SCANNER_RAW);
	t_ok(!array_diff($claves, array_keys($ini)), "$l: estan las 8 cadenas de apariencia");
	$vacias = array_filter($claves, fn($k) => trim((string) ($ini[$k] ?? ''), '"') === '');
	t_ok(!$vacias, "$l: ninguna cadena vacia");
}
$es = parse_ini_string($lee('backend/language/es-ES/com_engage.sys.ini'), false, INI_SCANNER_RAW);
$en = parse_ini_string($lee('backend/language/en-GB/com_engage.sys.ini'), false, INI_SCANNER_RAW);
t_ok(!array_filter($claves, fn($k) => $es[$k] === $en[$k]), 'es-ES distinta de en-GB en las 8 cadenas');
t_ok(!preg_match('/\b(puedes|tu|tus|elige|vacia|consulta tu)\b/iu', implode(' ', array_map(fn($k) => $es[$k], $claves))), 'es-ES de trato de usted (sin formas de tuteo)');

echo "C) Activos, Dispatcher, vista y plantilla\n";
$assets = json_decode($lee('media/joomla.asset.json'), true);
$mapa = array_column($assets['assets'], 'uri', 'name');
$disp = $lee('frontend/src/Dispatcher/Dispatcher.php');
foreach ($temas as $t => $arch) {
	t_ok(($mapa["com_engage.theme.$t"] ?? '') === "com_engage/themes/$arch", "activo com_engage.theme.$t -> themes/$arch");
	t_ok(is_file("$root/media/css/themes/$arch"), "existe media/css/themes/$arch");
	t_ok(preg_match("/'$t'\\s*=> 'style:com_engage\\.theme\\.$t'/", $disp) === 1, "el Dispatcher asocia $t con su hoja");
}
t_ok(!isset($mapa['com_engage.theme.classic']) && !str_contains($disp, 'theme.classic') && !str_contains($disp, "'classic'  =>"), 'classic no tiene hoja ni activo: no carga CSS adicional');
t_ok(preg_match("/if \(isset\(\\\$themeAssets\[\\\$theme\]\)\)\s*\{\s*\\\$this->commonMediaKeys\[\] = \\\$themeAssets\[\\\$theme\];\s*\}/", $disp) === 1, 'solo se anade UNA hoja y solo si el valor esta en la lista blanca (cualquier otro valor = classic)');
t_ok(strpos($disp, "'style:com_engage.replies'") < strpos($disp, '$themeAssets = ['), 'el tema se carga despues de replies.css (y de comments.css)');
$vista = $lee('frontend/src/View/Comments/HtmlView.php');
t_ok(preg_match("/in_array\(\\\$theme, \['classic', 'modern', 'minimal', 'dark'\], true\)/", $vista) === 1 && str_contains($vista, "public \$theme = 'classic';"), 'la vista valida el tema con lista blanca estricta (valor por defecto classic)');
$def = $lee('frontend/tmpl/comments/default.php');
t_ok(str_contains($def, "' akengage-theme--' . \$this->escape(\$this->theme)") && str_contains($def, "\$this->theme !== 'classic'"), 'la clase akengage-theme--<valor> sale escapada y solo con un tema distinto de classic (HTML de classic identico)');
t_ok(!preg_match('/style="[^"]*theme/i', $def), 'sin estilos en linea');

echo "D) Hojas CSS\n";
$todas = [];
foreach ($temas as $t => $arch) {
	$css = $lee("media/css/themes/$arch");
	$todas[$t] = $css;
	$P = "section.akengage-outer-container.akengage-theme--$t";
	t_ok(substr_count($css, '{') === substr_count($css, '}'), "$arch: llaves equilibradas");
	t_ok(!str_contains($css, '@import') && !preg_match('#url\(\s*[\'"]?(https?:|//)#', $css), "$arch: sin @import ni recursos externos");
	t_ok(str_contains($css, ':root {') && substr_count($css, '--eg-') > 60, "$arch: variables --eg-* en :root");
	// Todo selector fuera de :root/@media debe ir acotado al contenedor del tema
	$sin = preg_replace(['#/\*.*?\*/#s', '#@media[^{]*\{#', '#:root\s*\{[^}]*\}#s'], '', $css);
	$sin = preg_replace('/:(where|is)\(([^)]*)\)/', ':$1()', $sin);
	preg_match_all('#([^{}]+)\{#', $sin, $m);
	$malos = [];
	foreach ($m[1] as $sel) { foreach (explode(',', $sel) as $s) { $s = trim($s); if ($s !== '' && !str_starts_with($s, $P)) { $malos[] = $s; } } }
	t_ok(!$malos, "$arch: todos los selectores empiezan por $P" . ($malos ? ' (' . $malos[0] . ')' : ''));
	// Fondo Y color de texto explicitos en tarjeta, cabecera, cuerpo y botones
	foreach (['article[class*="akengage-comment--"]' => ['background-color', 'color'], '.akengage-comment-properties' => ['background-color', 'color'], '.akengage-comment-body {' => ['color'], '.akengage-comment-replyto {' => ['background-color', 'color'], '#akengageCommentForm .form-control' => ['background-color', 'color']] as $sel => $props) {
		$pos = strpos($css, "$P $sel") !== false ? strpos($css, "$P $sel") : strpos($css, $sel);
		$bloque = substr($css, $pos, strpos($css, '}', $pos) - $pos);
		foreach ($props as $p) { t_ok(preg_match('/(^|[\s;{])' . $p . ':/', $bloque) === 1, "$arch: $sel fija $p"); }
	}
	t_ok(!preg_match('/reply_indent|akengage-reply-indent--[a-z]+\s*\{/', preg_replace('#/\*.*?\*/#s', '', $css)) && !preg_match('/\n\s*padding-left:/', preg_replace('#/\*.*?\*/#s', '', $css)), "$arch: no toca la sangria de las respuestas (reply_indent)");
	t_ok(str_contains($css, '--akengage-reply-bg: var(--eg-fondo-respuesta)'), "$arch: da color opaco al fondo suave de reply_style");
	t_ok(str_contains($css, 'prefers-reduced-motion: reduce') && str_contains($css, 'max-width: 575.98px'), "$arch: movimiento reducido y pantalla estrecha");
	t_ok(str_contains($css, '.akengage-comment--spam') && str_contains($css, '.akengage-comment--unpublished'), "$arch: estados spam y sin publicar diferenciados");
}
t_ok(count(array_unique(array_map('md5', $todas))) === 3, 'las tres hojas son distintas');
t_ok(str_contains($todas['dark'], '--eg-panel-fondo: #0f172a'), 'oscuro: el panel fija fondo oscuro (y su texto claro) sobre cualquier plantilla');
$base = strtolower($lee('media/css/replies.css') . $lee('media/css/comments.css'));
t_ok(!str_contains($base, 'akengage-theme--'), 'replies.css y comments.css no cambian (blindados)');

echo "E) Documentacion\n";
$docs = file_get_contents(dirname(__DIR__) . '/docs/TEMAS.md');
$vars = [];
foreach ($todas as $css) { preg_match_all('/^\s*(--eg-[a-z-]+):/m', $css, $m); $vars = array_merge($vars, $m[1]); }
$vars = array_unique($vars);
$sinDoc = array_filter($vars, fn($v) => !str_contains($docs, "`$v`"));
t_ok(!$sinDoc, 'docs/TEMAS.md documenta las ' . count($vars) . ' variables --eg-*' . ($sinDoc ? ' (faltan: ' . implode(', ', array_slice($sinDoc, 0, 3)) . ')' : ''));
t_fin();
