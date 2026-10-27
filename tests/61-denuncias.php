<?php
/**
 * 0.9.14: denunciar comentarios. Reglas puras (ReportRules con datos simulados), y comprobaciones estaticas del controlador, la tabla, las opciones, la cola
 * de administracion y el aviso agrupado. Lo que necesita Joomla real esta en tests/joomla-live/lib/denuncias-0914.php y denuncias-0914-navegador.js.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$c/backend/src/Helper/ReportRules.php";
use Akeeba\Component\Engage\Administrator\Helper\ReportRules as R;

echo "A) Limites de las opciones (rangos cerrados)\n";
foreach (R::LIMITS as $k => [$min, $max, $def]) {
	t_ok(R::limit($k, null) === $def && R::limit($k, '') === $def && R::limit($k, 'abc') === $def && R::limit($k, (string) ($max + 1)) === $def && R::limit($k, (string) ($min - 1)) === $def && R::limit($k, '-1') === $def, "$k: vacio, basura y fuera de rango = defecto $def");
	t_ok(R::limit($k, (string) $min) === $min && R::limit($k, (string) $max) === $max && R::limit($k, $max) === $max && R::limit($k, [$max]) === $def && R::limit($k, "1e2") === $def && R::limit($k, '5.5') === $def, "$k: los extremos $min y $max valen; arrays y decimales no");
}
t_ok(R::LIMITS['reports_per_hour'][2] === 5 && R::LIMITS['reports_per_day'][2] === 20 && R::LIMITS['reports_min_account'][2] === 24, 'defectos: 5 por hora, 20 por dia, cuenta de 24 horas');

echo "B) Motivo y nota\n";
foreach ([['1', 1], ['2', 2], ['3', 3], ['4', 4], [3, 3], ['0', 0], ['5', 0], ['9', 0], ['', 0], ['spam', 0], ['1;DROP', 0], [null, 0], [[], 0], ['01', 0], ['-1', 0]] as [$in, $exp]) { t_ok(R::reason($in) === $exp, 'motivo ' . json_encode($in) . " -> $exp"); }
t_ok(R::cleanNote('<b>hola</b> <script>alert(1)</script>mundo') === 'hola alert(1)mundo', 'la nota se queda sin etiquetas');
t_ok(R::cleanNote("a\0b\x07c\r\nd\te") === 'a b c d e', 'la nota se queda sin caracteres de control');
t_ok(mb_strlen(R::cleanNote(str_repeat('á', 500))) === 200 && R::cleanNote(str_repeat('x', 5000)) === str_repeat('x', 200), 'la nota tiene como maximo 200 caracteres (tambien con UTF-8)');
t_ok(R::cleanNote("\xff\xfe") === '' && R::cleanNote(['x']) === '' && R::cleanNote(null) === '' && R::cleanNote('   ') === '', 'UTF-8 invalido, no cadenas y vacio: nota vacia');

echo "C) decide: abuso\n";
$ok = ['enabled' => true, 'uid' => 7, 'accountAgeHours' => 100, 'commentFound' => true, 'commentVisible' => true, 'ownComment' => false, 'reason' => 1, 'already' => false, 'perHour' => 0, 'perDay' => 0, 'open' => 0, 'limits' => []];
$d = fn(array $o = []) => R::decide(array_replace($ok, $o));
t_ok($d() === 'ok', 'caso normal: ok');
foreach ([['enabled' => false, 'disabled'], ['uid' => 0, 'login'], ['uid' => -1, 'login'], ['accountAgeHours' => 23, 'young'], ['accountAgeHours' => 0, 'young'], ['commentFound' => false, 'notfound'], ['commentVisible' => false, 'notfound'], ['ownComment' => true, 'own'], ['reason' => 0, 'reason'], ['reason' => 9, 'reason'], ['reason' => 'x', 'reason'], ['already' => true, 'already'], ['perHour' => 5, 'hour'], ['perDay' => 20, 'day'], ['open' => 500, 'full']] as $t) {
	$k = array_key_first($t); t_ok($d([$k => $t[$k]]) === $t[0], "$k=" . json_encode($t[$k]) . ' -> ' . $t[0]);
}
t_ok($d(['perHour' => 4, 'perDay' => 19, 'open' => 499, 'accountAgeHours' => 24]) === 'ok', 'justo por debajo de los limites y con 24 horas: ok');
t_ok($d(['limits' => ['reports_per_hour' => '2'], 'perHour' => 2]) === 'hour' && $d(['limits' => ['reports_per_hour' => '2'], 'perHour' => 1]) === 'ok', 'el limite por hora configurado se respeta');
t_ok($d(['limits' => ['reports_per_hour' => '9999'], 'perHour' => 5]) === 'hour', 'un limite guardado fuera del maximo (9999) cae al defecto: no se puede abrir el grifo');
t_ok($d(['limits' => ['reports_min_account' => '0'], 'accountAgeHours' => 0]) === 'ok' && $d(['limits' => ['reports_min_account' => '720'], 'accountAgeHours' => 719]) === 'young', 'antiguedad minima configurable (0 a 720 horas)');
t_ok($d(['limits' => ['reports_open_max' => '50'], 'open' => 50]) === 'full' && $d(['limits' => ['reports_open_max' => '10'], 'open' => 499]) === 'ok', 'tope global configurable; un valor bajo el minimo (10) vuelve al defecto 500');
t_ok($d(['commentFound' => false]) === $d(['commentVisible' => false]) && $d(['commentFound' => false]) === 'notfound', 'inexistente y no visible dan la MISMA respuesta (sin oraculo de IDs)');
t_ok($d(['uid' => 0, 'enabled' => false]) === 'disabled' && $d(['uid' => 0]) === 'login', 'orden de las comprobaciones: desactivado, sesion, antiguedad...');

echo "D) Codigo: controlador de denuncias\n";
$ct = $lee("$c/frontend/src/Controller/ReportController.php");
t_ok(str_contains($ct, "strtolower((string) \$task) !== 'submit'") && str_contains($ct, "throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403)"), 'una sola tarea (submit); todo lo demas 403');
$sub = substr($ct, strpos($ct, 'public function submit'), 2200);
t_ok(strpos($sub, 'HTTP_SEC_FETCH_SITE') < strpos($sub, "'POST'") && strpos($sub, "'POST'") < strpos($sub, "Session::checkToken('post')") && strpos($sub, "Session::checkToken('post')") < strpos($sub, '$uid <= 0') && strpos($sub, '$uid <= 0') < strpos($sub, '$this->process('), 'orden: cross-site, POST, token CSRF, sesion (invitado = 403), y solo entonces el proceso');
t_ok(str_contains($ct, 'ReportRules::decide(') && str_contains($ct, 'chainVisibility') && str_contains($ct, 'ReactionsController::canView') && str_contains($ct, "(int) \$comment['enabled'] === 1"), 'el comentario debe estar publicado, con su cadena publicada y el contenido visible para quien denuncia');
t_ok(!preg_match('/UPDATE|publish\(|delete\(|->enabled\s*=/i', $ct), 'una denuncia NUNCA cambia, oculta ni borra el comentario (el controlador solo escribe en la tabla de denuncias)');
$st = $lee("$c/backend/src/Helper/ReportStore.php");
t_ok(substr_count($st, '->bind(') >= 15 && !preg_match('/\$_(GET|POST|REQUEST)|\bintval\(\$_/', $st), 'ReportStore: consultas ligadas');
t_ok(str_contains($st, 'whereIn(') && str_contains($st, 'INNER') && str_contains($st, 'max(1, min(200, $limit))'), 'la cola esta acotada (200 filas como mucho)');

echo "E) Codigo: administracion\n";
$ac = $lee("$c/backend/src/Controller/ReportsController.php");
t_ok(str_contains($ac, "['main', 'display', 'dismiss', 'handle', 'unpublish']") && str_contains($ac, "authorise('core.edit.state', 'com_engage')") && str_contains($ac, "Session::checkToken('post')") && str_contains($ac, "'POST'"), 'acciones de la cola: lista cerrada de tareas, core.edit.state en el servidor, POST y token CSRF');
t_ok(strpos($ac, 'core.edit.state') < strpos($ac, 'checkToken'), 'primero el permiso, luego el token');
$vw = $lee("$c/backend/tmpl/reports/default.php");
t_ok(substr_count($vw, '$e(') > 30 && !preg_match('/<\?=\s*\$r\[/', $vw) && !str_contains($vw, 'style='), 'plantilla de la cola: todo escapado, sin estilos en linea');
t_ok(str_contains($vw, "HTMLHelper::_('form.token')") && str_contains($vw, 'method="post"'), 'las acciones son formularios POST con token');
t_ok(str_contains($lee("$c/backend/tmpl/controlpanel/default.php"), 'view=reports') , 'el panel de control enlaza a la cola con el numero de abiertas');

echo "F) Esquema y opciones\n";
$sql = $lee("$c/backend/sql/install.mysql.utf8.sql"); $upd = $lee("$c/backend/sql/updates/mysql/3.4.18-20261027.sql");
foreach ([$sql, $upd] as $i => $x) {
	t_ok(str_contains($x, 'CREATE TABLE IF NOT EXISTS `#__engage_reports`') && str_contains($x, 'UNIQUE KEY `#__engage_reports_unique` (`comment_id`, `user_id`)') && str_contains($x, '`note`        VARCHAR(200)') && str_contains($x, 'KEY `#__engage_reports_user` (`user_id`, `created`)'), ($i ? 'actualizacion' : 'instalacion') . ': tabla de denuncias con una denuncia por persona y comentario');
}
t_ok(str_contains($lee("$c/backend/sql/uninstall.mysql.utf8.sql"), 'DROP TABLE IF EXISTS `#__engage_reports`'), 'la desinstalacion la borra');
require_once "$c/backend/src/Helper/SettingsSchema.php";
use Akeeba\Component\Engage\Administrator\Helper\SettingsSchema as S;
$cfg = $lee("$c/backend/config.xml"); $def = S::parse($cfg, 'com_engage')['fields'];
foreach (['reports_enabled', 'reports_per_hour', 'reports_per_day', 'reports_min_account', 'reports_open_max', 'reports_notify'] as $k) {
	t_ok(S::requiresAdmin('com_engage', $k) && isset($def[$k]) && str_contains($cfg, "AdminOnlyFilter::$k"), "$k: solo core.admin (filtro y esquema)");
}
foreach (['reports_per_hour' => [1, 20, 5], 'reports_per_day' => [1, 100, 20], 'reports_min_account' => [0, 720, 24], 'reports_open_max' => [50, 5000, 500]] as $k => [$mn, $mx, $df]) {
	$ex1 = $ex2 = false; try { S::validate($def[$k], (string) ($mx + 1)); } catch (\InvalidArgumentException $e) { $ex1 = true; } try { S::validate($def[$k], (string) ($mn - 1)); } catch (\InvalidArgumentException $e) { $ex2 = true; }
	t_ok(S::validate($def[$k], (string) $mx) === (string) $mx && S::validate($def[$k], (string) $mn) === (string) $mn && $ex1 && ($ex2 || $mn === 0) && (string) $def[$k]['default'] === (string) $df, "$k: rango $mn-$mx cerrado en el formulario, defecto $df");
}
t_ok((string) $def['reports_enabled']['default'] === '1' && (string) $def['reports_notify']['default'] === '0', 'activadas por defecto; aviso por correo apagado por defecto (opcional)');
foreach (['es-ES', 'en-GB'] as $l) {
	$sys = parse_ini_string($lee("$c/backend/language/$l/com_engage.sys.ini"), false, INI_SCANNER_RAW); $bk = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW); $fr = parse_ini_string($lee("$c/frontend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW);
	$f = [];
	foreach (['REPORTS_ENABLED', 'REPORTS_PER_HOUR', 'REPORTS_PER_DAY', 'REPORTS_MIN_ACCOUNT', 'REPORTS_OPEN_MAX', 'REPORTS_NOTIFY'] as $k) { foreach (['LABEL', 'DESC'] as $z) { if (empty($sys["COM_ENGAGE_CONFIG_{$k}_$z"])) { $f[] = "$k $z"; } } }
	foreach (['BTN', 'HELP', 'SEND', 'MSG_SENT', 'NOTE_LABEL', 'REASON_LABEL', 'REASON_CHOOSE', 'REASON_SPAM', 'REASON_OFFENSIVE', 'REASON_PERSONAL', 'REASON_OTHER', 'ERR_GENERIC', 'ERR_DISABLED', 'ERR_YOUNG', 'ERR_OWN', 'ERR_REASON', 'ERR_ALREADY', 'ERR_LIMIT', 'ERR_FULL'] as $k) { if (empty($fr["COM_ENGAGE_REPORT_$k"])) { $f[] = "front $k"; } }
	foreach (['TITLE_REPORTS', 'REPORTS_HEAD', 'REPORTS_INTRO', 'REPORTS_COL_COMMENT', 'REPORTS_STATUS_OPEN', 'REPORTS_BTN_DISMISS', 'REPORTS_BTN_UNPUBLISH', 'REPORTS_EMPTY', 'REPORTS_TILE', 'REPORTS_DIGEST_SUBJECT', 'REPORTS_DIGEST_BODY', 'REPORT_REASON_SPAM'] as $k) { if (empty($bk["COM_ENGAGE_$k"])) { $f[] = "admin $k"; } }
	t_ok(!$f, "$l: todas las cadenas" . ($f ? ' (faltan: ' . implode(', ', $f) . ')' : ''));
}
t_ok(str_contains($lee("$c/frontend/language/es-ES/com_engage.ini"), 'no se oculta ni se borra automáticamente'), 'es-ES: dice que el comentario no se oculta solo');

echo "G) Aviso agrupado y limpieza\n";
$dg = $lee("$c/backend/src/Helper/ReportDigest.php");
t_ok(str_contains($dg, 'pendingDigest()') && str_contains($dg, 'markNotified(') && substr_count($dg, '->send()') === 1 && str_contains($dg, 'MAX_RECIPIENTS = 50'), 'un correo por moderador con el TOTAL de denuncias nuevas (no uno por denuncia), con tope de destinatarios');
t_ok(!preg_match('/->body|->note|username|comment_name/i', $dg), 'el aviso no lleva texto del comentario ni datos de quien denuncia');
t_ok(str_contains($lee($root . '/src/plugins/content/engage/src/Extension/Engage.php'), "ReportDigest::run((int) \$cParams->get('reports_notify', 0) === 1)"), 'se dispara desde el mismo reloj de 8 horas');
t_ok(str_contains($lee($root . '/src/plugins/user/engage/src/Extension/Engage.php'), 'ReportStore($this->getDatabase()))->deleteForUser('), 'al borrar la cuenta se borran sus denuncias');
t_ok(str_contains($lee("$c/frontend/src/View/Comments/HtmlView.php"), 'public function reportHtml(object $comment)') && str_contains($lee("$c/frontend/src/View/Comments/HtmlView.php"), "!\$u->guest && (int) \$u->id > 0"), 'el boton solo se imprime para usuarios con sesion');
t_fin();
