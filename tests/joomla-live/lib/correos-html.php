<?php
/**
 * 0.9.0 contra el Joomla REAL con el SMTP de captura: correos de aviso con diseño HTML enriquecido y aviso de confidencialidad.
 *  - 4 combinaciones de las opciones (email_rich x email_confidential), es-ES y en-GB, usuario suscriptor y moderador, plantilla por defecto y
 *    PERSONALIZADA (hostil), con y sin enlaces; el texto plano coincide con el HTML; las plantillas guardadas no cambian
 *  - vista previa y correo de prueba: ACL, token, limite, un unico destinatario
 * Restaura parametros, usuarios, plantillas e idiomas al acabar. Requiere live_site en configuration.php (el del sitio de pruebas).
 */
require __DIR__ . '/revision-082-comun.php';
$S = "$SITE";
usleep(5000000); // el script anterior (h11) acaba de restaurar configuration.php: el opcache del servidor de pruebas tarda unos segundos en verlo
$mailDir = "$WORK/mails";
$AP = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
@mkdir("$WORK/tmp");
$com0   = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$mails0 = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_mails'");
$adminId = (int) one("SELECT id FROM jos_users WHERE username='admintest'");
$adminParams0 = (string) one("SELECT IFNULL(params,'{}') FROM jos_users WHERE id=$adminId");
$tplSum0 = one("SELECT MD5(GROUP_CONCAT(CONCAT(template_id,language,subject,body,htmlbody) ORDER BY template_id, language)) FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%'");
$creados = [];
foreach (['language' => 'site', 'administrator/language' => 'administrator'] as $d => $cl) {
	if (!is_dir("$S/$d/es-ES")) {
		mkdir("$S/$d/es-ES", 0755, true);
		$meta = str_replace(['client="site"', 'English (en-GB)', 'English (United Kingdom)', '<tag>en-GB</tag>'], ["client=\"$cl\"", 'Spanish (es-ES)', 'Español (España)', '<tag>es-ES</tag>'], (string) file_get_contents("$S/language/en-GB/langmetadata.xml"));
		file_put_contents("$S/$d/es-ES/langmetadata.xml", preg_replace('/en_GB[^<]*/', 'es_ES.utf8, es_ES.UTF-8, es_ES, es', $meta));
		$creados[] = "$S/$d/es-ES";
	}
	copy(dirname(__DIR__, 3) . '/src/component/backend/language/es-ES/com_engage.ini', "$S/$d/es-ES/com_engage.ini");
	$creados[] = "$S/$d/es-ES/com_engage.ini";
}
register_shutdown_function(function () use ($com0, $mails0, $adminId, $adminParams0, $creados) {
	q("UPDATE jos_extensions SET params='" . esc((string) $com0) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc((string) $mails0) . "' WHERE element='com_mails'");
	q("UPDATE jos_users SET params='" . esc($adminParams0) . "' WHERE id=$adminId");
	q("DELETE FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%' AND subject LIKE 'R9-%'");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%'"); borraUsuarios();
	foreach (array_reverse($creados) as $p) { if (is_file($p)) { unlink($p); } }
	foreach (array_reverse($creados) as $p) { if (is_dir($p)) { @array_map('unlink', glob("$p/*") ?: []); @rmdir($p); } }
});
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mail_style' => 'both', 'disable_htmllayout' => '0'])) . "' WHERE element='com_mails'");
usuario('r3sub', 2);
usuario('r3mod', 7);
$setCom = function (array $extra) { $p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: []; $p = array_merge(['max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100', 'reactions_enabled' => '0', 'sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0', 'email_include_links' => '1', 'email_rich' => '1', 'email_confidential' => '1', 'email_accent' => 'blue'], $p, $extra); q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); usleep(300000); };
$setLang = function (string $lang) use ($adminId) { $p = ['language' => $lang, 'admin_language' => $lang, 'administrator_language' => $lang]; q("UPDATE jos_users SET params='" . esc(json_encode($p)) . "' WHERE id=$adminId OR username='r3sub'"); };
$leer = function (string $f): array { $t = (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f)); $raw = (string) file_get_contents($f); preg_match('/^To:\s*(.+)$/mi', $raw, $m);
	preg_match('/@@@PARTE text\/plain@@@\n(.*?)(?=@@@PARTE|\z)/s', $t, $a); preg_match('/@@@PARTE text\/html@@@\n(.*?)(?=@@@PARTE|\z)/s', $t, $b); return ['para' => $m[1] ?? '', 'txt' => $a[1] ?? '', 'html' => $b[1] ?? '', 'raw' => $raw]; };
$enviar = function (string $tag, ?int $padre = null, string $email = '') use ($BASE, $WORK, $PAGE, $ASSET_PUB, $mailDir, $leer) {
	$antes = glob("$mailDir/*.eml") ?: [];
	$c = new Cliente($BASE, "$WORK/tmp", 'r9' . $tag); $p = $c->get($PAGE); $tk = Cliente::token($p['body']);
	$c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode('/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => (string) ($padre ?? 0), 'name' => "R3N-H12-$tag", 'email' => $email ?: "h12$tag@example.invalid", 'body' => "R3B-H12-$tag <b>negrita</b>"]]);
	usleep(1500000);
	$out = []; foreach (array_diff(glob("$mailDir/*.eml") ?: [], $antes) as $f) { $out[] = $leer($f); }
	if (getenv('DEPURA')) { fwrite(STDERR, "[$tag] correos=" . count($out) . ' para=' . implode('|', array_map(fn($x) => $x['para'], $out)) . "\n"); }
	return $out;
};
$norm = fn(string $s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<style.*?</style>#s', '', $s)), ENT_QUOTES, 'UTF-8')));
$frase = ['en-GB' => 'confidential and intended exclusively for the person', 'es-ES' => 'confidenciales y van dirigidos exclusivamente a la persona destinataria'];
$privac = ['en-GB' => 'data protection law (GDPR)', 'es-ES' => 'normativa de protección de datos (RGPD)'];

/** Envia moderador (comentario sin publicar) y suscriptor (respuesta a un comentario de r3sub) y devuelve [mail al moderador, mail al suscriptor]. */
$flujo = function (string $tag) use ($setCom, $enviar) {
	$setCom(['default_publish' => '0']);
	$man = array_values(array_filter($enviar($tag . 'm'), fn($x) => stripos($x['para'], 'admin@example.test') !== false));
	$setCom(['default_publish' => '1']);
	// comentario del usuario registrado r3sub (suscriptor), puesto directamente en la base de datos; la respuesta de un invitado le avisa a el
	$uid = (int) one("SELECT id FROM jos_users WHERE username='r3sub'"); global $ASSET_PUB;
	q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B padre del suscriptor</p>',NULL,NULL,'203.0.113.8','t',1,'2026-09-02 10:00:00',$uid)");
	global $db; $padre = (int) $db->insert_id;
	$sub = array_values(array_filter($enviar($tag . 'r', $padre), fn($x) => stripos($x['para'], 'r3sub@example.invalid') !== false));
	q("DELETE FROM jos_engage_comments WHERE id=$padre");
	return [$man[0] ?? null, $sub[0] ?? null];
};
$hrefsDe = fn(string $h) => preg_match_all('/\bhref="([^"]*)"/i', $h, $m) ? $m[1] : [];
$limpioHtml = function (string $h): array {
	$sinStyle = preg_replace('#<style type="text/css">.*?</style>#s', '', $h);
	return [!preg_match('/<script|<iframe|<form|<object|<embed|\bsrc\s*=|<img|url\s*\(|@import|javascript:|\bon[a-z]+\s*=/i', $sinStyle), $sinStyle];
};

$n = 0;
foreach (['en-GB', 'es-ES'] as $lang) {
	$setLang($lang);
	foreach ([[1, 1], [1, 0], [0, 1], [0, 0]] as [$rich, $conf]) {
		$etq = "$lang-$rich$conf";
		$setCom(['email_rich' => (string) $rich, 'email_confidential' => (string) $conf]);
		[$mod, $sub] = $flujo(str_replace('-', '', $etq));
		foreach (['moderador' => $mod, 'suscriptor' => $sub] as $quien => $m) {
			$id = sprintf('C%02d', ++$n);
			if ($m === null) { r($id, "$etq $quien: llega el correo", false); continue; }
			$h = $m['html']; $t = $m['txt'];
			if ($rich && $conf) { @mkdir("$WORK/correos-html"); file_put_contents("$WORK/correos-html/$lang-$quien.html", $h); file_put_contents("$WORK/correos-html/$lang-$quien.txt", $t); } [$limpio, $sinStyle] = $limpioHtml($h);
			$ok = true; $ev = [];
			if ($rich) { $ok = $ok && str_contains($h, 'eg-card') && str_contains($h, 'width="600"') && str_contains($h, 'color-scheme') && str_contains($h, 'prefers-color-scheme: dark') && $limpio && !str_contains($h, 'engageemail-outer-wrapper'); }
			else { $ok = $ok && !str_contains($h, 'eg-card') && str_contains($h, 'engageemail-outer-wrapper'); }
			$hr = $hrefsDe($h); $malos = array_filter($hr, fn($x) => !preg_match('~^(https?://127\.0\.0\.1:8080/|mailto:)~i', html_entity_decode($x)));
			$ok = $ok && !$malos;
			if ($conf) {
				$c1 = [str_contains($norm($h), $frase[$lang]), str_contains($norm($t), $frase[$lang]), str_contains($norm($h), $privac[$lang]), str_contains($norm($t), $privac[$lang])];
				$ok = $ok && !in_array(false, $c1, true); $ev[] = json_encode($c1);
				if ($lang === 'es-ES') { $ok = $ok && preg_match('/\busted\b/', $norm($h)) === 1 && preg_match('/\busted\b/', $norm($t)) === 1; }
			} else { $ok = $ok && !str_contains($norm($h), $frase[$lang]) && !str_contains($norm($t), $frase[$lang]); }
			if ($quien === 'suscriptor') { $ok = $ok && count(array_filter($hr, fn($x) => str_contains($x, 'comments.unsubscribe'))) >= 1 && str_contains($t, 'comments.unsubscribe'); }
			if ($quien === 'moderador') { $ok = $ok && count(array_filter($hr, fn($x) => str_contains($x, 'task=comments.'))) >= 3 && preg_match('/token=[0-9a-f]{64}/', $h) === 1; }
			// el texto plano coincide con el HTML en el aviso
			if ($conf) { preg_match('/(?:Confidentiality notice|Aviso de confidencialidad)(.*)/s', $t, $mm); $pl = $norm($mm[1] ?? ''); $okc = $pl !== '' && str_contains($norm($h), mb_substr($pl, 0, 160)); $ok = $ok && $okc; }
			if ($lang === 'es-ES' && $rich) { $ok = $ok && str_contains($h, 'lang="es-ES"'); }
			r($id, "$etq $quien: " . ($rich ? 'diseño enriquecido limpio (sin script/src/url/img, href solo del sitio), 600 px, modo oscuro' : 'sin diseño (como antes)') . '; aviso ' . ($conf ? 'en HTML y texto plano, igual' : 'ausente') . ($quien === 'suscriptor' ? '; baja firmada' : '; botones firmados'), $ok, 'href=' . count($hr) . ' malos=' . json_encode(array_values($malos)) . ' ' . implode(' ', $ev) . ($ok ? '' : ' txtconf=' . substr($norm($t), -200)));
		}
	}
}

// ---- Sin enlaces: la baja se mantiene, los botones de moderacion no
$setLang('en-GB'); $setCom(['email_rich' => '1', 'email_confidential' => '1', 'email_include_links' => '0']);
[$mod, $sub] = $flujo('sinlinks');
$hm = $hrefsDe($mod['html'] ?? ''); $hs = $hrefsDe($sub['html'] ?? '');
r('C20', 'Enlaces desactivados: el moderador no recibe ni un href de moderacion/contenido y el suscriptor solo la baja (firmada), con el diseño enriquecido', $mod && $sub && !array_filter($hm, fn($x) => str_contains($x, 'task=comments.') || str_contains($x, 'article')) && !str_contains($mod['html'], 'token=') && count($hs) === 1 && str_contains($hs[0], 'comments.unsubscribe') && str_contains($sub['html'], 'eg-card'), 'moderador=' . json_encode($hm) . ' suscriptor=' . json_encode($hs));
$setCom(['email_include_links' => '1']);

// ---- Plantilla PERSONALIZADA hostil
$custom = "<p>Aviso: <a href='{CONTENT_LINK}' onclick=\"alert(1)\">{CONTENT_TITLE}</a> de {NAME}</p><script>window.__x=1</script><style>body{background:url(http://t.example/x.gif)}</style><img src=\"http://t.example/p.gif\" width=1 height=1><iframe src=\"http://t.example\"></iframe><form action=\"http://t.example\"><input name=a></form><table><tr><td><a class='engageemail-button' href='{PUBLISH_URL}'>Publicar</a></td><td><a class=\"engageemail-button engageemail-button-danger\" href=\"javascript:alert(2)\">Borrar</a></td><td><A HREF=\"{COMMENT_LINK}\">ver</A></td></tr></table><p><a href=\"{UNSUBSCRIBE_URL}\">baja</a></p>";
foreach (['manage', 'notify'] as $k) { q("DELETE FROM jos_mail_templates WHERE template_id='com_engage.$k' AND language='en-GB' AND subject LIKE 'R9-%'"); q("INSERT INTO jos_mail_templates (template_id, extension, language, subject, body, htmlbody, attachments, params) VALUES ('com_engage.$k', 'com_engage', 'en-GB', 'R9-personalizada $k', 'Aviso en texto plano de {CONTENT_TITLE}\nURL del comentario: {COMMENT_LINK}\nBaja: {UNSUBSCRIBE_URL}', '" . esc($custom) . "', '', '{}')"); }
[$mod, $sub] = $flujo('custom');
$ok = true; $ev = [];
foreach (['moderador' => $mod, 'suscriptor' => $sub] as $q2 => $m) { if (!$m) { $ok = false; continue; } [$limpio] = $limpioHtml($m['html']); $ok = $ok && $limpio && str_contains($m['html'], 'eg-card') && !str_contains($m['html'], 't.example') && !str_contains($m['html'], 'alert(') && !str_contains($m['html'], '__x') && str_contains($m['txt'], 'Aviso en texto plano'); $ev[$q2] = $limpio; }
r('C21', 'Plantilla PERSONALIZADA hostil (script, style con url(), img/iframe/form externos, javascript:, onclick): el correo sale dentro de la tarjeta, limpio, con su texto plano', $ok, json_encode($ev));
$sinBaja = $sub && preg_match_all('/comments\.unsubscribe/', $sub['html']) === 1;
r('C22', 'Plantilla personalizada con su propio enlace de baja: no se duplica la baja en el aviso', (bool) $sinBaja, 'veces=' . preg_match_all('/comments\.unsubscribe/', (string) ($sub['html'] ?? '')));
q("DELETE FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%' AND subject LIKE 'R9-%'");
// ---- Plantilla personalizada SIN enlace de baja: el aviso la anade
q("INSERT INTO jos_mail_templates (template_id, extension, language, subject, body, htmlbody, attachments, params) VALUES ('com_engage.notify', 'com_engage', 'en-GB', 'R9-sinbaja', 'Solo texto', '<p>Hola, {NAME} ha respondido.</p>', '', '{}')");
[, $sub] = $flujo('sinbaja');
r('C23', 'Plantilla personalizada SIN enlace de baja: el aviso de confidencialidad añade la baja firmada (HTML y texto plano)', $sub && count(array_filter($hrefsDe($sub['html']), fn($x) => str_contains($x, 'comments.unsubscribe'))) === 1 && str_contains($sub['txt'], 'comments.unsubscribe'), '');
q("DELETE FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%' AND subject LIKE 'R9-%'");
// ---- B1-B4 (0.9.1): plantilla personalizada con 300 niveles de anidacion, style con image-set/cursor/position y elementos raros
$deep = str_repeat('<div>', 300) . "<p style=\"background:image-set(url(http://t.example/a.png) 1x);position:fixed\">Aviso {NAME}</p><a class='engageemail-button' href='{PUBLISH_URL}'>Publicar</a><a class='engageemail-button engageemail-button-danger' href='{DELETE_URL}'>Borrar</a>" . str_repeat('</div>', 300);
$raro = "<xmp><a href='http://evil.test/x'>x</a></xmp><map><area href='http://evil.test/a'></map><span style=\"cursor:url(http://t.example/c.cur),auto\" href='http://evil.test/s'>span</span><a class='engageemail-button' href='{PUBLISH_URL}'>Publicar</a>";
foreach (['manage' => $deep, 'notify_author' => $raro] as $k => $htmlTpl) { q("DELETE FROM jos_mail_templates WHERE template_id='com_engage.$k' AND language='en-GB' AND subject LIKE 'R9-%'"); q("INSERT INTO jos_mail_templates (template_id, extension, language, subject, body, htmlbody, attachments, params) VALUES ('com_engage.$k', 'com_engage', 'en-GB', 'R9-$k', 'Texto plano', '" . esc($htmlTpl) . "', '', '{}')"); }
$setCom(['default_publish' => '0']);
$mm = array_values(array_filter($enviar('profundo'), fn($x) => stripos($x['para'], 'admin@example.test') !== false));
$hh = $mm[0]['html'] ?? '';
r('C26', 'B1 (0.9.1): plantilla con 300 niveles de anidacion: el correo no queda vacio y conserva los botones de moderacion firmados', $hh !== '' && substr_count($hh, 'task=comments.') >= 2 && str_contains($hh, 'Publicar') && str_contains($hh, 'eg-card') && !preg_match('/image-set|position:fixed|t\.example/i', $hh), 'href=' . count($hrefsDe($hh)));
$setCom(['default_publish' => '1']);
$m2 = array_values(array_filter($enviar('raro'), fn($x) => stripos($x['para'], 'admin@example.test') !== false));
$h2 = $m2[0]['html'] ?? '';
r('C27', 'B2/B4 (0.9.1): style con cursor:url, elementos xmp/map/area y href en <span>: nada de evil.test ni t.example en el correo', $h2 !== '' && !preg_match('/evil\.test|t\.example|<xmp|<map|<area|cursor/i', $h2) && str_contains($h2, 'task=comments.publish'), strlen($h2) . ' bytes');
q("DELETE FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%' AND subject LIKE 'R9-%'");
$tplSum1 = one("SELECT MD5(GROUP_CONCAT(CONCAT(template_id,language,subject,body,htmlbody) ORDER BY template_id, language)) FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%'");
r('C24', 'Las plantillas de correo guardadas en Joomla no cambian (ni se reescriben ni se pisan)', $tplSum0 === $tplSum1, "$tplSum0 / $tplSum1");

// ---- Acento: lista cerrada
$setCom(['email_accent' => 'violet', 'email_rich' => '1']);
[$mod] = $flujo('acento');
$setCom(['email_accent' => 'x";}</style><script>alert(1)</script>']);
[$mod2] = $flujo('acento2');
r('C25', 'Acento violeta -> #6d28d9; un acento hostil escrito fuera del formulario cae a azul y no llega al CSS', $mod && $mod2 && str_contains($mod['html'], '#6d28d9') && str_contains($mod2['html'], '#1d4ed8') && !str_contains($mod2['html'], 'alert(1)'), '');
$setCom(['email_accent' => 'blue']);

// ---- Opciones del panel moderno: ACL y validacion cerrada
$sup = new Cliente($BASE, "$WORK/tmp", 'r9sup'); $okS = $sup->loginAdmin('admintest', $AP);
$adm = new Cliente($BASE, "$WORK/tmp", 'r9adm'); $okA = $adm->loginAdmin('r3mod', $PASS);
$tokS = fn(Cliente $c) => preg_match('/"csrf\.token":"([0-9a-f]{32})"/', $c->get('/administrator/index.php?option=com_engage&view=settings')['body'], $m) ? $m[1] : '';
$guardar = fn(Cliente $c, string $k, string $v) => $c->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => $k, 'value' => $v, $tokS($c) => 1]);
$par = fn(string $k) => json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true)[$k] ?? 'AUSENTE';
$x1 = $guardar($sup, 'email_rich', '0'); $v1 = $par('email_rich'); $guardar($sup, 'email_rich', '1');
$x2 = $guardar($adm, 'email_confidential', '0'); $v2 = $par('email_confidential');
$mal = []; foreach ([['email_rich', '2'], ['email_rich', 'x'], ['email_confidential', ''], ['email_accent', 'pink'], ['email_accent', "blue;background:url(x)"], ['email_accent', '#ff0000'], ['email_accent', 'BLUE']] as [$k, $v]) { $before = $par($k); $x = $guardar($sup, $k, $v); if ($x['code'] !== 422 || $par($k) !== $before) { $mal[] = "$k=" . json_encode($v) . ":{$x['code']}"; } }
$x3 = $guardar($sup, 'email_accent', 'green'); $v3 = $par('email_accent'); $guardar($sup, 'email_accent', 'blue');
r('C26', 'Panel moderno: Super User guarda email_rich (200); un Administrator sin core.admin no puede (403); valores no validos = 422 sin cambios; acento de la lista se guarda', $okS && $okA && $x1['code'] === 200 && $v1 === '0' && $x2['code'] === 403 && $v2 !== '0' && !$mal && $x3['code'] === 200 && $v3 === 'green', json_encode([$x1['code'], $v1, $x2['code'], $v2, $mal, $x3['code']]));
r('C27', 'Las opciones nuevas aparecen en el grupo Notificaciones del panel moderno', str_contains($sup->get('/administrator/index.php?option=com_engage&view=settings&section=notifications')['body'], 'email_confidential') && str_contains($sup->get('/administrator/index.php?option=com_engage&view=settings&section=notifications')['body'], 'email_accent'));

// ---- Vista previa y correo de prueba
$pv = $sup->get('/administrator/index.php?option=com_engage&view=emailpreview&lang=es-ES&kind=subscriber');
preg_match('/srcdoc="([^"]*)"/', $pv['body'], $sm); $src = html_entity_decode($sm[1] ?? '', ENT_QUOTES, 'UTF-8');
[$limp] = $limpioHtml($src);
r('C28', 'Vista previa: iframe sandbox con srcdoc (sin scripts), en es-ES con el aviso en tono de «usted», sin recursos externos', $pv['code'] === 200 && str_contains($pv['body'], 'sandbox=""') && $src !== '' && $limp && str_contains($norm($src), $frase['es-ES']) && preg_match('/\busted\b/', $norm($src)) === 1, 'HTTP ' . $pv['code'] . ' len=' . strlen($src));
$pa = $adm->get('/administrator/index.php?option=com_engage&view=emailpreview');
r('C29', 'Vista previa: un Administrator sin core.admin recibe 403', $pa['code'] === 403, 'HTTP ' . $pa['code']);
$cuenta = fn() => count(glob("$mailDir/*.eml") ?: []);
$tk = Cliente::token($pv['body']);
$c0 = $cuenta();
$g = $sup->get('/administrator/index.php?option=com_engage&task=emailpreview.sendtest'); usleep(600000);
$sinTok = $sup->post('/administrator/index.php?option=com_engage&task=emailpreview.sendtest', ['kind' => 'moderator', 'lang' => 'en-GB']); usleep(600000);
$tkA = Cliente::token($adm->get('/administrator/index.php?option=com_engage')['body']);
$deAdm = $adm->post('/administrator/index.php?option=com_engage&task=emailpreview.sendtest', ['kind' => 'moderator', 'lang' => 'en-GB', $tkA => 1]); usleep(600000);
r('C30', 'Correo de prueba: por GET, sin token o sin core.admin no se envía nada', $cuenta() === $c0, 'enviados=' . ($cuenta() - $c0));
$antes = glob("$mailDir/*.eml") ?: [];
$ok1 = $sup->post('/administrator/index.php?option=com_engage&task=emailpreview.sendtest', ['kind' => 'moderator', 'lang' => 'es-ES', $tk => 1]); usleep(1500000);
$nuevos = array_values(array_diff(glob("$mailDir/*.eml") ?: [], $antes));
$m1 = $nuevos ? $leer($nuevos[0]) : null;
r('C31', 'Correo de prueba: Super User con token envia UN correo, solo a su propia dirección (admin@example.test), con el diseño y el aviso en es-ES', count($nuevos) === 1 && $m1 && preg_match('/admin@example\.test/i', $m1['para']) === 1 && substr_count(strtolower($m1['para']), '@') === 1 && str_contains($m1['html'], 'eg-card') && str_contains($norm($m1['html']), $frase['es-ES']), 'enviados=' . count($nuevos) . ' para=' . ($m1['para'] ?? ''));
$antes = glob("$mailDir/*.eml") ?: [];
$sup->post('/administrator/index.php?option=com_engage&task=emailpreview.sendtest', ['kind' => 'moderator', 'lang' => 'es-ES', $tk => 1]); usleep(1200000);
r('C32', 'Correo de prueba: el segundo envío seguido se rechaza (límite de uno por minuto)', count(array_diff(glob("$mailDir/*.eml") ?: [], $antes)) === 0);
$cp = $sup->get('/administrator/index.php?option=com_engage');
r('C33', 'El panel de control enlaza a la vista previa', str_contains($cp['body'], 'view=emailpreview'));
r('C34', 'Sin errores PHP nuevos de Engage en el registro', !is_file($PHPLOG) || !preg_match('/engage[^\n]*(Fatal|Uncaught|Warning|Deprecated)|(Fatal|Uncaught)[^\n]*(Engage|EmailWrapper|EngageMailer)/i', (string) file_get_contents($PHPLOG)), '');
resumen('c');
