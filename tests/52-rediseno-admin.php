<?php
/**
 * 0.9.3: rediseno del backend (Comentarios, Plantillas de email, Edicion) con admin-lists.css: sin estilos en linea, todo escapado,
 * URL sin doble escape (Route::_ dentro de $e() debe ir con xhtml=false), claves es-ES/en-GB, activo registrado, version del paquete.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));

echo "A) Plantillas del rediseno\n";
foreach (['comments/default', 'comments/emptystate', 'emailtemplates/default', 'comment/edit'] as $v) {
	$s = $lee("$root/backend/tmpl/$v.php");
	t_ok(!preg_match('/\sstyle\s*=/i', $s), "$v: sin atributo style");
	t_ok(!preg_match('~\$e\(Route::_\((?:(?!, false\)).)*\)\)\s*\?>~', $s), "$v: Route::_ dentro de \$e() sin doble escape (xhtml=false)");
	t_ok(!str_contains($s, 'gravatar') && !str_contains($s, 'innerHTML'), "$v: sin Gravatar ni innerHTML");
}
$d = $lee("$root/backend/tmpl/comments/default.php");
t_ok(str_contains($d, 'eg-admin') && str_contains($d, 'data-label') && str_contains($d, 'eg-badge'), 'Comentarios: contenedor .eg-admin, etiquetas de tarjeta y estados con texto');
t_ok(!preg_match('~<\?=\s*\$(?:item|meta|author)[^;]*\?>~', preg_replace('~<\?=\s*\$e\(.*?\)\s*\?>|<\?=\s*\(int\).*?\?>~s', '', $d) ?? ''), 'Comentarios: ninguna salida de datos sin escapar ni convertir a entero');

echo "B) CSS y activo\n";
$css = $lee("$root/media/css/admin-lists.css");
t_ok(str_contains($css, '@container eg (max-width: 780px)') && str_contains($css, 'flex-wrap: wrap'), 'CSS: tarjetas en movil y paginacion que envuelve');
t_ok(!preg_match('~url\(\s*[\'"]?https?:|@import~i', $css), 'CSS: sin recursos externos');
t_ok(preg_match('/"com_engage\.lists"/', $lee("$root/media/joomla.asset.json")) === 1, 'activo com_engage.lists registrado');

echo "C) Idiomas\n";
foreach (['es-ES', 'en-GB'] as $lg) {
	$ini = $lee("$root/backend/language/$lg/com_engage.ini");
	foreach (['COM_ENGAGE_EMAILTEMPLATES_LBL_LIST_HEAD', 'COM_ENGAGE_COMMENTS_LBL_REACTIONS', 'COM_ENGAGE_EMAILTEMPLATES_BTN_PREVIEW'] as $k) { t_ok(str_contains($ini, "$k=\""), "$lg: $k"); }
}
t_fin();
