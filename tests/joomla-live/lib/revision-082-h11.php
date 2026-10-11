<?php
/**
 * 0.8.4 contra el Joomla REAL (SMTP de captura): la base fiable de los enlaces de los correos sin tocar configuration.php.
 * Prioridad opcion `email_base_url` > live_site > base aprendida (primera visita al panel de control de Engage de un usuario con core.admin).
 * Se usan cabeceras Host distintas (php -S acepta cualquiera): un invitado con Host hostil no cambia ni la base aprendida ni los enlaces.
 * Restaura configuration.php y los parametros de com_engage al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
$cfgPath = "$SITE/configuration.php"; $cfgOrig = file_get_contents($cfgPath);
$mailDir = "$WORK/mails";
$com0 = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$mails0 = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_mails'");
register_shutdown_function(function () use ($cfgPath, $cfgOrig, $com0, $mails0) {
	file_put_contents($cfgPath, $cfgOrig);
	q("UPDATE jos_extensions SET params='" . esc((string) $com0) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc((string) $mails0) . "' WHERE element='com_mails'");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%'"); borraUsuarios();
});
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mail_style' => 'both', 'disable_htmllayout' => '0'])) . "' WHERE element='com_mails'");
usuario('r3adm', 7);
@mkdir("$WORK/tmp");
$AP = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
$com = fn() => json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: [];
$setCom = function (array $extra) use ($com) { $p = array_merge($com(), $extra); q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); usleep(300000); };
$aprendida = fn() => (string) ($com()['email_base_learned'] ?? '');
$opcion = fn() => (string) ($com()['email_base_url'] ?? '');
$setCfg = function (string $live, int $force) use ($cfgPath, $cfgOrig) {
	$c = preg_replace("/public \\\$live_site = '[^']*';/", "public \$live_site = '$live';", $cfgOrig);
	$c = preg_replace('/public \$force_ssl = \d+;/', "public \$force_ssl = $force;", $c);
	file_put_contents($cfgPath, $c); usleep(4500000);
};
$login = function (string $user, string $pass, string $host, string $n, bool $ssl = false) use ($BASE, $WORK) { $c = new Cliente($BASE, "$WORK/tmp", $n); $c->cabDefecto = ["Host: $host"] + ($ssl ? [1 => 'X-Engage-Test-Https: 1'] : []); $c->desecurizar = $ssl; return [$c, $c->loginAdmin($user, $pass)]; };
$enviar = function (string $host, string $tag, bool $ssl = false) use ($BASE, $WORK, $PAGE, $ASSET_PUB, $mailDir) {
	$antes = glob("$mailDir/*.eml") ?: [];
	$c = new Cliente($BASE, "$WORK/tmp", 'r3h11' . $tag); $c->cabDefecto = ($host !== '' ? ["Host: $host"] : []) + ($ssl ? [1 => 'X-Engage-Test-Https: 1'] : []); $c->desecurizar = $ssl;
	$p = $c->get($PAGE); $tk = Cliente::token($p['body']);
	$x = $c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode('/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'name' => "R3N-H11-$tag", 'email' => "h11$tag@example.invalid", 'body' => "R3B-H11-$tag"]]);
	usleep(1300000);
	$txt = ''; $n = 0;
	foreach (array_diff(glob("$mailDir/*.eml") ?: [], $antes) as $f) { $n++; $txt .= "\n" . (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f)); }
	preg_match_all('~https?://[^\s"\'<>)]+~i', $txt, $m);
	$urls = array_values(array_filter(array_unique($m[0]), fn($u) => !str_contains($u, 'www.w3.org')));
	return [$n, $urls, $txt];
};
$hosts = fn(array $u) => array_values(array_unique(array_map(fn($x) => strtolower((string) parse_url(html_entity_decode($x), PHP_URL_HOST)) . (($pp = parse_url(html_entity_decode($x), PHP_URL_PORT)) ? ":$pp" : ''), $u)));
$panel = fn(Cliente $c, array $cab = []) => $c->get('/administrator/index.php?option=com_engage', $cab);
$logIni = is_file($PHPLOG) ? filesize($PHPLOG) : 0;
$limpia = function () use ($setCom) { $setCom(['email_base_url' => '', 'email_base_learned' => '']); };

// ---------------------------------------------------------------- sin nada
$setCfg('', 0); $limpia();
[$n, $urls, $txt] = $enviar('evil.test:8080', 'a');
r('B84-01', 'Sin opcion, sin live_site y nada aprendido, un invitado con Host hostil comenta: el correo sale SIN enlaces ni token y no se aprende nada', $n > 0 && $urls === [] && !preg_match('~token=[0-9a-f]{64}~', $txt) && !str_contains($txt, 'evil.test') && $aprendida() === '', "correos=$n aprendida=" . $aprendida());
$anon = new Cliente($BASE, "$WORK/tmp", 'r3h11anon'); $anon->cabDefecto = ['Host: evil.test:8080'];
$anon->get('/administrator/index.php?option=com_engage'); $anon->get('/index.php?option=com_engage&view=controlpanel'); $anon->get('/administrator/index.php?option=com_engage&view=controlpanel&format=json');
r('B84-02', 'Un visitante anonimo pidiendo el panel de administracion con Host hostil no aprende nada', $aprendida() === '', 'aprendida=' . $aprendida());
[$adm, $okA] = $login('r3adm', $PASS, 'sitio-a.test:8080', 'r3h11adm');
$pa = $okA ? $panel($adm) : ['code' => 0, 'body' => ''];
r('B84-03', 'Administrator SIN core.admin de com_engage abre el panel: no aprende y el semaforo ambar dice «abra el panel de control de Engage una vez como administrador o escriba la URL»', $okA && $pa['code'] === 200 && $aprendida() === '' && preg_match('/abra el panel de control de Engage una vez|open the Engage control panel once/', $pa['body']) === 1, "login=" . ($okA ? 'si' : 'no') . " HTTP {$pa['code']} aprendida=" . $aprendida());
[$sup, $okS] = $login('admintest', $AP, 'sitio-a.test:8080', 'r3h11sup');
$ps = $okS ? $sup->get('/administrator/index.php?option=com_engage', ['X-Requested-With: XMLHttpRequest']) : ['code' => 0, 'body' => ''];
$pj = $okS ? $sup->get('/administrator/index.php?option=com_engage&view=controlpanel&format=json') : ['code' => 0];
r('B84-04', 'Super User por AJAX (X-Requested-With) y con format=json: no aprende', $okS && $aprendida() === '', 'aprendida=' . $aprendida());
$x = $sup->post('/administrator/index.php?option=com_engage&view=controlpanel', ['a' => 1]);
r('B84-05', 'Super User por POST: no aprende', $aprendida() === '', 'HTTP ' . $x['code'] . ' aprendida=' . $aprendida());
$ps = $panel($sup);
$esperada = 'http://sitio-a.test:8080';
r('B84-06', 'Super User abre el panel de control de Engage con Host sitio-a.test:8080: aprende ' . $esperada . ' (una vez) y el semaforo dice la direccion y que es «aprendida»', $okS && $ps['code'] === 200 && $aprendida() === $esperada && str_contains($ps['body'], $esperada) && preg_match('/aprendida|learned/', $ps['body']) === 1, 'aprendida=' . $aprendida());
[$sup2, $ok2] = $login('admintest', $AP, 'sitio-b.test:8080', 'r3h11sup2');
$panel($sup2);
r('B84-07', 'Otro acceso de administracion por un alias (sitio-b.test:8080) NO sobrescribe la base aprendida', $ok2 && $aprendida() === $esperada, 'aprendida=' . $aprendida());
[$n, $urls, $txt] = $enviar('evil.test:8080', 'b');
$h = $hosts($urls);
r('B84-08', 'Invitado con Host hostil (evil.test): los enlaces del correo (comentario, moderacion firmada) salen SOLO sobre la base aprendida, ni uno a evil.test', $n > 0 && $h === ['sitio-a.test:8080'] && !str_contains($txt, 'evil.test') && preg_match('~token=[0-9a-f]{64}~', $txt) === 1, "correos=$n hosts=" . implode(',', $h));
// ---------------------------------------------------------------- olvidar
$tok = Cliente::token($ps['body']);
$x = $sup->post('/administrator/index.php?option=com_engage&task=controlpanel.forgetbase', []);
r('B84-09', 'Olvidar sin token CSRF: no hace nada', $aprendida() === $esperada, 'HTTP ' . $x['code']);
$tokA = Cliente::token($panel($adm)['body']);
$x = $adm->post('/administrator/index.php?option=com_engage&task=controlpanel.forgetbase', [$tokA => 1]);
r('B84-10', 'Olvidar un Administrator sin core.admin: no hace nada', $aprendida() === $esperada, 'HTTP ' . $x['code']);
$x = $sup->post('/administrator/index.php?option=com_engage&task=controlpanel.forgetbase', [$tok => 1]);
r('B84-11', 'Super User con token olvida la base aprendida (y no vuelve al panel: no reaprende en la misma peticion)', $aprendida() === '' && $x['code'] >= 300 && $x['code'] < 400 && !str_contains($x['loc'], 'com_engage'), 'HTTP ' . $x['code'] . ' loc=' . $x['loc']);
$panel($sup2);
r('B84-12', 'La siguiente visita de administracion aprende de nuevo (ahora la de sitio-b.test:8080)', $aprendida() === 'http://sitio-b.test:8080', 'aprendida=' . $aprendida());
// ---------------------------------------------------------------- prioridades
$setCfg('https://live.test', 0);
[$n, $urls, $txt] = $enviar('evil.test:8080', 'c');
r('B84-13', 'live_site manda sobre la aprendida', $n > 0 && $hosts($urls) === ['live.test'] && !str_contains($txt, 'sitio-b.test'), 'hosts=' . implode(',', $hosts($urls)));
$tokS = fn() => preg_match('/"csrf\.token":"([0-9a-f]{32})"/', $sup->get('/administrator/index.php?option=com_engage&view=settings')['body'], $m) ? $m[1] : '';
$guardar = fn(string $v) => $sup->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => 'email_base_url', 'value' => $v, $tokS() => 1]);
$x = $guardar('https://opcion.test/blog/');
r('B84-14', 'Opcion «URL base» desde el panel moderno: se guarda normalizada (sin barra final)', $x['code'] === 200 && $opcion() === 'https://opcion.test/blog', "HTTP {$x['code']} valor=" . $opcion());
[$n, $urls, $txt] = $enviar('evil.test:8080', 'd');
r('B84-15', 'La opcion manda sobre live_site y sobre la aprendida; las rutas ya llevan la subcarpeta real de Joomla, solo cambia el origen', $n > 0 && $hosts($urls) === ['opcion.test'] && str_contains($txt, 'https://opcion.test/index.php/component/'), 'hosts=' . implode(',', $hosts($urls)) . ' ' . implode(' ', array_slice($urls, 0, 3)));
$pp = $panel($sup);
r('B84-16', 'El semaforo indica que la base viene de la opcion', str_contains($pp['body'], 'https://opcion.test/blog') && preg_match('/opción «URL base|Base URL of the links|URL base de los enlaces/', $pp['body']) === 1);
$mal = [];
foreach (['javascript:alert(1)', 'https://user:pw@opcion.test', 'https://opcion.test@evil.test', 'https://opcion.test/?a=1', 'https://opcion.test/#x', 'https://exa mple.test', 'https://opcion.test:99999', 'https://exämple.test', 'https://127.0.0.1', 'https://localhost', '//evil.test', 'https:\\\\evil.test', str_repeat('a', 300), 'ftp://opcion.test', 'https://10.0.0.1'] as $v) {
	$x = $guardar($v); if ($x['code'] !== 422 || $opcion() !== 'https://opcion.test/blog') { $mal[] = json_encode(substr($v, 0, 30)) . "={$x['code']}/" . $opcion(); }
}
r('B84-17', 'Valores hostiles en la opcion (15 variantes): 422 y la opcion guardada no cambia', !$mal, implode(' | ', array_slice($mal, 0, 4)));
$x = $guardar('');
r('B84-18', 'Opcion vacia = borrada; vuelve a mandar live_site', $x['code'] === 200 && $opcion() === '' && $hosts($enviar('evil.test:8080', 'e')[1]) === ['live.test'], "HTTP {$x['code']}");
// Opcion invalida escrita fuera del formulario: se ignora
$setCom(['email_base_url' => 'javascript:alert(1)']);
[$n, $urls, $txt] = $enviar('evil.test:8080', 'f');
$pp = $panel($sup);
r('B84-19', 'Una opcion no valida escrita fuera del formulario se ignora (cae a live_site) y el panel avisa', $hosts($urls) === ['live.test'] && preg_match('/no es válida|is not valid/', $pp['body']) === 1);
$setCom(['email_base_url' => '']);
// http con SSL forzado (la peticion se simula por https con X-Engage-Test-Https; las cookies Secure se devuelven por http)
$setCfg('', 2);
[$supS, $okSS] = $login('admintest', $AP, 'sitio-c.test:8080', 'r3h11supS', true);
$tokSS = fn() => preg_match('/"csrf\.token":"([0-9a-f]{32})"/', $supS->get('/administrator/index.php?option=com_engage&view=settings')['body'], $m) ? $m[1] : '';
$guardarS = fn(string $v) => $supS->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => 'email_base_url', 'value' => $v, $tokSS() => 1]);
$x = $guardarS('http://opcion.test');
r('B84-20', 'Con el sitio forzando SSL (force_ssl = 2), la opcion http:// se rechaza (422)', $okSS && $x['code'] === 422 && $opcion() === '', "login=" . ($okSS ? 'si' : 'no') . " HTTP {$x['code']}");
$x = $guardarS('https://opcion.test');
r('B84-21', 'Con SSL forzado, https:// se acepta', $x['code'] === 200 && $opcion() === 'https://opcion.test', "HTTP {$x['code']}");
$setCom(['email_base_url' => '', 'email_base_learned' => 'http://sitio-b.test:8080']);
[$n, $urls, $txt] = $enviar('evil.test:8080', 'g', true);
r('B84-22', 'Con SSL forzado una base aprendida http se usa como https (nunca baja a http)', $n > 0 && preg_match('~https://sitio-b\.test:8080/~', $txt) === 1 && !preg_match('~http://sitio-b~', $txt), 'correos=' . $n . ' urls=' . implode(',', array_slice($urls, 0, 2)));
$limpia();
$panel($supS);
r('B84-23', 'Con SSL forzado se aprende https', $aprendida() === 'https://sitio-c.test:8080', 'aprendida=' . $aprendida());
$limpia();
// Direcciones internas: no se aprenden
$setCfg('', 0);
[$sup4, $ok4] = $login('admintest', $AP, '127.0.0.1:8080', 'r3h11sup4'); $panel($sup4);
[$sup5, $ok5] = $login('admintest', $AP, 'localhost:8080', 'r3h11sup5'); $panel($sup5);
r('B84-24', 'Acceder a la administracion por 127.0.0.1 o localhost NO aprende (direccion interna)', $aprendida() === '', 'aprendida=' . $aprendida());
$pf = $panel($sup4);
r('B84-25', 'En ese caso el semaforo sigue ambar con la instruccion', preg_match('/abra el panel de control de Engage una vez|open the Engage control panel once/', $pf['body']) === 1);
r('B84-26', 'Sin errores PHP en el registro', !is_file($PHPLOG) || !preg_match('/engage[^\n]*|(Fatal|Uncaught)[^\n]*/i', substr((string) file_get_contents($PHPLOG), $logIni)), is_file($PHPLOG) ? substr(substr((string) file_get_contents($PHPLOG), $logIni), -300) : '');
resumen('h11');
