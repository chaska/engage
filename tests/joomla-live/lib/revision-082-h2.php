<?php
/**
 * 0.8.3, H2 contra el Joomla REAL: Host hostil en los correos. Un invitado comenta con la cabecera `Host: evil.test` y se miran los correos CAPTURADOS
 * (SMTP de pruebas) en 4 configuraciones: live_site fiable, sin live_site, live_site http con SSL forzado (force_ssl = 2) y el live_site de siempre.
 * Ningun enlace del correo puede llevar el anfitrion falso; el esquema sale de live_site (https si el sitio fuerza SSL, nunca baja a http); sin live_site no hay
 * enlaces ni token firmado y el semaforo del panel lo dice. Restaura configuration.php al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
$cfgPath = "$SITE/configuration.php"; $cfgOrig = file_get_contents($cfgPath);
$mailDir = "$WORK/mails";
// El envoltorio HTML del propio Joomla (layouts/joomla/mail/mailtemplate.php) imprime `Uri::root()` (el Host de la peticion) en su pie: es codigo del nucleo,
// no de Engage (las plantillas de Engage ya llevan su propio HTML). Se desactiva (en Joomla, disable_htmllayout = 0 significa «sin envoltorio») para medir solo lo que genera Engage.
$comMails = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_mails'");
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mail_style' => 'both', 'disable_htmllayout' => '0'])) . "' WHERE element='com_mails'");
// 0.8.4: sin opcion email_base_url ni base aprendida (si no, «sin live_site» ya tendria base); se restauran al acabar
$comEng = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$pe = json_decode((string) $comEng, true) ?: []; $pe['email_base_url'] = ''; $pe['email_base_learned'] = '';
q("UPDATE jos_extensions SET params='" . esc(json_encode($pe)) . "' WHERE element='com_engage' AND type='component'");
register_shutdown_function(function () use ($cfgPath, $cfgOrig, $comMails, $comEng) { q("UPDATE jos_extensions SET params='" . esc((string) $comEng) . "' WHERE element='com_engage' AND type='component'"); q("UPDATE jos_extensions SET params='" . esc((string) $comMails) . "' WHERE element='com_mails'"); file_put_contents($cfgPath, $cfgOrig); q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%'"); borraUsuarios(); });
usuario('r3adm', 7);
@mkdir("$WORK/tmp");
$setCfg = function (string $live, int $force) use ($cfgPath, $cfgOrig) {
	$c = preg_replace("/public \\\$live_site = '[^']*';/", "public \$live_site = '$live';", $cfgOrig);
	$c = preg_replace('/public \$force_ssl = \d+;/', "public \$force_ssl = $force;", $c);
	file_put_contents($cfgPath, $c); usleep(4200000);
};
$enviar = function (string $host, string $tag, bool $https = false) use ($BASE, $WORK, $PAGE, $ASSET_PUB, $mailDir) {
	$antes = glob("$mailDir/*.eml") ?: [];
	$c = new Cliente($BASE, "$WORK/tmp", 'r3h2' . $tag);
	$cab = $host !== '' ? ["Host: $host"] : [];
	if ($https) { $cab[] = 'X-Engage-Test-Https: 1'; }
	$p = $c->get($PAGE, $cab); $tk = Cliente::token($p['body']);
	// Con SSL forzado Joomla marca la cookie de sesion como Secure y curl no la devolveria por http: se envia a mano en la cabecera
	if ($https && preg_match_all('/^Set-Cookie:\s*([^=;]+=[^;]*)/mi', $p['headers'], $mc)) { $cab[] = 'Cookie: ' . implode('; ', $mc[1]); }
	$x = $c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode('/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'name' => "R3N-H2-$tag", 'email' => "h2$tag@example.invalid", 'body' => "R3B-H2-$tag"]], $cab);
	usleep(1200000);
	$nuevos = array_diff(glob("$mailDir/*.eml") ?: [], $antes);
	$txt = '';
	foreach ($nuevos as $f) {
		$msg = file_get_contents($f);
		// Partes decodificadas (texto y HTML): se leen con el analizador de Python (stdlib), sin depender de la codificacion de transferencia
		$txt .= "\n" . (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f));
	}
	preg_match_all('~https?://[^\s"\'<>)]+~i', $txt, $m);
	$urls = array_values(array_filter(array_unique($m[0]), fn($u) => !str_contains($u, 'www.w3.org')));

	return [$x['code'], count($nuevos), $urls, $txt];
};
$hosts = fn(array $urls) => array_values(array_unique(array_map(fn($u) => strtolower((string) parse_url(html_entity_decode($u), PHP_URL_HOST)), $urls)));
$esquemas = fn(array $urls) => array_values(array_unique(array_map(fn($u) => strtolower((string) parse_url(html_entity_decode($u), PHP_URL_SCHEME)), $urls)));

// 1) live_site fiable distinto del Host de la peticion
$setCfg('https://sitio-fiable.test', 0);
[$code, $n, $urls, $txt] = $enviar('evil.test', 'a');
$h = $hosts($urls);
r('H2-01', 'live_site https://sitio-fiable.test y Host hostil (evil.test): el correo lleva enlaces SOLO a sitio-fiable.test (con token firmado), ninguno a evil.test', $n > 0 && $h === ['sitio-fiable.test'] && !str_contains($txt, 'evil.test') && str_contains($txt, 'token=') && str_contains($txt, 'https://sitio-fiable.test/index.php/component/content/article/'), "HTTP $code correos=$n hosts=" . implode(',', $h));
// 2) sin live_site
$setCfg('', 0);
[$code, $n, $urls, $txt] = $enviar('evil.test', 'b');
r('H2-02', 'Sin live_site y Host hostil: el correo se envia SIN enlaces absolutos, sin evil.test y sin ningun token firmado', $n > 0 && !str_contains($txt, 'evil.test') && !preg_match('~token=[0-9a-f]{64}~', $txt) && $urls === [], "HTTP $code correos=$n urls=" . implode(',', array_slice($urls, 0, 3)));
[$code, $n, $urls, $txt] = $enviar('', 'c');
r('H2-03', 'Sin live_site y Host normal: tampoco se deriva la base del Host (nada de enlaces ni de token)', $n > 0 && !preg_match('~token=[0-9a-f]{64}~', $txt) && $urls === [], "correos=$n urls=" . implode(',', array_slice($urls, 0, 3)));
$adm = new Cliente($BASE, "$WORK/tmp", 'r3h2adm'); $okA = $adm->loginAdmin('r3adm', $PASS);
$pa = $okA ? $adm->get('/administrator/index.php?option=com_engage') : ['body' => '', 'code' => 0];
r('H2-04', 'Sin live_site el semaforo del panel (correos de aviso) avisa de que no hay enlaces y dice que abra el panel como administrador o escriba la URL', $okA && $pa['code'] === 200 && preg_match('/abra el panel de control de Engage una vez|open the Engage control panel once/', $pa['body']) === 1, "login=" . ($okA ? 'si' : 'no') . " HTTP {$pa['code']}");
// 3) live_site http + SSL forzado
$setCfg('http://sitio-fiable.test', 2);
[$code, $n, $urls, $txt] = $enviar('evil.test', 'd', true);
$e = $esquemas($urls); $h = $hosts($urls);
r('H2-05', 'live_site http con force_ssl = 2 (TLS_FORCE) y Host hostil: todos los enlaces son https://sitio-fiable.test (nunca http, nunca evil.test)', $n > 0 && $e === ['https'] && $h === ['sitio-fiable.test'] && str_contains($txt, 'https://sitio-fiable.test/index.php/component/content/article/'), "HTTP $code correos=$n esquemas=" . implode(',', $e) . " hosts=" . implode(',', $h));
$setCfg('https://sitio-fiable.test', 0);
[$code, $n, $urls, $txt] = $enviar('http-forzado.test:80', 'e');
r('H2-06', 'live_site https: nunca baja a http aunque la peticion llegue por http con otro Host', $n > 0 && $esquemas($urls) === ['https'] && !str_contains($txt, 'http-forzado.test'), 'esquemas=' . implode(',', $esquemas($urls)));
// 4) la configuracion de siempre (live_site del sitio de pruebas) sigue dando enlaces utiles
file_put_contents($cfgPath, $cfgOrig); usleep(4200000);
$orig = preg_match("/public \\\$live_site = '([^']*)';/", $cfgOrig, $mm) ? $mm[1] : '';
[$code, $n, $urls, $txt] = $enviar('evil.test', 'f');
$okOrig = $orig !== '' ? ($n > 0 && str_contains($txt, "$orig/index.php") && !str_contains($txt, 'evil.test') && preg_match('~token=[0-9a-f]{64}~', $txt)) : true;
r('H2-07', "Con el live_site de siempre ($orig) y Host hostil: los enlaces (comentario, baja firmada) salen sobre ese live_site y no sobre el Host", (bool) $okOrig, "correos=$n");
[$code, $n, $urls, $txt] = $enviar('', 'g');
r('H2-08', 'Control: sin cabecera Host especial, mismo resultado que con la hostil (la peticion no decide)', $n > 0 && $hosts($urls) === $hosts($enviar('evil.test', 'h')[2]), 'hosts=' . implode(',', $hosts($urls)));
$pl = $adm->get('/administrator/index.php?option=com_engage&view=comments&filter[search]=R3N-H2');
$enlaces = []; if (preg_match_all('~href="(/[^"]*component/content/article/[^"]*)"~', $pl['body'], $mm)) { $enlaces = $mm[1]; }
r('H2-09', 'La lista de comentarios del panel sigue enlazando al articulo (public_url ahora relativo, desde la raiz del sitio, no de /administrator)', $pl['code'] === 200 && $enlaces !== [] && !str_contains($enlaces[0], '/administrator'), 'enlaces=' . count($enlaces) . ' ' . ($enlaces[0] ?? ''));
resumen('h2');
