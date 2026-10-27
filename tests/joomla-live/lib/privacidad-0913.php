<?php
/**
 * 0.9.13 contra el Joomla REAL:
 *   A) retencion de IP y navegador (purga por lotes, valor invalido = 12, el comentario se conserva)
 *   B) bloque de informacion bajo el formulario y enlace a la politica (valido / hostil)
 *   D) Gravatar decide el servidor: invitado sin referencia ni aviso y 403; registrado sin consentimiento 403; consentimiento (CSRF, valores cerrados);
 *      con consentimiento si; revocar; el HTML publico sin hash
 *   E) adopcion de comentarios con correo verificado por activacion: registro + activacion + adopcion; cambio de correo posterior; cuenta creada por
 *      el administrador; sitio sin activacion por correo; cambio de correo ANTES de activar; idempotencia
 * Restaura todo al acabar. Requiere el SMTP de captura ($WORK/mails).
 */
require __DIR__ . '/revision-082-comun.php';
$uid = ['reg' => usuario('r3reg', 2), 'reg2' => usuario('r3reg2', 2)];
$C   = siembra($uid);
$mailDir = "$WORK/mails";
$comOrig = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$grvOrig = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='gravatar' AND folder='engage'");
$usrOrig = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_users' AND type='component'");
register_shutdown_function(function () use ($comOrig, $grvOrig, $usrOrig) {
	q("UPDATE jos_extensions SET params='" . esc($comOrig) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc($grvOrig) . "' WHERE element='gravatar' AND folder='engage'");
	q("UPDATE jos_extensions SET params='" . esc($usrOrig) . "' WHERE element='com_users' AND type='component'");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%' OR body LIKE '%R3B-%' OR email LIKE '%@r3p.invalid'");
	foreach (q("SELECT id FROM jos_users WHERE username LIKE 'r3%'")->fetch_all() as [$id]) { q("DELETE FROM jos_user_profiles WHERE user_id=$id"); }
	borraUsuarios();
});
$setCom = function (array $extra) { $p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: []; q("UPDATE jos_extensions SET params='" . esc(json_encode(array_merge($p, $extra))) . "' WHERE element='com_engage' AND type='component'"); usleep(300000); };
$setUsers = function (array $extra) { $p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_users' AND type='component'"), true) ?: []; q("UPDATE jos_extensions SET params='" . esc(json_encode(array_merge($p, $extra))) . "' WHERE element='com_users' AND type='component'"); };
@mkdir("$WORK/tmp");
$g = new Cliente($BASE, "$WORK/tmp", 'p9g');
$OP = '/index.php?option=com_engage';

// ------------------------------------------------------------------ A) retencion
q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-RT%'");
$viejo = date('Y-m-d H:i:s', strtotime('-13 months')); $medio = date('Y-m-d H:i:s', strtotime('-2 months')); $nuevo = date('Y-m-d H:i:s', strtotime('-2 days'));
for ($i = 0; $i < 1200; $i++) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B-RT viejo $i</p>','R3N-RTV$i','rt$i@r3p.invalid','203.0.113.7','R3UA-VIEJO',1,'$viejo',0)"); }
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B-RT medio</p>','R3N-RTM','rtm@r3p.invalid','203.0.113.8','R3UA-MEDIO',1,'$medio',0)");
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B-RT nuevo</p>','R3N-RTN','rtn@r3p.invalid','203.0.113.9','R3UA-NUEVO',1,'$nuevo',0)");
$lanza = function () use ($g, $PAGE, $setCom) { $setCom(['retention_lastRun' => 0]); $g->get($PAGE); usleep(800000); };
$estado = fn(string $n) => q("SELECT ip, user_agent, body FROM jos_engage_comments WHERE name='$n'")->fetch_assoc();
$setCom(['retention_months' => '12']); $lanza();
$v = $estado('R3N-RTV0'); $v2 = $estado('R3N-RTV1199'); $m = $estado('R3N-RTM'); $n = $estado('R3N-RTN');
r('PV-A1', 'plazo 12: los comentarios de hace 13 meses (1200, por lotes) quedan sin IP ni navegador y con su texto', $v['ip'] === null && $v['user_agent'] === '' && str_contains($v['body'], 'R3B-RT') && $v2['ip'] === null && (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE name LIKE 'R3N-RTV%' AND (ip IS NOT NULL OR user_agent<>'')") === 0);
r('PV-A2', 'los de hace 2 meses y 2 dias conservan IP y navegador con el plazo de 12', $m['ip'] === '203.0.113.8' && $m['user_agent'] === 'R3UA-MEDIO' && $n['ip'] === '203.0.113.9');
$setCom(['retention_months' => '1']); $lanza();
$m = $estado('R3N-RTM'); $n = $estado('R3N-RTN');
r('PV-A3', 'plazo 1: el de hace 2 meses se vacia, el de hace 2 dias no; el comentario sigue', $m['ip'] === null && $m['user_agent'] === '' && str_contains($m['body'], 'R3B-RT medio') && $n['ip'] === '203.0.113.9');
q("UPDATE jos_engage_comments SET ip='203.0.113.7', user_agent='R3UA-VIEJO' WHERE name='R3N-RTV5'");
foreach (['99', '0', 'abc', '-3'] as $bad) { $setCom(['retention_months' => $bad]); q("UPDATE jos_engage_comments SET ip='203.0.113.7', user_agent='R3UA-VIEJO' WHERE name='R3N-RTV5'"); q("UPDATE jos_engage_comments SET ip='203.0.113.8', user_agent='R3UA-MEDIO', created='$medio' WHERE name='R3N-RTM'"); $lanza();
	$mm = $estado('R3N-RTM'); $vv = $estado('R3N-RTV5');
	r('PV-A4', "valor guardado invalido «{$bad}»: se trata como 12 (el de 2 meses se conserva, el de 13 se vacia)", $mm['ip'] === '203.0.113.8' && $vv['ip'] === null); }
$setCom(['retention_months' => '12']);
$pc = (new Cliente($BASE, "$WORK/tmp", 'p9adm')); $pc->loginAdmin('admintest', 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt")));
$cp = $pc->get('/administrator/index.php?option=com_engage&view=controlpanel');
r('PV-A5', 'el panel de control muestra el plazo vigente', $cp['code'] === 200 && preg_match('/eg-health__item[^"]*"[^>]*>.{0,600}12/s', $cp['body']) === 1 && (stripos($cp['body'], 'IP and browser retention') !== false || stripos($cp['body'], 'Conservación de IP') !== false), "HTTP {$cp['code']}");

// ------------------------------------------------------------------ B) informacion y enlace
$blkOf = fn(string $h) => preg_match('#<aside[^>]*akengage-privacy-info.*?</aside>#s', $h, $mb) ? $mb[0] : '';
$pg = $g->get($PAGE)['body'];
r('PV-B1', 'bajo el formulario hay un bloque de informacion (role=note) con el plazo y sin enlace si no esta configurado', str_contains($pg, 'id="akengage-privacy-info"') && str_contains($pg, 'role="note"') && preg_match('/12 (months|meses)/', $blkOf($pg)) === 1 && !str_contains($blkOf($pg), 'href='));
$setCom(['privacy_url' => 'https://www.example.com/?option=com_content&view=article&id=45', 'retention_months' => '6']);
$pg = $g->get($PAGE)['body']; $blk = $blkOf($pg);
r('PV-B2', 'con la politica configurada: enlace https con rel noopener noreferrer nofollow y el plazo de 6 meses', str_contains($blk, 'href="https://www.example.com/?option=com_content&amp;view=article&amp;id=45"') && str_contains($blk, 'rel="noopener noreferrer nofollow"') && preg_match('/6 (months|meses)/', $blk) === 1);
foreach (['javascript:alert(1)', '//evil.example/x', 'http://insegura.example/p', '/a"onmouseover=alert(1)', 'https://u:p@evil.example/'] as $hostil) {
	$setCom(['privacy_url' => $hostil]); $pg = $g->get($PAGE)['body']; $blk = $blkOf($pg);
	r('PV-B3', 'valor hostil guardado ' . json_encode($hostil) . ': el enlace NO sale', str_contains($pg, 'akengage-privacy-info') && !str_contains($blk, 'href=') && !str_contains($blk, 'evil.example') && !str_contains($blk, 'onmouseover'));
}
$setCom(['privacy_url' => '', 'retention_months' => '12']);

// ------------------------------------------------------------------ D) Gravatar: decide el servidor
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mode' => 'ask', 'rating' => 'g', 'default_image' => 'identicon'])) . "' WHERE element='gravatar' AND folder='engage'");
$reg = new Cliente($BASE, "$WORK/tmp", 'p9reg'); $reg->login('r3reg', $PASS); $reg2 = new Cliente($BASE, "$WORK/tmp", 'p9reg2'); $reg2->login('r3reg2', $PASS);
$tk = fn(Cliente $c) => ($j = json_decode($c->get("$OP&task=avatar.token")['body'], true)) && isset($j['token']) ? $j['token'] : '';
$hg = $g->get($PAGE)['body']; $hr = $reg->get($PAGE)['body'];
r('PV-D1', 'ask: la pagina de un INVITADO no lleva referencia diferida ni aviso (solo avatar generico)', !str_contains($hg, 'data-engage-gravatar='));
r('PV-D2', 'ask: la pagina de un REGISTRADO si lleva la referencia (opaca, sin hash ni correo)', preg_match('/data-engage-gravatar="[1-9][0-9]*\.48\.[0-9a-f]{32}"/', $hr) === 1 && !preg_match('/gravatar\.com\/avatar|[0-9a-f]{64}|r3mail/i', $hr));
preg_match('/data-engage-gravatar="([^"]+)"/', $hr, $mr); $ref = $mr[1] ?? '';
$resolve = function (Cliente $c, string $ref, ?string $token = null) use ($OP, $tk) { $token = $token ?? $tk($c); $x = $c->post("$OP&task=avatar.resolve", ['c' => $ref, $token => 1]); return [$x['code'], json_decode($x['body'], true)]; };
[$cg, $jg] = $resolve($g, $ref);
r('PV-D3', 'INVITADO con token CSRF valido: avatar.resolve = 403 login, ninguna URL', $cg === 403 && ($jg['error'] ?? '') === 'login' && !isset($jg['urls']), "HTTP $cg");
[$c0, $j0] = $resolve($reg2, $ref);
r('PV-D4', 'REGISTRADO sin consentimiento guardado: 403 consent, ninguna URL', $c0 === 403 && ($j0['error'] ?? '') === 'consent' && !isset($j0['urls']), "HTTP $c0");
$cx = $g->post("$OP&task=avatar.consent", ['grant' => '1', $tk($g) => 1]);
r('PV-D5', 'avatar.consent de un INVITADO: 403', $cx['code'] === 403, "HTTP {$cx['code']}");
$cy = $reg2->post("$OP&task=avatar.consent", ['grant' => '1']);
r('PV-D6', 'avatar.consent sin token CSRF: 403 y no se guarda nada', $cy['code'] === 403 && (int) one("SELECT COUNT(*) FROM jos_user_profiles WHERE user_id={$uid['reg2']} AND profile_key='engagegravatar.consent'") === 0, "HTTP {$cy['code']}");
$cz = $reg2->get("$OP&task=avatar.consent&grant=1&" . $tk($reg2) . '=1');
r('PV-D7', 'avatar.consent por GET: 405', $cz['code'] === 405, "HTTP {$cz['code']}");
foreach (['2', 'si', '', '1;DROP'] as $mal) { $cw = $reg2->post("$OP&task=avatar.consent", ['grant' => $mal, $tk($reg2) => 1]); r('PV-D8', 'avatar.consent con grant=' . json_encode($mal) . ': 400', $cw['code'] === 400 && (int) one("SELECT COUNT(*) FROM jos_user_profiles WHERE user_id={$uid['reg2']} AND profile_key='engagegravatar.consent'") === 0, "HTTP {$cw['code']}"); }
$ok1 = $reg2->post("$OP&task=avatar.consent", ['grant' => '1', $tk($reg2) => 1]);
r('PV-D9', 'avatar.consent grant=1 de un registrado: se guarda en el servidor, ligado a su cuenta', $ok1['code'] === 200 && one("SELECT profile_value FROM jos_user_profiles WHERE user_id={$uid['reg2']} AND profile_key='engagegravatar.consent'") === '1');
[$c1, $j1] = $resolve($reg2, $ref);
$u = (array) ($j1['urls'] ?? []); $url = $u[implode('.', array_slice(explode('.', $ref), 0, 2))] ?? null;
r('PV-D10', 'con sesion y consentimiento guardado: avatar.resolve entrega la URL de Gravatar (lista blanca)', $c1 === 200 && is_string($url) && preg_match('#^https://www\.gravatar\.com/avatar/[0-9a-f]{32,64}\?#', $url) === 1, "HTTP $c1");
[$c2, $j2] = $resolve($reg, $ref);
r('PV-D11', 'otra cuenta registrada SIN consentimiento sigue sin obtener nada (el consentimiento es de cada cuenta)', $c2 === 403 && ($j2['error'] ?? '') === 'consent');
$rv = $reg2->post("$OP&task=avatar.consent", ['grant' => '0', $tk($reg2) => 1]);
[$c3, $j3] = $resolve($reg2, $ref);
r('PV-D12', 'revocar (grant=0): se borra y avatar.resolve vuelve a 403 consent', $rv['code'] === 200 && $c3 === 403 && ($j3['error'] ?? '') === 'consent' && (int) one("SELECT COUNT(*) FROM jos_user_profiles WHERE user_id={$uid['reg2']} AND profile_key='engagegravatar.consent'") === 0);
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mode' => 'off', 'rating' => 'g', 'default_image' => 'identicon'])) . "' WHERE element='gravatar' AND folder='engage'");
r('PV-D13', 'modo off: ni referencia ni mencion de Gravatar en la pagina de un registrado', !str_contains($reg->get($PAGE)['body'], 'data-engage-gravatar'));

// ------------------------------------------------------------------ E) adopcion con correo verificado
$setUsers(['allowUserRegistration' => '1', 'useractivation' => '1']);
$leeMails = function (array $antes) use ($mailDir) { usleep(1500000); $out = []; foreach (array_diff(glob("$mailDir/*.eml") ?: [], $antes) as $f) { $out[] = (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f)) . "\n" . (string) file_get_contents($f); } return implode("\n=====\n", $out); };
$invitado = function (string $email, string $tag) use ($ASSET_PUB, $PAGE, $BASE, $WORK, $OP) { $c = new Cliente($BASE, "$WORK/tmp", 'p9i' . $tag); $tkn = Cliente::token($c->get($PAGE)['body']); $c->post("$OP&task=comment.save", ['returnurl' => base64_encode('/'), $tkn => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'name' => "R3N-AD-$tag", 'email' => $email, 'body' => "R3B-AD-$tag comentario de invitado"]]); return (int) one("SELECT id FROM jos_engage_comments WHERE name='R3N-AD-$tag' ORDER BY id DESC LIMIT 1"); };
$registro = function (string $user, string $email) use ($BASE, $WORK, $PASS, $leeMails, $mailDir) {
	$c = new Cliente($BASE, "$WORK/tmp", 'p9r' . $user); $p = $c->get('/index.php?option=com_users&view=registration'); $t = Cliente::token($p['body']);
	if ($p['code'] === 301 || $p['code'] === 302) { $p = $c->get($p['loc']); $t = Cliente::token($p['body']); }
	$antes = glob("$mailDir/*.eml") ?: [];
	$x = $c->post('/index.php?option=com_users&task=registration.register', ['jform' => ['name' => $user, 'username' => $user, 'password1' => $PASS, 'password2' => $PASS, 'email1' => $email, 'email2' => $email], $t => 1]);
	$m = $leeMails($antes);
	preg_match('~task=registration\.activate(?:&amp;|&)token=([0-9a-f]{32})~i', $m, $mm);
	return [$c, $mm[1] ?? '', $x['code'], $m];
};
$activar = function (string $token) use ($BASE, $WORK) { $c = new Cliente($BASE, "$WORK/tmp", 'p9act'); return $c->get('/index.php?option=com_users&task=registration.activate&token=' . $token)['code']; };
$login = function (string $user) use ($BASE, $WORK, $PASS) { $c = new Cliente($BASE, "$WORK/tmp", 'p9l' . $user); return $c->login($user, $PASS); };
$dueno = fn(int $cid) => (int) one("SELECT created_by FROM jos_engage_comments WHERE id=$cid");
$uidOf = fn(string $u) => (int) one("SELECT id FROM jos_users WHERE username='$u'");

// E1: registro + activacion + adopcion
$e1 = 'adopta1@r3p.invalid'; $c1 = $invitado($e1, 'E1');
[$cl, $tok, $rc, $mail] = $registro('r3adopta1', $e1);
r('PV-E0', 'el registro crea la cuenta bloqueada con activacion pendiente y llega el correo con el enlace', $uidOf('r3adopta1') > 0 && (int) one("SELECT block FROM jos_users WHERE username='r3adopta1'") === 1 && strlen($tok) === 32, "HTTP $rc");
$id1 = $uidOf('r3adopta1');
r('PV-E1a', 'al registrarse se guarda el correo pendiente; todavia no hay correo verificado', strtolower((string) one("SELECT profile_value FROM jos_user_profiles WHERE user_id=$id1 AND profile_key='engageadopt.pending'")) === $e1 && (int) one("SELECT COUNT(*) FROM jos_user_profiles WHERE user_id=$id1 AND profile_key='engageadopt.verified'") === 0);
$login('r3adopta1');
r('PV-E1b', 'sin activar no puede iniciar sesion y no adopta nada', $dueno($c1) === 0);
$activar($tok);
r('PV-E1c', 'al pulsar el enlace de activacion la cuenta queda activa y el correo VERIFICADO', (int) one("SELECT block FROM jos_users WHERE id=$id1") === 0 && strtolower((string) one("SELECT profile_value FROM jos_user_profiles WHERE user_id=$id1 AND profile_key='engageadopt.verified'")) === $e1 && (int) one("SELECT COUNT(*) FROM jos_user_profiles WHERE user_id=$id1 AND profile_key='engageadopt.pending'") === 0);
$ok = $login('r3adopta1');
r('PV-E1d', 'al iniciar sesion se adopta el comentario de invitado (sin nombre ni correo guardados)', $ok && $dueno($c1) === $id1 && one("SELECT email FROM jos_engage_comments WHERE id=$c1") === null);
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'R3B-AD-E1b otro','R3N-AD-E1b','$e1','198.51.100.77','R3UA',1,'2026-09-02 10:00:00',0)"); $c1b = (int) $db->insert_id; $login('r3adopta1'); $d = $dueno($c1b);
r('PV-E1e', 'otro comentario de invitado con el mismo correo se adopta en el siguiente inicio de sesion (el correo sigue verificado)', $d === $id1, "c1b=$c1b dueno=$d id1=$id1");
$antesDueno = $dueno($c1); q("UPDATE jos_engage_comments SET created_by=$antesDueno WHERE id=$c1"); $login('r3adopta1');
r('PV-E1f', 'idempotente: iniciar sesion otra vez no cambia nada', $dueno($c1) === $id1 && $dueno($c1b) === $id1);

// E2: cambio de correo posterior
$e2 = 'cambia@r3p.invalid'; $c2 = $invitado($e2, 'E2'); $victima = 'victima@r3p.invalid'; $c2v = $invitado($victima, 'E2v');
[$cl, $tok, , ] = $registro('r3adopta2', $e2); $id2 = $uidOf('r3adopta2'); $activar($tok);
q("UPDATE jos_users SET email='$victima' WHERE id=$id2");
$login('r3adopta2');
r('PV-E2', 'el usuario cambia su correo DESPUES de verificar: con el correo nuevo (de otra persona) no se adopta nada', $dueno($c2v) === 0 && $dueno($c2) === 0);
q("UPDATE jos_users SET email='$e2' WHERE id=$id2"); $login('r3adopta2');
r('PV-E2b', 'si vuelve a su correo verificado, adopta el suyo (nunca el de la victima)', $dueno($c2) === $id2 && $dueno($c2v) === 0);

// E3: cuenta creada por el administrador (sin activacion)
$e3 = 'admincreada@r3p.invalid'; $c3 = $invitado($e3, 'E3');
$id3 = usuario('r3admincre', 2); q("UPDATE jos_users SET email='$e3' WHERE id=$id3");
$login('r3admincre');
r('PV-E3', 'cuenta creada por un administrador con el correo de un comentario de invitado: NO adopta', $dueno($c3) === 0);

// E4: cuenta anterior (activada antes de actualizar: sin claves)
$e4 = 'anterior@r3p.invalid'; $c4 = $invitado($e4, 'E4');
$id4 = usuario('r3anterior', 2); q("UPDATE jos_users SET email='$e4', activation='', block=0 WHERE id=$id4");
$login('r3anterior');
r('PV-E4', 'cuenta ya activada antes de esta version (sin correo verificado guardado): NO adopta', $dueno($c4) === 0);

// E5: cambio de correo ANTES de activar
$e5 = 'antesactivar@r3p.invalid'; $vic5 = 'victima5@r3p.invalid'; $c5 = $invitado($vic5, 'E5');
[$cl, $tok, , ] = $registro('r3adopta5', $e5); $id5 = $uidOf('r3adopta5');
q("UPDATE jos_users SET email='$vic5' WHERE id=$id5");
$activar($tok);
r('PV-E5a', 'ataque: cambiar el correo antes de activar: la activacion NO deja correo verificado', (int) one("SELECT COUNT(*) FROM jos_user_profiles WHERE user_id=$id5 AND profile_key='engageadopt.verified'") === 0);
$login('r3adopta5');
r('PV-E5b', 'ataque: tras activar con el correo de la victima, no adopta su comentario', $dueno($c5) === 0);

// E6: sitio sin activacion por correo
$setUsers(['useractivation' => '0']);
$e6 = 'sinactivacion@r3p.invalid'; $c6 = $invitado($e6, 'E6');
[$cl, $tok, , ] = $registro('r3adopta6', $e6); $id6 = $uidOf('r3adopta6');
$login('r3adopta6');
r('PV-E6', 'sitio sin activacion por correo: la cuenta se activa sola y NO adopta comentarios de invitado', $id6 > 0 && (int) one("SELECT block FROM jos_users WHERE id=$id6") === 0 && $dueno($c6) === 0 && (int) one("SELECT COUNT(*) FROM jos_user_profiles WHERE user_id=$id6 AND profile_key LIKE 'engageadopt.%'") === 0);
$setUsers(['useractivation' => '1']);

// E7: la opcion own_comments apagada no adopta ni con correo verificado
$pl = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='engage' AND folder='user'");
q("UPDATE jos_extensions SET params='" . esc(json_encode(['own_comments' => 0])) . "' WHERE element='engage' AND folder='user'");
$c7 = $invitado($e1, 'E7'); $login('r3adopta1');
r('PV-E7', 'con own_comments apagada no se adopta nada, aunque el correo este verificado', $dueno($c7) === 0);
q("UPDATE jos_extensions SET params='" . esc($pl) . "' WHERE element='engage' AND folder='user'");
resumen('privacidad-0913');
