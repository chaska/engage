<?php
/**
 * 0.9.1 contra el Joomla REAL: opciones sensibles (core.admin, no solo core.options). Un grupo Administrator con `core.options` y SIN `core.admin` sobre
 * com_engage (el PoC del revisor: t9/t10) no puede cambiar email_base_url, email_base_learned ni las demas opciones sensibles, ni por el panel moderno
 * (settings.save) ni por el formulario de Opciones de Joomla (com_config); un Super User si (salvo lo que solo escribe Engage); el cambio queda anotado.
 * Restaura las reglas de acceso, los parametros y los usuarios al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
usuario('r3adm', 7);
$rules0  = one("SELECT rules FROM jos_assets WHERE name='com_engage'");
$params0 = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$mails0  = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_mails'");
register_shutdown_function(function () use ($rules0, $params0, $mails0) {
	q("UPDATE jos_assets SET rules='" . esc((string) $rules0) . "' WHERE name='com_engage'");
	q("UPDATE jos_extensions SET params='" . esc((string) $params0) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc((string) $mails0) . "' WHERE element='com_mails'");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%'"); borraUsuarios();
});
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mail_style' => 'both', 'disable_htmllayout' => '0'])) . "' WHERE element='com_mails'");
@mkdir("$WORK/tmp");
$AP = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
$par = fn() => json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: [];
$setp = function (array $x) use ($par) { q("UPDATE jos_extensions SET params='" . esc(json_encode(array_merge($par(), $x))) . "' WHERE element='com_engage' AND type='component'"); usleep(300000); };
$setp(['email_base_url' => '', 'email_base_learned' => 'https://learned.example.com', 'email_confidential' => '1', 'email_rich' => '1', 'captcha' => '', 'iplookup' => 'https://ip.example/%s', 'filter_mode' => 'strict', 'email_base_audit' => '-']);
q("UPDATE jos_assets SET rules='" . esc('{"core.options":{"7":1}}') . "' WHERE name='com_engage'");
$adm = new Cliente($BASE, "$WORK/tmp", 'o91adm'); $okA = $adm->loginAdmin('r3adm', $PASS);
$sup = new Cliente($BASE, "$WORK/tmp", 'o91sup'); $okS = $sup->loginAdmin('admintest', $AP);
r('S-00', 'Sesiones: Administrator con core.options (sin core.admin) y Super User', $okA && $okS);
$tokOf = fn(Cliente $c) => preg_match('/"csrf\.token":"([0-9a-f]{32})"/', $c->get('/administrator/index.php?option=com_engage&view=settings')['body'], $m) ? $m[1] : '';
$api = fn(Cliente $c, string $k, string $v) => $c->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => $k, 'value' => $v, $tokOf($c) => 1]);
$ts = $tokOf($adm);
r('S-01', 'El grupo con solo core.options abre la pantalla de ajustes (tiene token)', $ts !== '');
// ---- panel moderno
$casos = ['email_base_url' => 'https://phish.example.net', 'javascript' => null, 'email_confidential' => '0', 'iplookup' => 'https://evil.example/%s', 'filter_mode' => 'none', 'captcha' => '-1'];
unset($casos['javascript']);
$antes = $par(); $mal = [];
foreach ($casos as $k => $v) { $x = $api($adm, $k, $v); if ($x['code'] !== 403 || ($par()[$k] ?? null) !== ($antes[$k] ?? null)) { $mal[] = "$k={$x['code']}"; } }
r('S-02', 'Panel moderno, core.options sin core.admin: email_base_url, email_confidential, iplookup, filter_mode y captcha = 403 y no cambian (PoC t9)', !$mal, implode(',', $mal));
$x = $api($adm, 'email_rich', '0'); $v1 = $par()['email_rich'] ?? '?'; $api($adm, 'email_rich', '1');
r('S-03', 'Panel moderno: una opcion no sensible (email_rich) si la cambia con core.options', $x['code'] === 200 && $v1 === '0', "HTTP {$x['code']} valor=$v1");
$x = $api($adm, 'email_base_learned', 'https://evil.example.com');
r('S-04', 'Panel moderno: email_base_learned no se puede escribir (ni siquiera por core.admin: 422/403) y no cambia', in_array($x['code'], [403, 422], true) && ($par()['email_base_learned'] ?? '') === 'https://learned.example.com', "HTTP {$x['code']}");
$xs = $api($sup, 'email_base_learned', 'https://evil.example.com');
r('S-05', 'Panel moderno: ni el Super User escribe email_base_learned', $xs['code'] === 422 && ($par()['email_base_learned'] ?? '') === 'https://learned.example.com', "HTTP {$xs['code']}");
// ---- formulario de Opciones de Joomla (com_config)
$form = $adm->get('/administrator/index.php?option=com_config&view=component&component=com_engage');
preg_match('/name="([0-9a-f]{32})" value="1"/', $form['body'], $tm); $tk = $tm[1] ?? '';
$envia = function (Cliente $c, string $tk, array $jform) { return $c->post('/administrator/index.php?option=com_config&task=component.save', ['id' => '252', 'component' => 'com_engage', 'return' => '', 'task' => 'component.save', $tk => 1, 'jform' => $jform]); };
$id = (int) one("SELECT extension_id FROM jos_extensions WHERE element='com_engage' AND type='component'");
$base = ['email_base_url' => 'https://phish.example.net', 'email_base_learned' => 'https://evil.example.com', 'email_confidential' => '0', 'iplookup' => 'https://evil.example/%s', 'filter_mode' => 'none', 'captcha' => 'evil', 'email_rich' => '0', 'default_publish' => '1'];
$setp(['email_base_url' => 'https://opt.example.com', 'email_base_audit' => '-']);
$antes = $par();
$x = $adm->post('/administrator/index.php?option=com_config&task=component.save', ['id' => (string) $id, 'component' => 'com_engage', 'return' => '', $tk => 1, 'jform' => $base]);
$d = $par();
r('S-06', 'com_config, core.options sin core.admin: un envio que cambia email_base_url, email_base_learned, email_confidential, iplookup, filter_mode o captcha se RECHAZA entero y no cambia nada (PoC: el formulario de Opciones)', $d == $antes, 'HTTP ' . $x['code'] . ' ' . json_encode(array_diff_assoc($d, $antes)));
$vacio = $base; foreach (['email_base_url', 'email_base_learned', 'iplookup', 'filter_mode', 'captcha', 'email_confidential'] as $k) { unset($vacio[$k]); }
$x = $adm->post('/administrator/index.php?option=com_config&task=component.save', ['id' => (string) $id, 'component' => 'com_engage', 'return' => '', $tk => 1, 'jform' => $vacio + ['email_base_url' => '', 'email_base_learned' => '']]);
$d = $par();
r('S-06b', 'com_config, core.options sin core.admin: borrar en blanco (o no enviar) email_base_url, email_base_learned y las demas sensibles tampoco funciona: rechazado y sin cambios', $d == $antes, 'HTTP ' . $x['code'] . ' ' . json_encode(array_diff_assoc($d, $antes)));
$honesto = ['email_base_audit' => '-', 'email_base_url' => 'https://opt.example.com', 'email_base_learned' => 'https://learned.example.com', 'email_confidential' => '1', 'iplookup' => 'https://ip.example/%s', 'filter_mode' => 'strict', 'captcha' => '', 'email_rich' => '0', 'default_publish' => '1'];
$x = $adm->post('/administrator/index.php?option=com_config&task=component.save', ['id' => (string) $id, 'component' => 'com_engage', 'return' => '', $tk => 1, 'jform' => $honesto]);
$d = $par();
r('S-07', 'com_config, core.options sin core.admin: el envio honesto (las sensibles tal como estan) si se guarda: email_rich = 0 y las sensibles intactas', ($d['email_rich'] ?? '') === '0' && ($d['email_base_url'] ?? '') === 'https://opt.example.com' && ($d['email_base_learned'] ?? '') === 'https://learned.example.com' && ($d['filter_mode'] ?? '') === 'strict', 'HTTP ' . $x['code'] . ' ' . json_encode($d));
// el correo no lleva el dominio del atacante (el envio de Opciones sin los demas campos borra parametros: se ponen los de pruebas)
$setp(['max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100', 'reactions_enabled' => '0', 'sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0', 'default_publish' => '1', 'comments_enabled' => '1', 'min_length' => '1', 'max_length' => '2000', 'email_base_url' => 'https://phish.example.net/x-no-valido', 'email_confidential' => '1', 'email_rich' => '1', 'comments_notify_author' => '1']);
$setp(['email_base_url' => '']);
$antesM = glob("$WORK/mails/*.eml") ?: [];
q("UPDATE jos_assets SET rules='" . esc((string) $rules0) . "' WHERE name='com_engage'"); usleep(300000); // las reglas de pruebas (solo core.options) quitan el permiso de comentar a los invitados
$c = new Cliente($BASE, "$WORK/tmp", 'o91g'); $p = $c->get($PAGE); $tkc = Cliente::token($p['body']);
$c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode('/'), $tkc => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'name' => 'R3N-O91', 'email' => 'o91@example.invalid', 'body' => 'hola']]);
usleep(1800000); $txt = ''; q("UPDATE jos_assets SET rules='" . esc('{"core.options":{"7":1}}') . "' WHERE name='com_engage'");
foreach (array_diff(glob("$WORK/mails/*.eml") ?: [], $antesM) as $f) { $txt .= (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f)); }
r('S-08', 'Tras los intentos, los correos no llevan ningun enlace al dominio del atacante (phish.example.net / evil.example.com)', $txt !== '' && !str_contains($txt, 'phish.example.net') && !str_contains($txt, 'evil.example.com'), 'len=' . strlen($txt));
// ---- Super User por com_config
$setp(['email_base_url' => '', 'email_base_learned' => 'https://learned.example.com', 'email_base_audit' => '-']);
$form = $sup->get('/administrator/index.php?option=com_config&view=component&component=com_engage');
preg_match('/name="([0-9a-f]{32})" value="1"/', $form['body'], $tm2); $tk2 = $tm2[1] ?? '';
$x = $sup->post('/administrator/index.php?option=com_config&task=component.save', ['id' => (string) $id, 'component' => 'com_engage', 'return' => '', $tk2 => 1, 'jform' => ['email_base_audit' => '-', 'email_base_url' => 'https://admin-ok.example.com/', 'email_base_learned' => 'https://learned.example.com', 'email_confidential' => '0', 'email_rich' => '1']]);
$d = $par(); $audit = json_decode((string) ($d['email_base_audit'] ?? ''), true) ?: []; if (getenv('DEPURA')) { fwrite(STDERR, json_encode($d) . "\n"); }
r('S-09', 'com_config, Super User: email_base_url se guarda validada y normalizada y email_confidential se cambia', ($d['email_base_url'] ?? '') === 'https://admin-ok.example.com' && ($d['email_base_learned'] ?? '') === 'https://learned.example.com' && ($d['email_confidential'] ?? '1') === '0', 'HTTP ' . $x['code'] . ' ' . json_encode(array_intersect_key($d, array_flip(['email_base_url', 'email_base_learned', 'email_confidential']))));
$x2 = $sup->post('/administrator/index.php?option=com_config&task=component.save', ['id' => (string) $id, 'component' => 'com_engage', 'return' => '', $tk2 => 1, 'jform' => ['email_base_audit' => '-', 'email_base_url' => 'https://admin-ok.example.com', 'email_base_learned' => 'https://evil.example.com', 'email_confidential' => '0']]);
r('S-09b', 'com_config, ni el Super User escribe email_base_learned por el formulario (envio rechazado, queda como estaba)', ($par()['email_base_learned'] ?? '') === 'https://learned.example.com', 'HTTP ' . $x2['code']);
r('S-10', 'La nota de auditoria guarda quien (admintest), de que valor a que valor, saneados', ($audit['u'] ?? '') === 'admintest' && ($audit['o'] ?? '') === '(empty)' && ($audit['n'] ?? '') === 'https://admin-ok.example.com' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $audit['t'] ?? '') === 1, json_encode($audit));
$pp = $sup->get('/administrator/index.php?option=com_engage');
r('S-11', 'El semaforo de los correos muestra la nota del ultimo cambio de la URL base (usuario y valores)', str_contains($pp['body'], 'admintest') && str_contains($pp['body'], 'admin-ok.example.com') && preg_match('/Último cambio de la dirección base|Last change of the base address/', $pp['body']) === 1);
$x = $sup->post('/administrator/index.php?option=com_config&task=component.save', ['id' => (string) $id, 'component' => 'com_engage', 'return' => '', $tk2 => 1, 'jform' => ['email_base_audit' => '-', 'email_base_url' => 'javascript:alert(1)', 'email_base_learned' => 'https://learned.example.com', 'email_confidential' => '0']]);
r('S-12', 'com_config, Super User: una URL base hostil no se guarda (se conserva la anterior)', ($par()['email_base_url'] ?? '') === 'https://admin-ok.example.com', 'valor=' . ($par()['email_base_url'] ?? ''));
// panel moderno, Super User: cambia y deja la nota
$setp(['email_base_url' => '', 'email_base_audit' => '-']);
$x = $api($sup, 'email_base_url', 'https://panel-ok.example.com');
$audit = json_decode((string) ($par()['email_base_audit'] ?? ''), true) ?: [];
r('S-13', 'Panel moderno, Super User: guarda email_base_url y deja la nota de auditoria (usuario, antes y despues)', $x['code'] === 200 && ($par()['email_base_url'] ?? '') === 'https://panel-ok.example.com' && ($audit['u'] ?? '') === 'admintest' && ($audit['n'] ?? '') === 'https://panel-ok.example.com', json_encode($audit));
// I2: el enlace de la vista previa solo con core.admin
$pa = $adm->get('/administrator/index.php?option=com_engage');
$ps = $sup->get('/administrator/index.php?option=com_engage');
r('S-14', 'I2: el enlace «Vista previa de los correos» no aparece con core.options sin core.admin y si con core.admin; la vista da 403 al primero', !str_contains($pa['body'], 'view=emailpreview') && str_contains($ps['body'], 'view=emailpreview') && $adm->get('/administrator/index.php?option=com_engage&view=emailpreview')['code'] === 403, 'panel HTTP ' . $pa['code']);
$ko = count(array_filter($RES, fn($x) => $x['estado'] === 'FALLA')); resumen('s91');
