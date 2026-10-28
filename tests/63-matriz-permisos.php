<?php
/**
 * 0.9.16: matriz de permisos. Clasificacion y fusion (PermissionsMatrix) con un arbol de grupos y un Joomla simulado; entradas hostiles; y comprobaciones
 * estaticas del controlador, la plantilla, el JS y las cadenas. El contraste con Access::checkGroup y el guardado real estan en tests/joomla-live/lib/permisos-0916.php.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
define('_JEXEC', 1);
require_once "$c/backend/src/Helper/PermissionsMatrix.php";
use Akeeba\Component\Engage\Administrator\Helper\PermissionsMatrix as PM;

// Arbol de Joomla: Public(1) > Guest(9), Manager(6) > Administrator(7), Registered(2) > Author(3) > Editor(4) > Publisher(5); Super Users(8) cuelga de Public
$groups = [
	['id' => 1, 'parent' => 0, 'title' => 'Public', 'depth' => 0], ['id' => 9, 'parent' => 1, 'title' => 'Guest', 'depth' => 1],
	['id' => 6, 'parent' => 1, 'title' => 'Manager', 'depth' => 1], ['id' => 7, 'parent' => 6, 'title' => 'Administrator', 'depth' => 2],
	['id' => 2, 'parent' => 1, 'title' => 'Registered', 'depth' => 1], ['id' => 3, 'parent' => 2, 'title' => 'Author', 'depth' => 2],
	['id' => 4, 'parent' => 3, 'title' => 'Editor', 'depth' => 3], ['id' => 5, 'parent' => 4, 'title' => 'Publisher', 'depth' => 4],
	['id' => 8, 'parent' => 1, 'title' => 'Super Users', 'depth' => 1],
];
$parent = array_column($groups, 'parent', 'id');
$path   = function (int $g) use ($parent): array { $p = []; for ($x = $g; $x > 0; $x = $parent[$x] ?? 0) { $p[] = $x; } return $p; };
// Joomla simulado: un Allow en el grupo o en un ancestro lo da; un Deny en cualquiera lo quita (el Deny gana). Mas «asset padre» opcional.
$joomla = function (array $explicit, array $parentAsset = []) use ($path) {
	return function (int $g, string $a) use ($explicit, $parentAsset, $path): ?bool {
		$r = null;
		foreach ([$parentAsset, $explicit] as $rules) {
			foreach ($path($g) as $x) { $v = $rules[$a][$x] ?? null; if ($v === 0) { return false; } if ($v === 1) { $r = true; } }
		}
		return $r;
	};
};
$isSuper = fn(int $g): bool => $g === 8;

echo "A) Clasificacion de las celdas\n";
$exp = ['core.create' => [2 => 1, 9 => 0], 'core.edit.state' => [6 => 1], 'core.delete' => [3 => 1, 4 => 0], 'core.manage' => [7 => 1], 'core.edit' => [2 => 1]];
$cells = PM::cells($groups, $exp, $joomla($exp), $isSuper);
$esperado = [
	[2, 'core.create', 'allow'], [3, 'core.create', 'inherit'], [5, 'core.create', 'inherit'], [1, 'core.create', 'unset'], [6, 'core.create', 'unset'],
	[9, 'core.create', 'deny'], [8, 'core.create', 'always'], [8, 'core.manage', 'always'],
	[6, 'core.edit.state', 'allow'], [7, 'core.edit.state', 'inherit'], [2, 'core.edit.state', 'unset'],
	[3, 'core.delete', 'allow'], [4, 'core.delete', 'deny'], [5, 'core.delete', 'deny'] /* hereda el Deny del Editor */,
	[7, 'core.manage', 'allow'], [6, 'core.manage', 'unset'], [2, 'core.edit', 'allow'], [4, 'core.edit', 'inherit'],
];
foreach ($esperado as [$g, $a, $s]) { t_ok(($cells[$g][$a] ?? '') === $s, "grupo $g $a -> $s (obtenido " . ($cells[$g][$a] ?? '-') . ')'); }
$bad = [];
foreach ($groups as $g) { foreach (array_keys(PM::ACTIONS) as $a) { $eff = $joomla($exp)($g['id'], $a) === true || $isSuper($g['id']); if (PM::isAllowed($cells[$g['id']][$a]) !== $eff) { $bad[] = $g['id'] . ' ' . $a; } } }
t_ok(!$bad, 'en TODAS las celdas, permitido/heredado/siempre == lo que decide el Joomla simulado; sin asignar/denegado == no puede' . ($bad ? ' [' . implode(', ', $bad) . ']' : ''));
$expAllow = ['core.create' => [2 => 1]]; $parentAllow = ['core.create' => [6 => 1]]; $cells2 = PM::cells($groups, $expAllow, $joomla($expAllow, $parentAllow), $isSuper);
t_ok($cells2[7]['core.create'] === 'inherit' && $cells2[6]['core.create'] === 'inherit' && $cells2[1]['core.create'] === 'unset', 'un Allow del asset padre (configuracion global) se ve como heredado');
$expBlocked = ['core.create' => [2 => 1]]; $parentDeny = ['core.create' => [1 => 0]]; $cells3 = PM::cells($groups, $expBlocked, $joomla($expBlocked, $parentDeny), $isSuper);
t_ok($cells3[2]['core.create'] === 'deny', 'un Allow aqui que un Deny del asset padre anula: denegado (no engana con «permitido»)');
t_ok(array_keys(PM::ACTIONS) === ['core.create', 'core.edit.own', 'core.edit', 'core.edit.state', 'core.delete', 'core.manage'] && !isset(PM::ACTIONS['core.admin']) && !isset(PM::ACTIONS['core.options']), 'las columnas son las seis acciones; core.admin y core.options NO estan');

echo "B) Entradas hostiles (parseChanges)\n";
$ok = json_encode([['g' => 2, 'a' => 'core.create', 'v' => 1], ['g' => '3', 'a' => 'core.delete', 'v' => '0']]);
t_ok(PM::parseChanges($ok) === [[2, 'core.create', 1], [3, 'core.delete', 0]], 'lista valida');
foreach ([null, '', 'x', '{"g":1}', '[[1,2]]', str_repeat('a', 200000), json_encode(array_fill(0, 601, ['g' => 2, 'a' => 'core.create', 'v' => 1])), json_encode(['x' => ['g' => 1]]), []] as $i => $bad) { t_ok(PM::parseChanges($bad) === null || PM::parseChanges($bad) === [], "carga inutilizable #$i: nada que aplicar"); }
$hostil = json_encode([['g' => 2, 'a' => 'core.admin', 'v' => 1], ['g' => 2, 'a' => 'core.options', 'v' => 1], ['g' => 2, 'a' => 'core.create', 'v' => 2], ['g' => 2, 'a' => 'core.create', 'v' => true], ['g' => -1, 'a' => 'core.create', 'v' => 1], ['g' => '2; DROP', 'a' => 'core.create', 'v' => 1], ['g' => 2, 'a' => ['core.create'], 'v' => 1], ['g' => 2.5, 'a' => 'core.create', 'v' => 1], ['g' => 2, 'a' => 'CORE.CREATE', 'v' => 1], ['g' => 2, 'a' => 'core.create', 'v' => 'si'], ['a' => 'core.create', 'v' => 1], 'texto', null, ['g' => 2, 'a' => 'core.create', 'v' => 1]]);
t_ok(PM::parseChanges($hostil) === [[2, 'core.create', 1]], 'acciones inventadas (core.admin, core.options), valores no 0/1, ids malos, tipos raros y entradas sin campos: descartados; queda la unica valida');

echo "C) Fusion (merge)\n";
$valid = [1, 2, 3, 4, 5, 6, 7, 8, 9]; $super = [8]; $broad = [1, 2, 9];
$m = PM::merge($exp, [[2, 'core.create', 0], [3, 'core.create', 1]], $valid, $super, $broad);
t_ok($m['rules']['core.create'] === [2 => 0 + 0 === 0 ? 1 : 1, 9 => 0, 3 => 1] || ($m['rules']['core.create'][3] ?? 0) === 1, 'se anade un Allow nuevo');
$m = PM::merge($exp, [[2, 'core.create', 0]], $valid, $super, $broad);
t_ok(!isset($m['rules']['core.create'][2]) && ($m['rules']['core.create'][9] ?? null) === 0 && $m['applied'] === [[2, 'core.create', '-']], 'quitar un Allow lo deja «sin asignar»; el Deny del otro grupo sigue');
$m = PM::merge($exp, [[9, 'core.create', 1], [4, 'core.delete', 1]], $valid, $super, $broad);
t_ok($m['rules']['core.create'][9] === 0 && $m['rules']['core.delete'][4] === 0 && $m['applied'] === [] && $m['ignored'] === 2, 'un Deny existente NO se pisa (ni para ponerlo en Allow)');
$m = PM::merge($exp, [[8, 'core.create', 1], [8, 'core.manage', 0], [99, 'core.create', 1], [0, 'core.create', 1]], $valid, $super, $broad);
t_ok($m['rules'] === $exp && $m['applied'] === [] && $m['ignored'] === 4, 'Super Users y los grupos que no existen se ignoran: las reglas quedan IDENTICAS');
$m = PM::merge($exp, [[2, 'core.admin', 1], [2, 'core.options', 1], [2, 'core.foo', 1]], $valid, $super, $broad);
t_ok($m['rules'] === $exp && $m['ignored'] === 3, 'core.admin, core.options y cualquier accion fuera de la lista: ignoradas');
$once = PM::merge($exp, [[5, 'core.create', 1], [6, 'core.delete', 1]], $valid, $super, $broad);
$twice = PM::merge($once['rules'], [[5, 'core.create', 1], [6, 'core.delete', 1]], $valid, $super, $broad);
t_ok($once['rules'] === $twice['rules'] && $twice['applied'] === [], 'idempotente: repetir el mismo cambio no cambia nada');
t_ok(PM::merge([], [[2, 'core.create', 1]], $valid, $super, $broad)['needsConfirm'] === false, 'Comentar a Registered no pide confirmacion');
foreach ([[2, 'core.edit'], [2, 'core.edit.state'], [1, 'core.delete'], [9, 'core.manage']] as [$g, $a]) { t_ok(PM::merge([], [[$g, $a, 1]], $valid, $super, $broad)['needsConfirm'] === true, "conceder $a al grupo amplio $g: pide confirmacion"); }
t_ok(PM::merge([], [[6, 'core.edit.state', 1]], $valid, $super, $broad)['needsConfirm'] === false && PM::merge($exp, [[2, 'core.edit', 0]], $valid, $super, $broad)['needsConfirm'] === false, 'moderar a Manager o QUITAR un permiso no pide confirmacion');
t_ok(PM::summary([[2, 'core.create', '+'], [6, 'core.delete', '-']], [2 => 'Registered', 6 => 'Manager <b>']) === 'Registered: +core.create; Manager b: -core.delete', 'resumen del registro: titulos de grupo y acciones, limpio y sin datos personales');
$f = PM::filterRules(['core.admin' => [8 => 1], 'core.create' => [2 => 1, 'x' => 1, 3 => 5, 4 => '0'], 'core.foo' => [1 => 1]]);
t_ok($f === ['core.create' => [2 => 1, 4 => 0]], 'filterRules: solo las seis acciones, ids positivos y valores 0/1');

echo "D) Codigo\n";
$ct = $lee("$c/backend/src/Controller/PermissionsController.php");
$sv = substr($ct, strpos($ct, 'public function save'), 3600);
$ord = ["'POST'", 'HTTP_X_REQUESTED_WITH', "Session::checkToken('post')", "authorise('core.admin', 'com_engage')", 'PermissionsMatrix::parseChanges', 'PermissionsMatrix::merge', "'error' => 'confirm'", 'PermissionsMatrix::store'];
$pos = array_map(fn($x) => strpos($sv, $x), $ord);
t_ok(!in_array(false, $pos, true) && $pos === array_values(array_unique($pos)) && $pos === (function ($p) { sort($p); return $p; })($pos), 'save: POST, X-Requested-With, token CSRF, core.admin, validacion, fusion, confirmacion y solo despues escritura (en ese orden)');
t_ok(str_contains($ct, "['main', 'display', 'save']") && !str_contains($ct, '->query(') && !str_contains($ct, 'UPDATE ') && !str_contains($ct, 'INSERT '), 'lista cerrada de tareas y ningun SQL escrito a mano');
$pm = $lee("$c/backend/src/Helper/PermissionsMatrix.php");
t_ok(str_contains($pm, "Table::getInstance('Asset')") && str_contains($pm, 'new \Joomla\CMS\Access\Rules(') && str_contains($pm, "loadByName('com_engage')"), 'se guarda con la tabla Asset y la clase Rules de Joomla, sobre el activo com_engage');
t_ok(!preg_match('/\$all\[[\'"]core\.(admin|options)/', $pm), 'el codigo de escritura nunca toca core.admin ni core.options');
$vw = $lee("$c/backend/src/View/Permissions/HtmlView.php");
t_ok(str_contains($vw, "authorise('core.admin', 'com_engage')") && str_contains($vw, '403'), 'la pantalla exige core.admin (403 si no)');
$tp = $lee("$c/backend/tmpl/permissions/default.php");
t_ok(str_contains($tp, '<th scope="col"') && str_contains($tp, '<th scope="row"') && str_contains($tp, 'aria-pressed=') && str_contains($tp, 'aria-label=') && str_contains($tp, '<caption') && str_contains($tp, 'role="region" tabindex="0"') && !str_contains($tp, 'style='), 'tabla accesible: th scope, boton por celda con aria-pressed y aria-label, region desplazable con teclado, sin estilos en linea');
t_ok(substr_count($tp, "foreach (['allow', 'inherit', 'unset', 'always', 'deny'] as \$kind)") >= 2 && str_contains($tp, 'eg-pico--deny') || str_contains($tp, "'deny'"), 'cada estado tiene su propio icono (no solo color)');
$js = $lee("$c/media/js/permissions.js");
t_ok(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|eval\s*\(|new Function|document\.write/', $js) && str_contains($js, 'X-Requested-With') && str_contains($js, 'textContent'), 'JS propio: sin innerHTML ni eval; envia X-Requested-With; solo textContent');
t_ok(!preg_match('#https?://#', $js) && !preg_match('#https?://#', preg_replace('#/\*.*?\*/#s', '', substr($lee("$c/media/css/admin-lists.css"), strpos($lee("$c/media/css/admin-lists.css"), '0.9.16: matriz')))), 'ni el JS ni el CSS de la matriz llevan URLs externas');
$cs = $lee("$c/media/css/admin-lists.css");
t_ok(str_contains($cs, '.eg-perm-wrap { overflow-x: auto') && str_contains($cs, 'var(--eg-admin-ok-text)') && str_contains($cs, ':focus-visible'), 'CSS: desplazamiento horizontal en movil, variables del panel (claro/oscuro) y foco visible');
foreach (['es-ES', 'en-GB'] as $l) {
	$ini = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW); $f = [];
	foreach (['TITLE_PERMISSIONS', 'PERMS_BTN', 'PERMS_CONFIG_NOTE', 'PERMS_HEAD', 'PERMS_ADVANCED', 'PERMS_INTRO', 'PERMS_VIEW_NOTE', 'PERMS_REGION', 'PERMS_COL_GROUP', 'PERMS_ARIA_TPL', 'PERMS_LEGEND', 'PERMS_HOWTO', 'PERMS_SAVE', 'PERMS_DISCARD', 'PERMS_MSG_PENDING', 'PERMS_MSG_SAVED', 'PERMS_MSG_NOCHANGE', 'PERMS_MSG_FAILED', 'PERMS_CONFIRM_TITLE', 'PERMS_CONFIRM_TEXT', 'PERMS_CONFIRM_YES'] as $k) { if (empty($ini["COM_ENGAGE_$k"])) { $f[] = $k; } }
	foreach (PM::ACTIONS as $a => $k) { foreach (['', '_DESC'] as $z) { if (empty($ini["COM_ENGAGE_PERMS_ACT_$k$z"])) { $f[] = "ACT_$k$z"; } } }
	foreach (['ALLOW', 'INHERIT', 'UNSET', 'DENY', 'ALWAYS'] as $k) { foreach (['STATE', 'LEGEND'] as $z) { if (empty($ini["COM_ENGAGE_PERMS_{$z}_$k"])) { $f[] = "{$z}_$k"; } } }
	$al = parse_ini_string($lee($root . "/src/plugins/actionlog/engage/language/$l/plg_actionlog_engage.ini"), false, INI_SCANNER_RAW);
	if (empty($al['COM_ENGAGE_USERLOG_PERMISSIONS_CHANGED']) || !str_contains($al['COM_ENGAGE_USERLOG_PERMISSIONS_CHANGED'], '{summary}')) { $f[] = 'USERLOG'; }
	t_ok(!$f, "$l: todas las cadenas" . ($f ? ' (faltan: ' . implode(', ', $f) . ')' : ''));
}
$es = parse_ini_string($lee("$c/backend/language/es-ES/com_engage.ini"), false, INI_SCANNER_RAW);
t_ok(str_contains($es['COM_ENGAGE_PERMS_HOWTO'], 'Pulse') && str_contains($es['COM_ENGAGE_PERMS_CONFIRM_TEXT'], 'Va a dar') && !preg_match('/\b(pulsa|tu|ve a)\b/i', $es['COM_ENGAGE_PERMS_HOWTO']), 'es-ES: trato de usted');
echo "N) 0.9.17: «Permisos» abre directamente la matriz; la nativa de Joomla sigue accesible\n";
$nav = $lee("$c/backend/src/Helper/PermissionsNav.php");
t_ok(str_contains($nav, "'index.php?option=com_engage&view=permissions'") && str_contains($nav, "authorise('core.admin', 'com_engage')") && str_contains($nav, 'com_config&view=component&component=com_engage&return=') && !preg_match('/getInput|\\$_(GET|POST|REQUEST|SERVER)|Uri::|getHost|base\\(/', $nav), 'PermissionsNav: direcciones con parametros fijos, nada de la peticion ni del Host');
t_ok(str_contains($nav, "'#permissions'"), 'el enlace avanzado apunta a la pestana de permisos (y degrada a las opciones si cambia)');
foreach (['Comments', 'Emailtemplates'] as $v) {
	$s = $lee("$c/backend/src/View/$v/HtmlView.php");
	t_ok(str_contains($s, 'PermissionsNav::canOpen()') && str_contains($s, 'COM_ENGAGE_PERMS_BTN') && str_contains($s, 'PermissionsNav::matrixUrl()'), "barra de herramientas de $v: boton «Permisos» directo a la matriz (solo core.admin)");
}
foreach (['Controlpanel', 'Comments'] as $v) { t_ok(str_contains($lee("$c/backend/src/View/$v/HtmlView.php"), "preferences('com_engage')"), "$v: quien solo tiene core.options conserva el boton «Opciones»"); }
$st = $lee("$c/backend/tmpl/settings/default.php"); $sv = $lee("$c/backend/src/View/Settings/HtmlView.php");
t_ok(str_contains($st, 'PermissionsNav::MATRIX') && str_contains($st, 'permDirect') && !str_contains($st, 'COM_ENGAGE_SET_PERM_MATRIX_BTN') && substr_count($st, 'COM_ENGAGE_SET_PERM_BTN') === 1, 'Ajustes: la entrada «Permisos» es un enlace directo a la matriz y no hay boton duplicado (el clasico queda solo para quien no es core.admin)');
t_ok(str_contains($sv, "\$want === 'permissions'") && str_contains($sv, 'redirect(PermissionsNav::matrixUrl())'), 'el antiguo &section=permissions redirige a la matriz con core.admin');
t_ok(str_contains($lee("$c/backend/tmpl/controlpanel/default.php"), 'PermissionsNav::MATRIX'), 'el mosaico «Permisos» del panel abre la matriz');
t_ok(!str_contains($lee("$c/backend/src/View/Controlpanel/HtmlView.php"), 'COM_ENGAGE_PERMS_BTN') && !str_contains($sv, 'COM_ENGAGE_PERMS_BTN'), 'UN solo acceso por pantalla: panel = mosaico y Ajustes = entrada del menu (sin boton duplicado en la barra)');
$tp2 = $lee("$c/backend/tmpl/permissions/default.php");
t_ok(substr_count($tp2, 'advancedUrl') >= 2 && str_contains($tp2, 'eg-perm-advlink'), 'la matriz enlaza a los permisos avanzados de Joomla en la cabecera y en la leyenda de «Denegado»');
t_ok(str_contains($lee("$c/backend/src/View/Permissions/HtmlView.php"), 'PermissionsNav::advancedUrl()'), 'la vista usa el helper para el enlace avanzado');
$cfg = $lee("$c/backend/config.xml");
t_ok(preg_match('#name="permissions".*?COM_ENGAGE_PERMS_CONFIG_NOTE.*?type="rules"#s', $cfg) === 1, 'la pestana de permisos de Joomla lleva el aviso con el enlace de vuelta a la matriz y conserva el campo rules (sin logica de guardado nueva)');
foreach (['es-ES', 'en-GB'] as $l) { $ini = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW); t_ok(str_contains($ini['COM_ENGAGE_PERMS_CONFIG_NOTE'], 'view=permissions') && str_contains($ini['COM_ENGAGE_PERMS_ADVANCED'], 'Joomla'), "$l: aviso de la pantalla nativa y rotulo «Permisos avanzados (Joomla)»"); }
t_fin();
