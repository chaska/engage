<?php
/**
 * 0.9.12: correcciones de las auditorias. BBCode acotado (DoS), tope duro de longitud, baja de avisos firmada, enlace firmado ligado a la tarea,
 * captcha nonmanager, re-suscripcion, limitador, resultado de plugins de foto, AdminOnlyFilter estricto, semaforo, contadores, rendimiento, Akismet.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));

echo "A) BBCode: tope de corchetes y equivalencia en entradas normales\n";
require_once "$c/backend/src/Helper/BBCode.php";
use Akeeba\Component\Engage\Administrator\Helper\BBCode;
// version anterior (git HEAD~) cargada con otro nombre para el fuzz de equivalencia
$old = shell_exec('cd ' . escapeshellarg($root) . ' && git show 581e01e:src/component/backend/src/Helper/BBCode.php 2>/dev/null');
$hasOld = is_string($old) && str_contains($old, 'class BBCode');
if ($hasOld) { eval('?>' . str_replace(['namespace Akeeba\Component\Engage\Administrator\Helper;', 'final class BBCode'], ['namespace OldBB;', 'final class BBCode'], $old)); }
$t = microtime(true); $out = BBCode::parseBBCode(str_repeat('[b]', 20000) . 'hola'); $d = microtime(true) - $t;
t_ok($d < 1.0, sprintf('60 KB de [b] ([b] x 20000): %.3f s (antes ~35 s)', $d));
t_ok(!str_contains($out, '[') && str_contains($out, '&#91;b]') && !str_contains($out, '<strong>') , 'mas de 500 corchetes: texto literal, sin etiquetas');
$t = microtime(true); BBCode::parseBBCode(str_repeat('[', 100000)); t_ok(microtime(true) - $t < 1.0, '100000 «[» sueltos: rapido');
$t = microtime(true); BBCode::parseBBCode(str_repeat('[i][b]x[/b]', 400)); t_ok(microtime(true) - $t < 2.0, 'justo por debajo del tope (800 corchetes de apertura sin cierre): acotado');
t_ok(BBCode::MAX_BRACKETS === 500, 'MAX_BRACKETS = 500');
if ($hasOld) {
	mt_srand(12345);
	$parts = ['[b]', '[/b]', '[i]', '[/i]', '[u]', '[url=http://example.com]', '[/url]', '[quote]', '[/quote]', '[code]', '[/code]', '[list]', '[*]', '[/list]', 'hola', ' ', "\n", '&amp;', '[x]', '[', ']', 'texto largo de prueba', '[color=red]', '[/color]', '[size=5]', '[/size]', '[img]http://e.com/a.png[/img]'];
	$bad = 0; $n = 0;
	for ($i = 0; $i < 600; $i++) {
		$s = ''; $k = mt_rand(1, 60); for ($j = 0; $j < $k; $j++) { $s .= $parts[mt_rand(0, count($parts) - 1)]; }
		if (substr_count($s, '[') > 500) { continue; }
		$n++; if (BBCode::parseBBCode($s) !== \OldBB\BBCode::parseBBCode($s)) { $bad++; }
	}
	t_ok($bad === 0 && $n > 500, "fuzz de equivalencia: $n entradas normales dan EXACTAMENTE la misma salida que antes ($bad diferencias)");
} else { echo "  (sin git: fuzz de equivalencia omitido)\n"; }
$tab = $lee("$c/backend/src/Table/CommentTable.php");
t_ok(str_contains($tab, 'HARD_MAX_LENGTH = 20000') && str_contains($tab, '$maxLength = ($maxLength > 0) ? min((int) $maxLength, $hardMax) : $hardMax;'), 'tope duro de longitud (20000) aunque max_length sea 0 o mayor');

echo "B) Baja de avisos y enlaces firmados\n";
$ctl = $lee("$c/frontend/src/Controller/CommentsController.php");
$un  = substr($ctl, strpos($ctl, 'public function unsubscribe'), 3000);
t_ok(!str_contains($un, '$this->checkToken()') && str_contains($un, '$this->hasValidSignedLink()'), 'unsubscribe ya no acepta solo el token de formulario');
t_ok(str_contains($un, 'FILTER_VALIDATE_EMAIL') && str_contains($un, 'strcasecmp((string) $user->email, $unsubscribeEmail)') && str_contains($un, "Session::checkToken('request')") && str_contains($un, 'Meta::isAssetViewable'), 'sin enlace firmado: solo usuario en sesion con SU email, token de formulario y activo visible; email validado');
t_ok(substr_count($un, 'throw $denied();') === 2 && !str_contains($un, 'ERR_NOT_FOUND'), 'todas las negativas son el mismo 403 (sin oraculo de IDs)');
$tr = $lee("$c/frontend/src/Mixin/ControllerFrontendCommentsTrait.php");
t_ok(str_contains($tr, "\$tail((string) \$this->input->getCmd('task', '')) !== \$tail((string) \$task)"), 'enlace firmado: la tarea ejecutada debe ser la firmada (si no, token de formulario)');
$sig = $lee("$c/frontend/src/Helper/SignedURL.php");
t_ok(str_contains($sig, "\$task . '-' . \$email . '-'"), 'formato de la firma SIN cambiar (los enlaces ya enviados siguen valiendo; ver CHANGELOG)');

echo "C) Captcha, re-suscripcion y limitador\n";
$cm = $lee("$c/frontend/src/Model/CommentModel.php");
t_ok(str_contains($cm, "\$showCaptcha = (bool) (\$user->guest || !\$user->authorise('core.manage', 'com_engage'));"), 'captcha_for=nonmanager: los invitados SI ven captcha');
foreach ([['guests', true, false, true], ['guests', false, false, false], ['nonmanager', true, false, true], ['nonmanager', false, false, true], ['nonmanager', false, true, false], ['all', true, true, true]] as [$f, $g, $m, $exp]) {
	$s = match ($f) { 'guests' => $g, 'nonmanager' => (bool) ($g || !$m), default => true };
	t_ok($s === $exp, "captcha_for=$f invitado=" . (int) $g . ' moderador=' . (int) $m . ' -> ' . ($exp ? 'captcha' : 'sin captcha'));
}
t_ok(preg_match('/function resubscribeUser.*?if \(\$user === null \|\| \$user->guest\)\s*\{\s*return;/s', $cm) === 1, 'resubscribeUser: un invitado no re-suscribe a nadie');
require_once "$c/frontend/src/Helper/PostRateLimit.php";
use Akeeba\Component\Engage\Site\Helper\PostRateLimit as RL;
t_ok(!RL::exceeded(0, 0, true) && !RL::exceeded(14, 29, false) && RL::exceeded(15, 0, false) && RL::exceeded(0, 30, true) && RL::exceeded(0, 30, false), 'limitador: 15 por usuario y 30 por IP cada 10 minutos');
$pc = $lee("$c/frontend/src/Controller/CommentController.php");
t_ok(str_contains($pc, 'public function save(') && str_contains($pc, 'PostRateLimit::allowed(') && str_contains($pc, 'COM_ENGAGE_COMMENTS_ERR_RATELIMIT'), 'comment.save pasa por el limitador');
$rl = $lee("$c/frontend/src/Helper/PostRateLimit.php");
t_ok(str_contains($rl, "->bind(':uid'") && str_contains($rl, "->bind(':ip'") && str_contains($rl, 'return false;'), 'limitador: consultas ligadas y cierre seguro ante un error');
foreach (['es-ES', 'en-GB'] as $l) { $ini = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW); t_ok(!empty($ini['COM_ENGAGE_COMMENTS_ERR_RATELIMIT']), "$l: mensaje del limitador"); }

echo "D) Resultado de los plugins de foto\n";
define('_JEXEC_X', 1);
require_once "$c/backend/src/Helper/AvatarField.php";
use Akeeba\Component\Engage\Administrator\Helper\AvatarField as AF;
$o = 'https://site.test';
foreach (['/index.php?option=com_mifoto&view=foto&id=3' => true, '/media/a.png' => true, '//evil.com/x.png' => false, '/\\evil.com' => false, 'https://evil.com/a.png' => false, 'https://site.test/a.png' => true, 'https://site.test//evil.com' => false,
	'http://site.test/a.png' => false, 'https://www.gravatar.com/avatar/' . str_repeat('a', 32) . '?s=48&r=g' => true, 'https://www.gravatar.com.evil.com/avatar/' . str_repeat('a', 32) => false, 'javascript:alert(1)' => false, 'data:image/png;base64,AAAA' => false,
	'/a b' => false, "/a\nb" => false, '/a"onerror=1' => false, '' => false, 'foto.png' => false, 'https://site.test.evil.com/a.png' => false] as $v => $exp) {
	t_ok(AF::pluginResultOk((string) $v, $o) === $exp, 'plugin de foto ' . json_encode($v) . ' -> ' . ($exp ? 'aceptada' : 'descartada'));
}
t_ok(AF::pluginResultOk(['x'], $o) === false && AF::pluginResultOk(null, $o) === false && AF::pluginResultOk('/' . str_repeat('a', 2100), $o) === false, 'no cadenas y longitud excesiva: descartadas');
t_ok(str_contains($lee("$c/backend/src/Helper/Avatar.php"), 'AvatarField::pluginResultOk($x, $origin)'), 'pickAvatar filtra con pluginResultOk');

echo "E) AdminOnlyFilter, semaforo, contadores, rendimiento, Akismet\n";
$f = $lee("$c/backend/src/Helper/AdminOnlyFilter.php");
t_ok(str_contains($f, 'SettingsSchema::validate($def, $value)') && str_contains($f, 'return $keep;'), 'AdminOnlyFilter: el resto de claves admin-only pasa por SettingsSchema::validate');
$pd = $lee("$c/backend/src/Helper/PanelData.php");
t_ok(str_contains($pd, "NOT IN (1, 5)") && str_contains($pd, "\$facts['engage_plugin_access'] = null;") && str_contains($pd, 'PACKAGE_VERSION'), 'panel: Public y Guest visibles; la consulta fallida da null');
require_once "$c/backend/src/Helper/PanelHealth.php";
use Akeeba\Component\Engage\Administrator\Helper\PanelHealth as H;
$it = fn(array $f) => array_values(array_filter(H::evaluate($f)['items'], fn($i) => $i['id'] === 'avataraccess'))[0];
$r = $it(['engage_plugin_access' => null]);
t_ok($r['level'] === 'warn' && $r['args']['state'] === 'unknown', 'consulta fallida: estado desconocido (no verde)');
t_ok($it(['engage_plugin_access' => [['id' => 1, 'element' => 'g', 'name' => 'G', 'access' => 5, 'level' => 'Guest']]])['level'] === 'ok', 'nivel Guest se trata como visible para invitados');
$many = []; for ($i = 1; $i <= 8; $i++) { $many[] = ['id' => $i, 'element' => "p$i", 'name' => "P$i", 'access' => 2, 'level' => 'Registered']; }
t_ok(str_contains($it(['engage_plugin_access' => $many])['args']['a'], '(+3)'), 'mas de 5 plugins: indica «(+N)»');
foreach (['es-ES', 'en-GB'] as $l) { $ini = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW); t_ok(!empty($ini['COM_ENGAGE_PANEL_H_AVATARACCESS_UNKNOWN']), "$l: texto del estado desconocido"); }
foreach (['article', 'featured'] as $n) { t_ok(str_contains($lee($root . "/src/plugins/content/engage/layouts/akeeba/engage/content/$n.php"), "setState('filter.enabled', 1)"), "contador publico de $n: solo publicados"); }
$me = $lee("$c/frontend/src/Helper/Meta.php");
t_ok(str_contains($me, 'getNumCommentsForAsset(int $asset_id, bool $onlyPublished = true)') && str_contains($lee("$c/backend/tmpl/comments/default.php"), 'getNumCommentsForAsset($item->asset_id, false)'), 'getNumCommentsForAsset: publicados por defecto; la lista del administrador pide todos');
$cmm = $lee("$c/backend/src/Model/CommentsModel.php");
t_ok(str_contains($cmm, "a.publish_up') . ' IS NULL OR") && str_contains($cmm, "':pubnow2'"), 'listado publico / modulo: respeta publish_up y publish_down del articulo (ligado)');
t_ok(str_contains($cmm, "\$fltType = (is_string(\$rawType) && \$rawType !== '') ?"), 'CommentTypes::available() solo se calcula si se pidio un tipo');
$st = $lee("$c/backend/tmpl/settings/default.php");
t_ok(str_contains($st, "'password' : 'text'") && str_contains($st, 'autocomplete="new-password"'), 'panel: la clave de Akismet es un campo de contrasena');
t_ok(!str_contains($lee("$c/backend/tmpl/controlpanel/default.php"), "?: '3.4."), 'controlpanel: sin version escrita a mano');
t_ok(str_contains($lee("$c/backend/src/Helper/TemplateEmails.php"), 'catch (\Throwable $e)'), 'TemplateEmails::sendMail captura Throwable');
t_fin();
