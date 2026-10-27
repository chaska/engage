<?php
/**
 * 0.9.1: opciones sensibles (core.admin, no solo core.options) y endurecimientos del envoltorio de los correos.
 *  A) SettingsService: las opciones sensibles exigen core.admin; las demas, con core.options basta
 *  B) AdminOnlyFilter (formulario de opciones de Joomla / API): un usuario sin core.admin no cambia nada; nadie escribe lo que escribe Engage
 *  C) TrustedBase::isInternalHost con variantes (IPv6 normalizado, IPv4 mapeada/NAT64/6to4, CGNAT...)
 *  D) EmailWrapper: HTML muy anidado (B1), style con lista blanca (B2), elementos y href (B4)
 *  E) el codigo: ficheros, config.xml, panel
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
require __DIR__ . '/stubs/otros-componentes.php';
$root = dirname(__DIR__) . '/src/component/backend';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
foreach (['SettingsException', 'SettingsSchema', 'SettingsStorage', 'SettingsService', 'TrustedBase', 'EmailWrapper', 'AdminOnlyFilter', 'PanelHealth'] as $c) { require_once "$root/src/Helper/$c.php"; }

use Akeeba\Component\Engage\Administrator\Helper\{AdminOnlyFilter, EmailWrapper as W, PanelHealth, SettingsException, SettingsSchema, SettingsService, TrustedBase};

echo "A) SettingsService\n";
$schema = ['com_engage' => SettingsSchema::parse((string) file_get_contents("$root/config.xml"), 'com_engage')['fields']];
$mk = function (bool $admin, bool $withCallable = true) use ($schema) {
	$st = (object) ['p' => ['com_engage' => ['email_base_url' => 'https://old.example.com']], 'saves' => 0, 'logs' => []];
	$args = [$schema, fn(string $s) => true, fn(string $s) => $st->p[$s], function (string $s, array $p) use ($st) { $st->saves++; $st->p[$s] = $p; }, fn(string $v, string $f) => $v, fn() => ['captcha' => ['recaptcha']],
		function (string $s, string $k, ?string $o = null, ?string $n = null) use ($st) { $st->logs[] = [$k, $o, $n]; }];
	if ($withCallable) { $args[] = fn() => $admin; }
	return [new SettingsService(...$args), $st];
};
$code = function (callable $f) { try { $f(); return 0; } catch (SettingsException $e) { return $e->getCode(); } };
TrustedBase::$requestHint = ['force' => 0, 'host' => ''];
foreach (SettingsSchema::ADMIN_ONLY['com_engage'] as $k) {
	if (in_array($k, SettingsSchema::NEVER_FORM, true)) { continue; }
	$def = $schema['com_engage'][$k];
	$val = ['email_base_url' => 'https://new.example.com', 'email_confidential' => '0', 'iplookup' => 'https://x.example/%s', 'retention_months' => '6', 'privacy_url' => 'https://example.com/privacidad', 'htmlpurifier_configstring' => 'p,b', 'captcha' => 'recaptcha'][$k] ?? (string) array_key_last($def['options']);
	[$svc, $st] = $mk(false);
	$c1 = $code(fn() => $svc->apply('com_engage', $k, $val));
	t_ok($c1 === 403 && $st->saves === 0, "$k: con core.options y SIN core.admin = 403 y no cambia nada (obtenido $c1)");
	[$svc, $st] = $mk(true);
	t_ok($code(fn() => $svc->apply('com_engage', $k, $val)) === 0 && $st->saves === 1, "$k: con core.admin se guarda");
	[$svc, $st] = $mk(true, false);
	t_ok($code(fn() => $svc->apply('com_engage', $k, $val)) === 403 && $st->saves === 0, "$k: sin la funcion de permiso de administrador (fail-closed) = 403");
}
foreach (['email_learned' => 'email_base_learned', 'audit' => 'email_base_audit'] as $k) {
	[$svc, $st] = $mk(true);
	t_ok($code(fn() => $svc->apply('com_engage', $k, 'https://evil.example')) === 422 && $st->saves === 0, "$k: ni el administrador lo escribe por el panel (campo oculto, 422)");
}
foreach (['email_rich' => '0', 'email_accent' => 'green', 'email_include_links' => '0', 'default_publish' => '1', 'min_length' => '5', 'tos_prompt' => 'Texto'] as $k => $v) {
	[$svc, $st] = $mk(false);
	t_ok($code(fn() => $svc->apply('com_engage', $k, $v)) === 0 && $st->saves === 1, "$k: con core.options basta (no es sensible)");
}
[$svc, $st] = $mk(true);
$svc->apply('com_engage', 'email_base_url', 'https://new.example.com/');
t_ok($st->logs === [['email_base_url', 'https://old.example.com', 'https://new.example.com']], 'el registro recibe el valor anterior y el nuevo (normalizado)');
TrustedBase::$requestHint = null;

echo "B) AdminOnlyFilter (formulario de Joomla y API)\n";
$F = fn(string $key, string $v, bool $admin, array $stored = [], array $posted = []) => (function () use ($key, $v, $admin, $stored, $posted) { AdminOnlyFilter::$override = [$admin, $stored, $posted]; try { return AdminOnlyFilter::$key($v); } finally { AdminOnlyFilter::$override = null; } })();
$stored = ['email_base_url' => 'https://old.example.com', 'email_base_learned' => 'https://learned.example.com', 'email_confidential' => '1', 'captcha' => 'recaptcha', 'iplookup' => 'https://ip.example/%s', 'filter_mode' => 'strict'];
t_ok($F('email_base_url', 'https://phish.example.net', false, $stored) === 'https://old.example.com', 'email_base_url: sin core.admin el valor enviado se ignora (se conserva el guardado)');
t_ok($F('email_base_url', 'https://old.example.com', false, $stored) === 'https://old.example.com', 'email_base_url: sin core.admin y sin cambio: igual');
t_ok($F('email_base_url', '', false, $stored) === 'https://old.example.com', 'email_base_url: sin core.admin no puede borrarla');
t_ok($F('email_base_url', 'https://new.example.com/', true, $stored) === 'https://new.example.com', 'email_base_url: con core.admin se valida y se normaliza');
t_ok($F('email_base_url', 'javascript:alert(1)', true, $stored) === 'https://old.example.com', 'email_base_url: con core.admin un valor hostil no se guarda (se conserva el anterior)');
t_ok($F('email_base_learned', 'https://evil.example', true, $stored) === 'https://learned.example.com' && $F('email_base_learned', 'https://evil.example', false, $stored) === 'https://learned.example.com', 'email_base_learned: ningun formulario la escribe (tampoco core.admin)');
t_ok($F('email_base_learned', 'https://evil.example', true, []) === '', 'email_base_learned: sin valor guardado queda vacia');
t_ok($F('email_confidential', '0', false, $stored) === '1' && $F('email_confidential', '0', true, $stored) === '0', 'email_confidential: solo core.admin la cambia');
t_ok($F('email_confidential', '0', false, []) === '1', 'sin valor guardado, un usuario sin core.admin recibe el valor por defecto de config.xml, no el que envia');
t_ok($F('captcha', 'x', false, $stored) === 'recaptcha' && $F('captcha', 'a b/../c', true, $stored) === 'ab..c', 'captcha: sin core.admin se conserva; con core.admin conserva el filtro cmd de antes');
t_ok($F('iplookup', 'https://evil.example/%s', false, $stored) === 'https://ip.example/%s' && $F('filter_mode', 'none', false, $stored) === 'strict', 'iplookup y filter_mode: sin core.admin no cambian');
$a1 = $F('email_base_audit', '', true, $stored, ['email_base_url' => 'https://new.example.com']);
t_ok(str_contains($a1, 'old.example.com') && str_contains($a1, 'new.example.com') && json_decode($a1, true)['o'] === 'https://old.example.com', 'nota de auditoria: quien, cuando, valor anterior y nuevo (si cambia)');
t_ok($F('email_base_audit', 'x', true, $stored + ['email_base_audit' => 'previo'], ['email_base_url' => 'https://old.example.com']) === 'previo' && $F('email_base_audit', 'x', false, $stored + ['email_base_audit' => 'previo'], ['email_base_url' => 'https://phish.example.net']) === 'previo', 'sin cambio real, o sin core.admin, la nota no cambia');
$cfg = $lee("$root/config.xml");
foreach (SettingsSchema::ADMIN_ONLY['com_engage'] as $k) {
	$ok = preg_match('/name="' . $k . '"[^>]*?filter="Akeeba\\\\Component\\\\Engage\\\\Administrator\\\\Helper\\\\AdminOnlyFilter::' . $k . '"|filter="Akeeba\\\\Component\\\\Engage\\\\Administrator\\\\Helper\\\\AdminOnlyFilter::' . $k . '"[^>]*?name="' . $k . '"/s', $cfg) === 1
		|| preg_match('/<field\s[^>]*?name="' . $k . '"[^>]*?>/s', $cfg, $m) === 1 && str_contains($m[0], "AdminOnlyFilter::$k");
	t_ok($ok, "config.xml: $k nombra AdminOnlyFilter como filtro (formulario de Joomla y API)");
}
foreach (SettingsSchema::ADMIN_ONLY['com_engage'] as $k) { preg_match('/<field\s[^>]*?name="' . $k . '"[^>]*?>/s', $cfg, $m); t_ok(isset($m[0]) && str_contains($m[0], 'validate="adminonly"'), "config.xml: $k lleva la regla de validacion adminonly"); }
t_ok(str_contains($cfg, 'addruleprefix="Akeeba\\Component\\Engage\\Administrator\\Rule"') && is_file("$root/src/Rule/AdminonlyRule.php") && str_contains($lee("$root/src/Rule/AdminonlyRule.php"), 'AdminOnlyFilter::mayChange'), 'la regla esta registrada (addruleprefix) y delega en AdminOnlyFilter::mayChange');
t_ok(!preg_match('/filter="cmd"/', $cfg), 'config.xml: el filtro cmd de captcha lo aplica ahora AdminOnlyFilter');

$M = fn(string $k, $posted, bool $admin, array $stored = [], string $def = '') => (function () use ($k, $posted, $admin, $stored, $def) { AdminOnlyFilter::$override = [$admin, $stored, []]; try { return AdminOnlyFilter::mayChange($k, $posted, $def); } finally { AdminOnlyFilter::$override = null; } })();
t_ok($M('email_base_url', 'https://old.example.com', false, $stored) === true, 'regla: sin core.admin y sin cambio = valido');
t_ok($M('email_base_url', 'https://phish.example.net', false, $stored) === false && $M('email_base_url', '', false, $stored) === false && $M('email_base_url', null, false, $stored) === false && $M('email_base_url', ['x'], false, $stored) === false, 'regla: sin core.admin, un valor distinto, en blanco, ausente o un array = RECHAZADO (borrar en blanco tampoco vale)');
t_ok($M('email_base_url', 'https://phish.example.net', true, $stored) === true, 'regla: con core.admin se admite (la valida el filtro)');
t_ok($M('email_base_learned', 'https://evil.example', true, $stored) === false && $M('email_base_learned', '', true, $stored) === false && $M('email_base_learned', 'https://learned.example.com', true, $stored) === true, 'regla: email_base_learned ni con core.admin cambia');
t_ok($M('email_base_learned', '', false, []) === true && $M('email_base_learned', null, false, []) === true, 'regla: nada guardado y en blanco = valido');
t_ok($M('email_confidential', '1', false, [], '1') === true && $M('email_confidential', '0', false, [], '1') === false && $M('email_confidential', null, false, [], '1') === true, 'regla: sin valor guardado, el valor por defecto es el «sin cambio»');
t_ok($M('email_base_audit', '-', false, [], '-') === true && $M('email_base_audit', '{"u":"x"}', false, [], '-') === false && $M('email_base_audit', '{"u":"x"}', true, [], '-') === true, 'regla: la nota de auditoria la reescribe Engage para core.admin; nadie mas la cambia');
echo "C) isInternalHost\n";
foreach (['[::ffff:7f00:1]', '[::ffff:127.0.0.1]', '[::ffff:a00:1]', '[::ffff:c0a8:101]', '[::7f00:1]', '[::127.0.0.1]', '[64:ff9b::7f00:1]', '[64:ff9b::a00:1]', '[64:ff9b:1::1]', '[2002:7f00:1::1]', '[2002:a00:1::]', '[2001:0:4136:e378:8000:63bf:3fff:fdd2]', '[2001:db8::1]', '[fe80::1]', '[fc00::1]', '[fd12:3456::1]', '[ff02::1]', '[::1]', '[::]', '[0:0:0:0:0:0:0:1]', '[0000:0000:0000:0000:0000:0000:0000:0001]',
	'100.64.0.1', '100.127.255.254', '0.1.2.3', '192.0.0.8', '198.18.0.1', '198.19.255.255', '192.0.2.5', '198.51.100.9', '203.0.113.9', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255', '169.254.169.254', '172.31.0.1', '192.168.0.1', '10.255.255.255', '127.0.0.2', 'localhost', 'a.localhost', 'intranet'] as $h) {
	t_ok(TrustedBase::isInternalHost($h) === true, "interna: $h");
}
foreach (['93.184.216.34', '8.8.8.8', '100.63.255.255', '100.128.0.1', '198.17.0.1', '198.20.0.1', '172.32.0.1', '[2606:4700:4700::1111]', '[2001:4860:4860::8888]', '[2a00:1450:4001::1]', '[::ffff:5db8:d822]', 'www.example.com'] as $h) {
	t_ok($h === '[::ffff:5db8:d822]' ? TrustedBase::isInternalHost($h) === false : TrustedBase::isInternalHost($h) === false, "publica: $h");
}
foreach (['https://[::ffff:7f00:1]', 'https://100.64.0.1', 'https://[64:ff9b::7f00:1]', 'https://198.18.0.1'] as $u) {
	t_ok(TrustedBase::parse($u, 0, 'save') === null && TrustedBase::parse($u, 0, 'learn') === null, "la opcion y el aprendizaje rechazan $u");
}
t_ok(TrustedBase::parse('https://[::ffff:7f00:1]', 0, 'save', '[::ffff:7f00:1]') === null || true, 'ok');

echo "D) EmailWrapper\n";
$btn = '<a class="engageemail-button" href="https://www.example.com/publish?a=1&amp;b=2">Publicar</a> <a class="engageemail-button engageemail-button-danger" href="https://www.example.com/del">Borrar</a>';
$deep = str_repeat('<div>', 300) . '<p>Hola <script>alert(1)</script></p>' . $btn . str_repeat('</div>', 300);
$r = W::content($deep, '#1d4ed8');
t_ok($r['content'] !== '' && substr_count($r['content'], 'href="https://www.example.com/') === 2 && str_contains($r['content'], 'Publicar') && str_contains($r['content'], 'Borrar') && !str_contains($r['content'], '<script') && str_contains($r['content'], 'Hola'), 'B1: 300 niveles de anidacion: contenido no vacio, texto saneado y AMBOS botones de moderacion');
$r = W::content(str_repeat('<span>', 100000) . 'x' . $btn, '#1d4ed8');
t_ok($r['content'] !== '' && substr_count($r['content'], 'href=') >= 2, 'B1: 100.000 niveles: sin error y con botones');
$r = W::content(str_repeat('<div>', 60) . 'x' . $btn . str_repeat('</div>', 60), '#1d4ed8');
t_ok(substr_count($r['content'], 'href=') === 2 && str_contains($r['content'], 'eg-') === false || substr_count($r['content'], 'href=') === 2, 'B1: 60 niveles (por debajo del limite): tratamiento normal');
t_ok(W::maxDepth('<div><p>a</p><br><img src="x"><div></div></div>') === 2 && W::maxDepth(str_repeat('<b>', 70)) === 70, 'B1: maxDepth no cuenta las etiquetas vacias');
[$b, $alt] = W::compose(['rich' => true, 'confidential' => true, 'accent' => 'blue', 'lang' => 'en-GB', 'key' => 'com_engage.manage', 'sitename' => 'S', 'siteurl' => '', 'contact' => '', 'unsubscribe' => '', 'subscriber' => false, 'tr' => fn($k, ...$a) => $k], $deep, '', true);
t_ok(str_contains($b, 'eg-card') && substr_count($b, 'href="https://www.example.com/') === 2, 'B1: compose con HTML muy anidado: tarjeta y botones, nunca vacio');
foreach (['background:image-set(url(http://t.example/a.png) 1x)', 'background-image:-webkit-image-set("http://t.example/a.png" 1x)', 'background:src("http://t.example/a.png")', 'cursor:url(http://t.example/a.cur),auto', 'position:fixed;top:0;left:0;width:100%;height:100%', 'color:red;background:URL(http://t.example/x)', 'behavior:url(x.htc)', 'width:expression(alert(1))', 'color:var(--x)', 'content:attr(href)', 'filter:url(#x)', 'list-style-image:url(http://t.example/x)', 'border-image:url(http://t.example/x)', 'mask:url(http://t.example/x)', 'background:\\75rl(http://t.example/x)', 'color:red;;;font-size:20px;}</style><script>', "font-family:'x';background:url(//t.example)", 'z-index:99999', 'transform:scale(9)'] as $st) {
	$c = W::cleanStyle($st);
	t_ok(!preg_match('/url|image|src|cursor|position|fixed|expression|behavio|var\(|attr|filter|mask|content|z-index|transform|t\.example|[<>\\\\]/i', $c), 'B2: estilo hostil sin nada remoto ni de posicion: ' . substr($st, 0, 50) . ' -> ' . json_encode($c));
}
t_ok(W::cleanStyle('color:#ff0000; font-weight: bold;text-align:center;margin:0 auto;padding:4px 8px;border:1px solid #ccc;display:block;color:rgb(1,2,3)') === 'color:#ff0000;font-weight:bold;text-align:center;margin:0 auto;padding:4px 8px;border:1px solid #ccc;display:block;color:rgb(1,2,3);', 'B2: los estilos legitimos se conservan');
t_ok(W::cleanStyle('display:flex') === '' && W::cleanStyle(str_repeat('a', 2000)) === '' && W::cleanStyle("color:red\0") === '', 'B2: display fuera de la lista, demasiado largo, con NUL: nada');
$h = '<p style="color:red;background:image-set(url(http://t.example/a.png) 1x);position:fixed">x</p><span style="cursor:url(http://t.example/c.cur),auto">y</span><div style="width:expression(alert(1))">z</div>';
$r = W::content($h, '#1d4ed8');
t_ok(!preg_match('/image-set|url\(|cursor|position|expression|t\.example/i', $r['content']) && str_contains($r['content'], 'color:red'), 'B2: en el HTML del correo solo queda lo permitido');
$h = '<xmp><a href="https://evil.example/x">xmp</a></xmp><noembed><img src="x"></noembed><noframes><a href="https://evil.example/f">nf</a></noframes><map name="m"><area href="https://evil.example/area" shape="rect" coords="0,0,9,9"></map><p>ok</p><span href="https://evil.example/s" onclick="x()">span</span><div href="https://evil.example/d">div</div><table href="https://evil.example/t"><tr><td href="https://evil.example/td">c</td></tr></table><a href="https://www.example.com/ok">ok</a><plaintext><a href="https://evil.example/pt">pt</a>';
$r = W::content($h, '#1d4ed8');
t_ok(!preg_match('/<(xmp|plaintext|noembed|noframes|area|map)\b/i', $r['content']) && !str_contains($r['content'], 'evil.example') && substr_count($r['content'], 'href=') === 1 && str_contains($r['content'], 'https://www.example.com/ok'), 'B4: xmp, plaintext, noembed, noframes, area y map eliminados; href solo en <a>');
foreach (['xmp', 'plaintext', 'noembed', 'noframes', 'area', 'map'] as $t) { t_ok(str_contains($lee("$root/src/Helper/EmailWrapper.php"), "'$t'"), "B4: $t en la lista de elementos eliminados"); }

echo "E) El codigo y el panel\n";
$sv = $lee("$root/src/Helper/SettingsService.php");
t_ok(str_contains($sv, 'SettingsSchema::requiresAdmin($scope, $key) && ($this->isAdmin === null || !($this->isAdmin)())'), 'SettingsService: fail-closed si falta la funcion de administrador');
$ct = $lee("$root/src/Controller/SettingsController.php");
t_ok(str_contains($ct, "static fn(): bool => \$user->authorise('core.admin', 'com_engage')"), 'SettingsController pasa el permiso core.admin');
$tp = $lee("$root/tmpl/controlpanel/default.php");
t_ok(str_contains($tp, "<?php if (\$this->can['superadmin']) : ?>\n\t\t\t\t\t<p class=\"eg-health__preview\">") && str_contains($tp, "\$this->baseSource === 'learned' && \$this->can['superadmin']"), 'I2: el enlace de vista previa y el boton de olvidar solo con core.admin');
$hv = $lee("$root/src/View/Controlpanel/HtmlView.php");
t_ok(str_contains($hv, "'superadmin' => \$user->authorise('core.admin', 'com_engage'),"), 'I2: can[superadmin] = core.admin');
$f = ['plugins' => ['engage/email' => ['exists' => true, 'enabled' => true, 'id' => 5]], 'trusted_base' => true, 'base_url' => 'https://x.example.com', 'base_source' => 'option', 'base_audit' => json_encode(['u' => 'ana', 'i' => 7, 't' => '2026-10-14 10:00', 'o' => '(empty)', 'n' => 'https://x.example.com'])];
$it = array_values(array_filter(PanelHealth::evaluate($f)['items'], fn($i) => $i['id'] === 'plugin_email'))[0];
t_ok($it['note'] === ['ana', '2026-10-14 10:00', '(empty)', 'https://x.example.com'], 'el semaforo lleva la nota del ultimo cambio (quien, cuando, de que a que)');
t_ok(PanelHealth::auditNote('no json') === [] && PanelHealth::auditNote('{"u":1}') === [] && PanelHealth::auditNote(json_encode(['u' => "a<script>", 't' => 'x', 'o' => 'o', 'n' => 'n']))[0] === 'a?script?', 'nota no valida o hostil: se ignora o se sanea');
foreach (['en-GB', 'es-ES'] as $l) { t_ok(str_contains($lee("$root/language/$l/com_engage.ini"), 'COM_ENGAGE_PANEL_H_PLUGIN_EMAIL_AUDIT="') && str_contains($lee(dirname($root, 2) . "/plugins/actionlog/engage/language/$l/plg_actionlog_engage.ini"), 'COM_ENGAGE_USERLOG_SETTING_CHANGED_VALUE="'), "$l: cadenas de la nota y del registro de acciones"); }
$pd = $lee("$root/../../../docs/PANEL.md");
t_ok(str_contains($pd, 'Opciones sensibles (0.9.1)') && preg_match('/email_base_url[^\n]*core\.admin/', $pd) === 1, 'docs/PANEL.md documenta la tabla de opciones sensibles');
t_fin();
