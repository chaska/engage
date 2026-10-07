<?php
/**
 * 0.6.25: pantalla de opciones modernas y guardado instantaneo. Sin Joomla: el esquema se lee de los manifiestos REALES
 * (config.xml y los plugins de Engage) y el servicio se ejercita con funciones simuladas (permisos, almacen, filtro).
 * Cubre: permisos, claves desconocidas, ambitos ajenos, valores fuera de lista y de rango, tipos raros (arrays, objetos, NUL,
 * cadenas enormes), XSS, fusion sin perdida de parametros, idempotencia, fallos de guardado, cadenas en ambos idiomas,
 * seguridad estatica del controlador, la plantilla y el JS, y contraste de la vista previa. El token CSRF, la ACL real, la base de
 * datos y el navegador se prueban en tests/joomla-live/12-ajustes.sh.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => (string) file_get_contents($f);
$lf   = fn(string $f): string => str_replace("\r\n", "\n", $lee($f));
foreach (['SettingsSchema', 'SettingsException', 'SettingsService', 'SettingsLayout', 'PanelData'] as $k) { require_once "$c/backend/src/Helper/$k.php"; }
use Akeeba\Component\Engage\Administrator\Helper\PanelData;
use Akeeba\Component\Engage\Administrator\Helper\SettingsException;
use Akeeba\Component\Engage\Administrator\Helper\SettingsLayout;
use Akeeba\Component\Engage\Administrator\Helper\SettingsSchema as S;
use Akeeba\Component\Engage\Administrator\Helper\SettingsService;

echo "A) Esquema leido de los manifiestos reales\n";
$man = ['com_engage' => "$c/backend/config.xml", 'plg_engage_gravatar' => "$root/src/plugins/engage/gravatar/gravatar.xml", 'plg_engage_akismet' => "$root/src/plugins/engage/akismet/akismet.xml", 'plg_engage_email' => "$root/src/plugins/engage/email/email.xml"];
$schema = []; $skipped = [];
foreach ($man as $sc => $f) { $r = S::parse($lee($f), $sc); $schema[$sc] = $r['fields']; $skipped[$sc] = $r['skipped']; }
t_ok(count($schema['com_engage']) >= 29 && count($schema['plg_engage_gravatar']) === 8 && count($schema['plg_engage_akismet']) === 3 && count($schema['plg_engage_email']) === 2, 'campos: ' . count($schema['com_engage']) . ' del componente, 8 de Gravatar, 3 de Akismet, 2 de correo');
t_ok(in_array('rules', $skipped['com_engage'], true) && in_array('login_module', $skipped['com_engage'], true) && in_array('custom_default', $skipped['plg_engage_gravatar'], true), 'permisos (rules), modulo de acceso (ModulesModule) y archivo (media) NO se editan aqui: quedan en las opciones clasicas');
$ctl = fn(string $sc, string $k) => $schema[$sc][$k]['control'] ?? '?';
foreach ([['com_engage', 'default_publish', 'switch'], ['com_engage', 'comments_notify_author', 'switch'], ['com_engage', 'reply_indent', 'segmented'], ['com_engage', 'reply_style', 'segmented'], ['com_engage', 'filter_mode', 'segmented'],
	['com_engage', 'captcha_for', 'segmented'], ['com_engage', 'comments_ordering', 'segmented'], ['com_engage', 'max_level', 'segmented'], ['com_engage', 'default_limit', 'select'], ['com_engage', 'captcha', 'select'],
	['com_engage', 'min_length', 'number'], ['com_engage', 'comments_close_after', 'number'], ['com_engage', 'max_spam_age', 'number'], ['com_engage', 'tos_prompt', 'text'], ['com_engage', 'iplookup', 'text'],
	['com_engage', 'htmlpurifier_configstring', 'textarea'], ['plg_engage_gravatar', 'mode', 'segmented'], ['plg_engage_gravatar', 'default_image', 'select'], ['plg_engage_gravatar', 'jbcookies_group', 'text'],
	['plg_engage_akismet', 'key', 'text'], ['plg_engage_akismet', 'discard_blatant', 'switch'], ['plg_engage_email', 'managers_notify', 'switch']] as [$sc, $k, $e]) {
	t_ok($ctl($sc, $k) === $e, "$sc.$k -> control $e (lista corta = segmentado, larga = selector, 0/1 = interruptor)");
}
t_ok(array_map('strval', array_keys($schema['com_engage']['max_level']['options'])) === ['1', '2', '3', '4', '5', '6'] && $schema['com_engage']['default_limit']['useglobal'] === true && $schema['com_engage']['captcha']['dynamic'] === 'captcha', 'max_level 1..6 como opciones; default_limit con "usar global"; captcha con lista dinamica del grupo captcha');
t_ok(S::parse('<config><fieldset><field name="x" type="text"/></fieldset></config>', 'com_content')['fields'] === [] && S::parse('no es xml <', 'com_engage')['fields'] === [], 'ambito ajeno o XML roto: sin campos, sin excepciones');
t_ok(S::parse('<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><config><fieldset><field name="a" type="text" label="&a;"/></fieldset></config>', 'com_engage')['fields']['a']['label'] ?? '' === '', 'entidades externas XML no se resuelven');

echo "B) Distribucion en categorias\n";
$sec = SettingsLayout::sections();
$ids = array_column($sec, 'id');
t_ok($ids === ['design', 'moderation', 'reactions', 'antispam', 'notifications', 'privacy', 'security', 'advanced', 'permissions'], 'categorias: Diseno, Moderacion, Reacciones (0.7.0), Antispam, Notificaciones, Privacidad y Gravatar, Seguridad del HTML, Avanzado y Permisos');
$vistos = [];
foreach ($sec as $s1) { foreach ($s1['items'] as $it) { $vistos[] = $it[0] . '.' . $it[1]; } }
t_ok(count($vistos) === count(array_unique($vistos)), 'ningun ajuste aparece dos veces');
$todos = [];
foreach ($schema as $sc => $fs) { foreach ($fs as $k => $d) { $todos[] = "$sc.$k"; } }
$sin = array_diff($todos, $vistos);
t_ok($sin === [], 'TODO ajuste editable del esquema esta en alguna categoria' . ($sin ? ' (faltan: ' . implode(', ', $sin) . ')' : ''));
t_ok(array_diff($vistos, $todos) === [], 'toda entrada de las categorias existe en un manifiesto (nada inventado)');
t_ok(array_diff(SettingsLayout::EXCLUDED, array_merge($skipped['com_engage'], $skipped['plg_engage_gravatar'])) === [], 'los excluidos coinciden con los campos no editables');

echo "C) Validacion por tipo de campo\n";
$v = fn(string $sc, string $k, $val, array $dyn = []) => S::validate($schema[$sc][$k], $val, $dyn);
$bad = function (string $sc, string $k, $val, array $dyn = []) use ($schema): bool { try { S::validate($schema[$sc][$k], $val, $dyn); return false; } catch (InvalidArgumentException $e) { return true; } };
t_ok($v('com_engage', 'default_publish', '0') === '0' && $v('com_engage', 'default_publish', 1) === '1' && $v('com_engage', 'default_publish', true) === '1' && $v('com_engage', 'default_publish', false) === '0', 'interruptor: 0/1 (tambien int y bool)');
foreach (['2', '', ' 1', '1 ', 'on', 'true', '01', '-1', '1.0', '١'] as $x) { t_ok($bad('com_engage', 'default_publish', $x), "interruptor rechaza '" . addcslashes($x, "\0") . "'"); }
t_ok($v('com_engage', 'reply_indent', 'large') === 'large' && $bad('com_engage', 'reply_indent', 'huge') && $bad('com_engage', 'reply_indent', 'LARGE') && $bad('com_engage', 'reply_indent', 'none '), 'lista: solo valores declarados, sensible a mayusculas y espacios');
t_ok($v('com_engage', 'theme', 'dark') === 'dark' && $bad('com_engage', 'theme', '../../x') && $bad('com_engage', 'theme', 'classic" onmouseover="x'), 'tema: lista cerrada');
t_ok($v('com_engage', 'mobile_avatar', 'show') === 'show' && $v('com_engage', 'mobile_avatar', 'auto') === 'auto' && $bad('com_engage', 'mobile_avatar', 'SHOW') && $bad('com_engage', 'mobile_avatar', 'none') && $bad('com_engage', 'mobile_avatar', 'hide" onmouseover="x') && $bad('com_engage', 'mobile_avatar', ['show']), 'mobile_avatar (avatar en movil): lista cerrada auto/show/hide');
t_ok($v('com_engage', 'default_limit', '') === '' && $v('com_engage', 'default_limit', '20') === '20' && $bad('com_engage', 'default_limit', '21'), 'default_limit: vacio = usar global, 20 valido, 21 no');
t_ok($v('com_engage', 'max_level', '6') === '6' && $bad('com_engage', 'max_level', '7') && $bad('com_engage', 'max_level', '0') && $bad('com_engage', 'max_level', '3.5') && $bad('com_engage', 'max_level', 'tres'), 'max_level: 1..6, entero');
t_ok($v('com_engage', 'comments_close_after', '3650') === '3650' && $bad('com_engage', 'comments_close_after', '3651') && $bad('com_engage', 'comments_close_after', '-1') && $v('com_engage', 'comments_close_after', '0') === '0', 'comments_close_after: 0..3650');
t_ok($v('com_engage', 'min_length', '1048576') === '1048576' && $bad('com_engage', 'min_length', '1048577') && $bad('com_engage', 'min_length', '1e3') && $bad('com_engage', 'min_length', '0x10') && $bad('com_engage', 'min_length', ' 5') && $bad('com_engage', 'min_length', str_repeat('9', 30)), 'min_length: 0..1048576, solo cifras, sin notacion cientifica ni hexadecimal');
t_ok($v('com_engage', 'min_length', '007') === '7', 'ceros a la izquierda se normalizan');
t_ok($v('com_engage', 'max_spam_age', '365') === '365' && $bad('com_engage', 'max_spam_age', '366'), 'max_spam_age: 0..365');
t_ok($v('com_engage', 'captcha', '-1') === '-1' && $v('com_engage', 'captcha', '') === '' && $v('com_engage', 'captcha', 'recaptcha', ['captcha' => ['recaptcha']]) === 'recaptcha' && $bad('com_engage', 'captcha', 'recaptcha') && $bad('com_engage', 'captcha', 'evil', ['captcha' => ['recaptcha']]), 'captcha: -1, global, o un plugin de captcha ACTIVADO (lista dinamica); otro nombre se rechaza');
// texto
t_ok($v('com_engage', 'iplookup', 'https://whatismyipaddress.com/ip/%s') === 'https://whatismyipaddress.com/ip/%s' && $v('com_engage', 'iplookup', '') === '' && $v('com_engage', 'iplookup', 'http://x.example/lookup?ip=%s&a=1') !== '', 'iplookup: URL http(s) con %s, o vacio');
foreach (['javascript:alert(1)//%s', 'data:text/html,%s', 'https://a.example/%s%s', 'https://a.example/"onclick="x', 'https://a.example/%d', 'ftp://a/%s', "https://a.example/\n%s", 'https://a b/%s', 'https://a.example/<script>%s', 'https://a.example/%25'] as $x) { t_ok($bad('com_engage', 'iplookup', $x), 'iplookup rechaza ' . json_encode($x)); }
t_ok($v('plg_engage_gravatar', 'jbcookies_group', ' marketing ') === 'marketing' && $bad('plg_engage_gravatar', 'jbcookies_group', 'a<b') && $bad('plg_engage_gravatar', 'jbcookies_group', str_repeat('a', 65)) && $v('plg_engage_gravatar', 'jbcookies_group', '') === '', 'jbcookies_group: letras, cifras, guion y espacio; 64 max; se recorta');
t_ok($v('plg_engage_akismet', 'key', 'abc123DEF456') === 'abc123DEF456' && $bad('plg_engage_akismet', 'key', 'abc 123') && $bad('plg_engage_akismet', 'key', "abc\n") && $bad('plg_engage_akismet', 'key', str_repeat('a', 65)), 'clave de Akismet: alfanumerica, 64 max');
$wl = 'p,b,a[href],i,u,strong,em,small,big,span[style],font[size],font[color],ul,ol,li,br,img[src],img[width],img[height],code,pre,blockquote';
t_ok($v('com_engage', 'htmlpurifier_configstring', $wl) === $wl && $v('com_engage', 'htmlpurifier_configstring', "p,b\r\n,a[href|title]") === "p,b\n,a[href|title]", 'lista blanca de HTML Purifier: la lista por defecto y variantes con saltos de linea (CRLF -> LF)');
foreach (['p,<script>', 'p,a[href]; DROP', 'p,a{x}', 'p,"x"', "p,\0b", str_repeat('p,', 2001), 'p,a[href]`', 'p,\\x'] as $x) { t_ok($bad('com_engage', 'htmlpurifier_configstring', $x), 'lista blanca rechaza ' . json_encode(mb_substr($x, 0, 20))); }
t_ok($v('com_engage', 'tos_prompt', 'Acepto las <a href="/t">condiciones</a>') !== '' && $bad('com_engage', 'tos_prompt', str_repeat('x', 501)), 'tos_prompt: texto de hasta 500 caracteres (el filtro safehtml lo aplica el servicio)');
// tipos raros
foreach (['default_publish', 'min_length', 'iplookup', 'theme', 'htmlpurifier_configstring'] as $k) {
	foreach ([[], ['0'], new stdClass(), null, 1.5, NAN, INF, fopen('php://memory', 'r')] as $i => $x) {
		t_ok($bad('com_engage', $k, $x), "$k rechaza el tipo " . gettype($x) . (is_float($x) ? ' ' . var_export($x, true) : ''));
	}
}
foreach (["abc\0def", "a\x01b", "a\x7Fb", "\xC3\x28", "\xFF\xFE"] as $x) { t_ok($bad('com_engage', 'iplookup', $x) && $bad('com_engage', 'tos_prompt', $x) && $bad('plg_engage_gravatar', 'jbcookies_group', $x), 'NUL, controles y UTF-8 invalido rechazados: ' . bin2hex($x)); }
t_ok($bad('com_engage', 'tos_prompt', str_repeat('A', 10 * 1024 * 1024)), 'cadena de 10 MB rechazada por longitud (sin agotar memoria)');
t_ok($v('com_engage', 'tos_prompt', "  hola  ") === 'hola' && $bad('com_engage', 'tos_prompt', "a\tb\nc"), 'texto: se recorta; sin tabuladores ni saltos de linea en campos de una linea');

echo "D) Servicio: permisos, claves, valores, fusion e idempotencia (con funciones simuladas)\n";
$mk = function (array $opts = []) use ($schema) {
	$st = (object) ['params' => ['com_engage' => ['default_publish' => '1', 'theme' => 'classic', 'otro' => 'x', 'rules' => '{"core.admin":{"8":1}}'], 'plg_engage_gravatar' => ['mode' => 'ask', 'rating' => 'G'], 'plg_engage_akismet' => ['key' => 'k', 'check' => 'all'], 'plg_engage_email' => []],
		'saves' => 0, 'logs' => [], 'asked' => []];
	$auth = $opts['auth'] ?? ['com_engage' => true, 'plg_engage_gravatar' => true, 'plg_engage_akismet' => true, 'plg_engage_email' => true];
	$svc  = new SettingsService($schema,
		function (string $s) use ($auth, $st) { $st->asked[] = $s; return $auth[$s] ?? false; },
		fn(string $s) => $st->params[$s],
		function (string $s, array $p) use ($st, $opts) { if (!empty($opts['fail'])) { throw new RuntimeException('db'); } $st->saves++; $st->params[$s] = $p; },
		fn(string $v, string $f) => $f === 'safehtml' ? preg_replace('#<(?!/?(a|b|i|strong|em)\b)[^>]*>#i', '', $v) : strip_tags($v),
		fn() => ['captcha' => ['recaptcha', 'hcaptcha']],
		isset($opts['log']) ? $opts['log'] : function (string $s, string $k) use ($st) { $st->logs[] = "$s.$k"; });
	return [$svc, $st];
};
$code = function (callable $f) { try { $f(); return 0; } catch (SettingsException $e) { return $e->getCode(); } };
[$svc, $st] = $mk();
$r = $svc->apply('com_engage', 'default_publish', '0');
t_ok($r === ['scope' => 'com_engage', 'key' => 'default_publish', 'value' => '0', 'changed' => true] && $st->params['com_engage']['default_publish'] === '0', 'guarda un ajuste valido');
t_ok($st->params['com_engage']['theme'] === 'classic' && $st->params['com_engage']['otro'] === 'x' && $st->params['com_engage']['rules'] === '{"core.admin":{"8":1}}', 'FUSION sin perdida: el resto de parametros (incluidos los desconocidos y las reglas) intactos');
$r = $svc->apply('com_engage', 'default_publish', '0');
t_ok($r['changed'] === false && $st->saves === 1 && $st->logs === ['com_engage.default_publish'], 'IDEMPOTENTE: repetir el mismo valor no vuelve a escribir ni a registrar');
$r = $svc->apply('com_engage', 'default_publish', 0);
t_ok($r['changed'] === false && $st->saves === 1, 'idempotente tambien con el entero 0');
$svc->apply('plg_engage_gravatar', 'mode', 'off');
t_ok($st->params['plg_engage_gravatar'] === ['mode' => 'off', 'rating' => 'G'] && $st->params['com_engage']['default_publish'] === '0', 'ajuste de plugin: fusiona con los del plugin y no toca los del componente');
$svc->apply('plg_engage_email', 'managers_notify', '1');
t_ok($st->params['plg_engage_email'] === ['managers_notify' => '1'], 'plugin sin parametros previos: se crea solo ese');
// ambitos ajenos
foreach (['com_content', 'plg_system_cache', 'plg_system_engagecache', 'plg_content_engage', 'com_users', 'com_config', 'pkg_engage', '../com_engage', 'com_engage ', 'COM_ENGAGE', '', null, [], 5, 'plg_engage_gravatar/../../x'] as $sc) {
	[$s2, $t2] = $mk();
	t_ok($code(fn() => $s2->apply($sc, 'default_publish', '1')) === 422 && $t2->saves === 0 && $t2->asked === [], 'ambito ajeno ' . json_encode($sc) . ': 422 sin tocar nada ni consultar permisos');
}
// claves desconocidas
foreach (['rules', 'login_module', 'custom_default', 'params', 'enabled', 'element', '__proto__', 'constructor', 'default_publish ', 'DEFAULT_PUBLISH', '', null, ['default_publish'], 0, 'mode', 'key', 'default_publish[]', 'default_publish\0', 'access', 'manifest_cache', 'password'] as $k) {
	[$s2, $t2] = $mk();
	t_ok($code(fn() => $s2->apply('com_engage', $k, '1')) === 422 && $t2->saves === 0, 'clave desconocida ' . json_encode($k) . ': 422 y nada cambia');
}
[$s2, $t2] = $mk();
t_ok($code(fn() => $s2->apply('plg_engage_gravatar', 'default_publish', '1')) === 422 && $code(fn() => $s2->apply('com_engage', 'mode', 'off')) === 422 && $code(fn() => $s2->apply('plg_engage_gravatar', 'custom_default', 'x.png')) === 422, 'una clave de un ambito no sirve en otro; los campos de tipo archivo no se aceptan');
// valores invalidos
foreach ([['theme', 'neon'], ['default_publish', '2'], ['min_length', '-5'], ['min_length', '99999999'], ['iplookup', 'javascript:alert(1)'], ['reply_indent', ['a']], ['max_level', '99'], ['htmlpurifier_configstring', '<script>'], ['captcha', 'otro']] as [$k, $x]) {
	[$s2, $t2] = $mk();
	t_ok($code(fn() => $s2->apply('com_engage', $k, $x)) === 422 && $t2->saves === 0 && $t2->params['com_engage']['theme'] === 'classic', "valor invalido para $k: 422 y nada cambia");
}
// permisos
[$s2, $t2] = $mk(['auth' => ['com_engage' => false, 'plg_engage_gravatar' => true, 'plg_engage_akismet' => true, 'plg_engage_email' => true]]);
t_ok($code(fn() => $s2->apply('com_engage', 'default_publish', '0')) === 403 && $t2->saves === 0, 'sin core.admin/core.options del componente: 403 y nada cambia');
t_ok($code(fn() => $s2->apply('com_engage', 'clave-inexistente', 'x')) === 403 && $code(fn() => $s2->apply('com_engage', 'default_publish', 'no-valido')) === 403, 'sin permiso se contesta 403 ANTES de mirar clave o valor (no se revela cual es valido)');
t_ok($code(fn() => $s2->apply('plg_engage_gravatar', 'mode', 'off')) === 0 && $t2->saves === 1, 'el permiso del componente y el de los plugins son independientes');
[$s2, $t2] = $mk(['auth' => ['com_engage' => true, 'plg_engage_gravatar' => false, 'plg_engage_akismet' => false, 'plg_engage_email' => false]]);
t_ok($code(fn() => $s2->apply('plg_engage_gravatar', 'mode', 'off')) === 403 && $code(fn() => $s2->apply('plg_engage_akismet', 'key', 'abc')) === 403 && $t2->saves === 0, 'sin permiso de edicion de plugins: 403 en los plugins de Engage (aunque sea admin del componente)');
// escalada de privilegios
[$s2, $t2] = $mk();
foreach (['rules', 'core.admin', 'access', 'admin_users'] as $k) { $code(fn() => $s2->apply('com_engage', $k, '{"core.admin":{"2":1}}')); }
t_ok($t2->params['com_engage']['rules'] === '{"core.admin":{"8":1}}' && $t2->saves === 0, 'no se pueden tocar las reglas de permisos ni otros parametros por esta via');
// filtro y XSS en valores
[$s2, $t2] = $mk();
$s2->apply('com_engage', 'tos_prompt', 'Acepto <script>alert(1)</script> las <a href="/t">condiciones</a> <img src=x onerror=alert(1)>');
t_ok(!str_contains($t2->params['com_engage']['tos_prompt'], '<script') && !str_contains($t2->params['com_engage']['tos_prompt'], 'onerror') && str_contains($t2->params['com_engage']['tos_prompt'], '<a href'), 'tos_prompt (safehtml): el filtro de Joomla quita script e img con handlers y deja el enlace');
$s2->apply('plg_engage_gravatar', 'jbcookies_group', 'marketing');
t_ok($t2->params['plg_engage_gravatar']['jbcookies_group'] === 'marketing', 'texto simple intacto');
[$s3, $t3] = $mk();
t_ok($code(fn() => $s3->apply('plg_engage_gravatar', 'jbcookies_group', '<b>x</b>')) === 422 && $code(fn() => $s3->apply('com_engage', 'iplookup', 'https://a.example/%s"><script>')) === 422, 'el HTML en campos de texto se rechaza (no se "arregla" en silencio)');
// captcha dinamico
t_ok($code(fn() => $s2->apply('com_engage', 'captcha', 'hcaptcha')) === 0 && $code(fn() => $s2->apply('com_engage', 'captcha', 'recaptcha_invisible')) === 422, 'captcha: solo los plugins de captcha activados que ofrece el servidor');
// fallo del almacen y del registro
[$s2, $t2] = $mk(['fail' => true]);
t_ok($code(fn() => $s2->apply('com_engage', 'default_publish', '0')) === 500 && $t2->logs === [], 'fallo al guardar: 500 y NO se registra la accion');
[$s2, $t2] = $mk(['log' => function () { throw new RuntimeException('log'); }]);
t_ok($code(fn() => $s2->apply('com_engage', 'default_publish', '0')) === 0 && $t2->params['com_engage']['default_publish'] === '0', 'si el registro de acciones falla, el guardado se mantiene');
[$s2, $t2] = $mk(['log' => null]);
t_ok($code(fn() => $s2->apply('com_engage', 'default_publish', '0')) === 0, 'sin registro de acciones (plugin apagado): se guarda igual');
t_ok((new SettingsException('x', 403))->getCode() === 403 && SettingsException::invalid()->getCode() === 422 && SettingsException::failed()->getCode() === 500 && SettingsException::denied()->getCode() === 403, 'codigos HTTP genericos: 403, 422, 500');

echo "E) Seguridad estatica: controlador, almacen, plantilla, JS\n";
$ctrl = $lf("$c/backend/src/Controller/SettingsController.php");
t_ok(str_contains($ctrl, "Session::checkToken('post')") && strpos($ctrl, "checkToken('post')") < strpos($ctrl, '$service->apply'), 'el token CSRF se comprueba ANTES de aplicar nada');
t_ok(str_contains($ctrl, "'POST'") && str_contains($ctrl, 'respond(405') && str_contains($ctrl, 'respond(403'), 'solo POST (405 si no) y 403 sin token');
t_ok(!preg_match('/\$_(GET|POST|REQUEST|COOKIE)/', $ctrl) && substr_count($ctrl, "->get('") === 3, 'solo se leen tres campos (scope, key, value) y por el objeto de entrada de Joomla');
t_ok(str_contains($ctrl, 'JSON_HEX_TAG') && str_contains($ctrl, 'X-Content-Type-Options: nosniff') && str_contains($ctrl, 'no-store') && !preg_match("/'(value|scope|key)'\s*=>\s*\\\$(value|scope|key)\b/", $ctrl), 'respuesta JSON con cabeceras seguras y sin repetir lo recibido');
t_ok(!str_contains($ctrl, 'getMessage') && !str_contains($ctrl, 'getTraceAsString'), 'las respuestas de error no incluyen mensajes ni trazas');
$stor = $lf("$c/backend/src/Helper/SettingsStorage.php");
t_ok(str_contains($stor, 'new ExtensionTable') && str_contains($stor, '->store()') && str_contains($stor, "'core.edit', 'com_plugins'") && str_contains($stor, "'core.admin', 'com_engage'") && str_contains($stor, "'core.options', 'com_engage'"), 'guarda con Table Extension; permisos core.admin/core.options y core.edit de com_plugins');
t_ok(!preg_match('/\b(INSERT|DELETE|CREATE TABLE|DROP)\b/', $stor) && substr_count($stor, '->bind(') >= 6, 'sin tablas nuevas ni SQL de escritura propio; consultas con parametros enlazados');
t_ok(str_contains($stor, "isset(SettingsSchema::SCOPES[\$scope])") && !str_contains($stor, '$_'), 'almacen: solo ambitos de la lista cerrada');
t_ok(str_contains($stor, "PluginHelper::isEnabled('actionlog', 'engage')") && !str_contains($stor, "'value'"), 'registro de acciones solo con el plugin activado y SIN el valor (puede ser una clave)');
$tpl = $lf("$c/backend/tmpl/settings/default.php");
preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $tpl, $ex);
$sinEsc = [];
foreach ($ex[1] as $e) { if (preg_match('/^(\$e\(|\$url\(|I::icon\(|PanelUi::themeSwitcher\(\)|\(int\))/', $e)) { continue; } $sinEsc[] = $e; }
t_ok(!$sinEsc, 'toda salida <?= de la plantilla de opciones va escapada' . ($sinEsc ? ' -> ' . implode(' | ', array_slice($sinEsc, 0, 3)) : ''));
t_ok(!preg_match('/\sstyle\s*=/i', $tpl) && !preg_match('/<script/i', $tpl) && !preg_match('/\son[a-z]+\s*=/i', $tpl) && !preg_match('#https?://#', $tpl), 'plantilla sin style="", <script>, handlers on* ni URL externas');
t_ok(str_contains($tpl, 'role="switch"') && str_contains($tpl, 'role="radiogroup"') && str_contains($tpl, 'role="tablist"') && str_contains($tpl, 'role="tabpanel"') && str_contains($tpl, 'role="alert"') && str_contains($tpl, 'role="status"') && str_contains($tpl, 'aria-labelledby') && str_contains($tpl, 'aria-describedby'), 'ARIA: switch, radiogroup, tablist, tabpanel, alert (errores), status (aviso), labelledby y describedby');
$js = $lee("$c/media/js/settings.js");
$js = preg_replace('#/\*.*?\*/#s', '', $js); $js = preg_replace('#(?<!:)//[^\n]*#', '', $js);
t_ok(!preg_match('/\b(eval|confirm|alert|prompt)\s*\(|innerHTML|document\.write|localStorage|sessionStorage/', $js), 'settings.js sin eval, confirm(), innerHTML, document.write ni almacenamiento del navegador');
t_ok(substr_count($js, 'fetch(') === 1 && str_contains($js, 'fetch(opts.url') && str_contains($js, "body.append(opts.token, '1')") && !preg_match('#https?://#', $js), 'una sola peticion de red: fetch a la URL que da el servidor, con el token en cada peticion');
t_ok(str_contains($js, 'function fail') && str_contains($js, 'paint(row, previous)') && str_contains($js, "'is-error'") && str_contains($js, 'msg.reverted'), 'si falla el guardado el control vuelve al valor anterior y se avisa');
$css = $lee("$c/media/css/settings.css"); $cssn = preg_replace('#/\*.*?\*/#s', '', $css);
$fuera = [];
foreach (preg_split('/}/', $cssn) as $bl) { if (!str_contains($bl, '{')) { continue; } [$sel] = explode('{', $bl, 2); foreach (explode(',', $sel) as $s1) { $s1 = trim(preg_replace('/@(media|container)[^{]*$/', '', trim($s1))); if ($s1 === '' || str_starts_with($s1, '@') || preg_match('/^(from|to|\d+%)$/', $s1)) { continue; } if (!str_contains($s1, '.eg-admin')) { $fuera[] = $s1; } } }
t_ok(!$fuera && !preg_match('/@import|url\(|https?:\/\//', $cssn) && substr_count($cssn, '{') === substr_count($cssn, '}'), 'settings.css: todo bajo .eg-admin, sin recursos externos, llaves equilibradas');
t_ok(str_contains($css, 'prefers-reduced-motion: reduce') && str_contains($css, 'forced-colors') && str_contains($css, '@container eg'), 'settings.css: prefers-reduced-motion, forced-colors y adaptacion al ancho');

echo "F) Idiomas, activos y manifiesto\n";
$ini = [];
foreach (['en-GB', 'es-ES'] as $l) { $ini[$l] = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW) + parse_ini_string($lee("$c/backend/language/$l/com_engage.sys.ini"), false, INI_SCANNER_RAW); }
$usadas = [];
foreach ([$tpl, $lf("$c/backend/src/View/Settings/HtmlView.php")] as $t) { preg_match_all("/'(COM_ENGAGE_[A-Z0-9_]+)'/", $t, $mm); $usadas = array_merge($usadas, $mm[1]); }
foreach ($ids as $i) { $usadas[] = 'COM_ENGAGE_SET_SEC_' . strtoupper($i); $usadas[] = 'COM_ENGAGE_SET_SEC_' . strtoupper($i) . '_DESC'; }
$usadas = array_unique($usadas);
foreach (['en-GB', 'es-ES'] as $l) { $f = array_filter($usadas, fn($k) => !isset($ini[$l][$k]) && !in_array($k, ['COM_ENGAGE', 'COM_ENGAGE_SET_SEC_'], true)); t_ok(!$f, "$l: las " . count($usadas) . ' cadenas de la pantalla de opciones existen' . ($f ? ' (faltan: ' . implode(', ', $f) . ')' : '')); }
foreach (['en-GB', 'es-ES'] as $l) {
	$faltan = [];
	foreach ($schema as $sc => $fs) { foreach ($fs as $k => $d) { if ($sc !== 'com_engage') { continue; } if (!isset($ini[$l][$d['label']]) || !isset($ini[$l][$d['desc']])) { $faltan[] = $k; } } }
	t_ok(!$faltan, "$l: cada ajuste del componente tiene etiqueta y ayuda en com_engage.sys.ini (se reutilizan las existentes)" . ($faltan ? ' (faltan: ' . implode(', ', $faltan) . ')' : ''));
}
$log = ['en-GB' => $lee("$root/src/plugins/actionlog/engage/language/en-GB/plg_actionlog_engage.ini"), 'es-ES' => $lee("$root/src/plugins/actionlog/engage/language/es-ES/plg_actionlog_engage.ini")];
t_ok(str_contains($log['en-GB'], 'COM_ENGAGE_USERLOG_SETTING_CHANGED') && str_contains($log['es-ES'], 'COM_ENGAGE_USERLOG_SETTING_CHANGED') && str_contains($log['es-ES'], 'cambió el ajuste'), 'cadena del registro de acciones en ambos idiomas (es-ES de usted)');
$assets = json_decode($lee("$c/media/joomla.asset.json"), true); $by = [];
foreach ($assets['assets'] as $a) { $by[$a['type'] . ':' . $a['name']] = $a; }
t_ok(($by['style:com_engage.settings']['uri'] ?? '') === 'com_engage/settings.css' && ($by['script:com_engage.settings']['uri'] ?? '') === 'com_engage/settings.js' && ($by['script:com_engage.settings']['attributes']['defer'] ?? false) === true && !isset($by['style:com_engage.settings']['dependencies']), 'activos settings.css y settings.js (defer); sin dependencias cruzadas que Joomla no resuelve');
$x = simplexml_load_string($lee("$c/engage.xml")); $sub = $x->xpath('//administration/submenu/menu');
t_ok(count($sub) === 4 && (string) $sub[3]['link'] === 'option=com_engage&view=settings' && (string) $sub[3] === 'COM_ENGAGE_MENU_SETTINGS', 'submenu Opciones en el manifiesto');
t_ok(str_contains($lee("$c/backend/language/es-ES/com_engage.sys.ini"), 'COM_ENGAGE_MENU_SETTINGS="Opciones"'), 'menu en es-ES');
$vista = $lf("$c/backend/src/View/Settings/HtmlView.php");
t_ok(str_contains($vista, "authorise('core.admin', 'com_engage')") && str_contains($vista, "authorise('core.options', 'com_engage')") && str_contains($vista, '403'), 'la vista exige core.admin o core.options (como las opciones clasicas)');
t_ok(str_contains($vista, "'url'   => Route::_('index.php?option=com_engage&task=settings.save&format=json'") && str_contains($vista, 'Session::getFormToken()'), 'la vista pasa al JS la URL de guardado y el token');
t_ok(str_contains($vista, 'COM_ENGAGE_SET_CLASSIC') && str_contains($tpl, 'classicUrl') && str_contains($vista, 'com_config&view=component&component=com_engage'), 'enlace "Opciones clasicas" a la pantalla de Joomla (respaldo)');
$panel = $lf("$c/backend/tmpl/controlpanel/default.php");
t_ok(str_contains($panel, "'index.php?option=com_engage&view=settings'") && str_contains($panel, "&section=permissions") && str_contains($panel, "'&section=' . rawurlencode"), 'el panel enlaza a la pantalla de opciones y cada aviso del semaforo abre su categoria');
t_ok(PanelData::FORK_VERSION === '0.8.1', 'PanelData::FORK_VERSION = 0.8.1');

echo "G) Contraste de la vista previa (colores propios de cada tema de comentarios)\n";
$lum = function (string $h): float { $h = ltrim($h, '#'); $c = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; foreach ($c as &$v) { $v /= 255; $v = $v <= .03928 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4; } return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2]; };
$cr = fn(string $a, string $b): float => (max($lum($a), $lum($b)) + .05) / (min($lum($a), $lum($b)) + .05);
preg_match('/\.eg-admin \.eg-preview \{\s*([^}]*)\}/', $css, $b0);
$base = []; preg_match_all('/--pv-([a-z0-9]+):\s*(#[0-9a-f]{6}|transparent)/i', $b0[1], $m1, PREG_SET_ORDER); foreach ($m1 as $p) { $base[$p[1]] = $p[2]; }
foreach (['classic' => [], 'modern' => null, 'minimal' => null, 'dark' => null] as $tema => $_) {
	$v = $base;
	if ($tema !== 'classic') { preg_match('/\.eg-preview\[data-theme="' . $tema . '"\] \{([^}]*)\}/', $css, $bt); preg_match_all('/--pv-([a-z0-9]+):\s*(#[0-9a-f]{6}|transparent)/i', $bt[1], $m2, PREG_SET_ORDER); foreach ($m2 as $p) { $v[$p[1]] = $p[2]; } }
	$fondos = array_unique(array_filter([$v['bg'], $v['card'] !== 'transparent' ? $v['card'] : null]));
	foreach ($fondos as $bg) {
		foreach (['text', 'text2', 'accent'] as $k) { $ra = $cr($v[$k], $bg); t_ok($ra >= 4.5, sprintf('vista previa %s: %s sobre %s = %.2f:1 (minimo 4,5)', $tema, $k, $bg, $ra)); }
	}
	$ra = $cr('#ffffff', $v['av']); t_ok($ra >= 4.5, sprintf('vista previa %s: inicial blanca del avatar = %.2f:1', $tema, $ra));
}
t_fin();
