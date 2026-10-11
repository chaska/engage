<?php
/**
 * 0.8.3: opcion «Incluir enlaces en los correos» (email_include_links) contra el Joomla REAL, con el SMTP de captura:
 *   1) ACL y validacion del guardado (modo moderno, settings.save): Administrator sin core.admin = 403 y no cambia nada; Super User si; valores no validos = 422 y no cambian
 *   2) correos a moderadores (comentario sin publicar: botones de moderacion; publicado: aviso) y a suscriptores (respuesta a un comentario: baja) con la opcion en 1 y en 0,
 *      con la plantilla por defecto y con una plantilla PERSONALIZADA: con 0 ningun href salvo la baja (firmada, sobre live_site), texto plano sin «http» salvo la baja
 *   3) con 0 no queda ningun token firmado de moderacion; con 1 el correo es el de siempre
 * Restaura parametros y plantillas al acabar. Requiere SMTP de captura y live_site en configuration.php.
 */
require __DIR__ . '/revision-082-comun.php';
usuario('r3adm', 7);
$com0 = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$mailsParams0 = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_mails'");
register_shutdown_function(function () use ($com0, $mailsParams0) {
	q("UPDATE jos_extensions SET params='" . esc((string) $com0) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc((string) $mailsParams0) . "' WHERE element='com_mails'");
	q("DELETE FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%' AND language='en-GB' AND subject LIKE 'R3-%'");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%'"); borraUsuarios();
});
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mail_style' => 'both', 'disable_htmllayout' => '0'])) . "' WHERE element='com_mails'");
$setCom = function (array $extra) use ($db) { $p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: []; $p = array_merge(['max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100', 'reactions_enabled' => '0', 'sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0'], $p, $extra); q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); usleep(300000); };
$param = fn() => json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true)['email_include_links'] ?? 'AUSENTE';
@mkdir("$WORK/tmp");
$AP = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));

// ------------------------------------------------------------------ 1) Guardado
$setCom(['email_include_links' => '1']);
$adm = new Cliente($BASE, "$WORK/tmp", 'r3h10adm'); $okA = $adm->loginAdmin('r3adm', $PASS);
$sup = new Cliente($BASE, "$WORK/tmp", 'r3h10sup'); $okS = $sup->loginAdmin('admintest', $AP);
$tok = fn(Cliente $c) => preg_match('/"csrf\.token":"([0-9a-f]{32})"/', $c->get('/administrator/index.php?option=com_engage&view=settings')['body'], $m) ? $m[1] : '';
$guardar = fn(Cliente $c, string $valor, ?string $token = null) => $c->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => 'email_include_links', 'value' => $valor, ($token ?? $tok($c)) => 1]);
r('H10-00', 'Sesiones de panel (Administrator y Super User) y la opcion existe con valor 1 por defecto en la pantalla moderna', $okA && $okS && $param() === '1' && str_contains($sup->get('/administrator/index.php?option=com_engage&view=settings&section=notifications')['body'], 'email_include_links'));
$x = $guardar($adm, '0');
r('H10-01', 'ACL: un Administrator (sin core.admin ni core.options de com_engage) NO puede cambiarla: 403 y el valor sigue en 1', $x['code'] === 403 && $param() === '1', "HTTP {$x['code']} valor=" . $param());
$x = $sup->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => 'email_include_links', 'value' => '0']);
r('H10-02', 'Sin token CSRF: 403 y no cambia', $x['code'] === 403 && $param() === '1', "HTTP {$x['code']}");
$x = $guardar($sup, '0');
r('H10-03', 'Super User con token: se guarda 0', $x['code'] === 200 && $param() === '0', "HTTP {$x['code']} valor=" . $param());
$mal = [];
foreach (['2', 'abc', '', '00', ' 0', 'true', 'on', '-1', '1.0', '0x1', "1\n", '[1]', 'null'] as $v) { $x = $guardar($sup, $v); if ($x['code'] !== 422 || $param() !== '0') { $mal[] = json_encode($v) . "={$x['code']}/" . $param(); } }
r('H10-04', 'Valores no validos (13 variantes) = 422 y el valor guardado no cambia', !$mal, implode(' | ', array_slice($mal, 0, 4)));
$x = $guardar($sup, '1');
r('H10-05', 'Se puede volver a 1', $x['code'] === 200 && $param() === '1');

// ------------------------------------------------------------------ 2) Correos
$mailDir = "$WORK/mails";
$leer = function (string $f): array { $t = (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f)); $raw = (string) file_get_contents($f); preg_match('/^To:\s*(.+)$/mi', $raw, $m); return ['para' => $m[1] ?? '', 'todo' => $t]; };
$partes = function (string $t): array { // [texto plano, html] (leer-correo.py marca cada parte)
	$txt = ''; $html = ''; foreach (preg_split('/@@@PARTE (text\/plain|text\/html)@@@\n/', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $i => $x) { }
	preg_match('/@@@PARTE text\/plain@@@\n(.*?)(?=@@@PARTE|\z)/s', $t, $a); preg_match('/@@@PARTE text\/html@@@\n(.*?)(?=@@@PARTE|\z)/s', $t, $b);
	return [$a[1] ?? '', $b[1] ?? '']; };
$enviar = function (string $tag, ?int $padre = null, string $email = '') use ($BASE, $WORK, $PAGE, $ASSET_PUB, $mailDir, $leer) {
	$antes = glob("$mailDir/*.eml") ?: [];
	$c = new Cliente($BASE, "$WORK/tmp", 'r3h10' . $tag); $p = $c->get($PAGE); $tk = Cliente::token($p['body']);
	$c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode('/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => (string) ($padre ?? 0), 'name' => "R3N-H10-$tag", 'email' => $email ?: "h10$tag@example.invalid", 'body' => "R3B-H10-$tag"]]);
	usleep(1500000);
	$out = []; foreach (array_diff(glob("$mailDir/*.eml") ?: [], $antes) as $f) { $out[] = $leer($f); }
	return $out;
};
$hrefs = fn(string $h) => (preg_match_all('/href\s*=\s*"([^"]*)"/i', $h, $m) ? $m[1] : []);
$cuenta = function (array $mails, callable $partes) use ($hrefs) {
	$r = [];
	foreach ($mails as $m) { [$txt, $html] = $partes($m['todo']); $sinBaja = preg_replace('~https?://[^\s"\'<>]*comments\.unsubscribe[^\s"\'<>]*~', '', $m['todo']);
		$r[] = ['para' => $m['para'], 'hrefs' => $hrefs($html), 'http' => preg_match_all('~https?://~', preg_replace('~xmlns[:a-z]*="[^"]*"|https?://www\.w3\.org[^\s"]*~', '', $txt)), 'tokens' => preg_match_all('/token=[0-9a-f]{64}/', $m['todo']),
			'otras' => preg_match_all('~https?://(?!www\.w3\.org)~', $sinBaja), 'moderacion' => preg_match_all('/comments\.(publish|unpublish|delete|possiblespam|reportspam|reportham)/', $m['todo'])]; }
	return $r;
};
$ejecutar = function (string $etiqueta, string $opt, bool $personalizada) use ($setCom, $enviar, $cuenta, $partes, $hrefs) {
	$setCom(['email_include_links' => $opt, 'default_publish' => '0']);
	$man = $cuenta($enviar($etiqueta . 'm'), $partes);                // moderacion: comentario sin publicar -> plantilla «manage» al moderador
	$setCom(['email_include_links' => $opt, 'default_publish' => '1']);
	$pad = $enviar($etiqueta . 'p', null, 'r3sub@example.invalid');   // comentario publicado: aviso a moderadores y autor
	$sub = $cuenta($enviar($etiqueta . 'r', (int) one("SELECT id FROM jos_engage_comments WHERE name='R3N-H10-{$etiqueta}p' ORDER BY id DESC LIMIT 1")), $partes); // respuesta: aviso al suscriptor r3sub
	return [$man, $cuenta($pad, $partes), $sub];
};
foreach ([false, true] as $pers) {
	$n = $pers ? 'personalizada' : 'por defecto';
	if ($pers) {
		$custom = "<p>Aviso: <a href='{CONTENT_LINK}'>{CONTENT_TITLE}</a> de {NAME}</p><table><tr><td><a class='engageemail-button' href='{PUBLISH_URL}'>Publicar</a></td><td><a class=\"engageemail-button engageemail-button-danger\" href=\"{DELETE_URL}\">Borrar</a></td><td><A HREF=\"{COMMENT_LINK}\">ver</A></td></tr></table><p><a href=\"{UNSUBSCRIBE_URL}\">baja</a></p>";
		foreach (['manage', 'notify_managers', 'notify_author', 'notify'] as $k) { q("DELETE FROM jos_mail_templates WHERE template_id='com_engage.$k' AND language='en-GB' AND subject LIKE 'R3-%'"); q("INSERT INTO jos_mail_templates (template_id, extension, language, subject, body, htmlbody, attachments, params) VALUES ('com_engage.$k', 'com_engage', 'en-GB', 'R3-personalizada $k', 'Aviso en texto plano de {CONTENT_TITLE}\nURL del comentario: {COMMENT_LINK}\nPuede darse de baja: {UNSUBSCRIBE_URL}', " . "'" . esc($custom) . "', '', '{}')"); }
	}
	foreach (['1', '0'] as $opt) {
		[$man, $pub, $sub] = $ejecutar("$n$opt", $opt, $pers);
		$moder = array_values(array_filter(array_merge($man, $pub), fn($x) => stripos($x['para'], 'admin@example.test') !== false));
		$subs = array_values(array_filter($sub, fn($x) => stripos($x['para'], 'r3sub@example.invalid') !== false));
		if ($opt === '1') {
			$conBotones = $moder && max(array_map(fn($x) => count($x['hrefs']), $moder)) >= 3;
			r("H10-A-$n-1", "Plantilla $n, opcion 1: el correo al moderador lleva los enlaces de siempre (botones y contenido) y tokens firmados", $conBotones && max(array_map(fn($x) => $x['tokens'], $moder)) >= 1, 'correos=' . count($moder) . ' hrefs=' . json_encode(array_map(fn($x) => count($x['hrefs']), $moder)));
			r("H10-B-$n-1", "Plantilla $n, opcion 1: el correo al suscriptor lleva la baja y el enlace al comentario", $subs && count($subs[0]['hrefs']) >= 2 && $subs[0]['tokens'] >= 1, 'hrefs=' . json_encode($subs[0]['hrefs'] ?? []));
		} else {
			$malM = array_filter($moder, fn($x) => count(array_filter($x['hrefs'], fn($h) => !str_contains($h, 'task=comments.unsubscribe'))) > 0 || $x['otras'] !== 0 || $x['moderacion'] !== 0);
			r("H10-A-$n-0", "Plantilla $n, opcion 0: el correo al moderador (" . count($moder) . ') NO lleva ningun enlace de moderacion, al contenido ni al comentario (solo, si la plantilla lo tiene, la baja), ni «http» ajeno a ella', $moder && !$malM, json_encode(array_values(array_map(fn($x) => [$x['hrefs'], $x['otras'], $x['moderacion']], $malM))));
			$ok = $subs && count($subs[0]['hrefs']) === 1 && preg_match('~^http://127\.0\.0\.1:8080/index\.php/component/engage\?task=comments\.unsubscribe&amp;~', $subs[0]['hrefs'][0]) === 1 && $subs[0]['tokens'] === 2 || ($subs && count($subs[0]['hrefs']) === 1 && str_contains($subs[0]['hrefs'][0], 'comments.unsubscribe') && str_contains($subs[0]['hrefs'][0], 'token='));
			r("H10-B-$n-0", "Plantilla $n, opcion 0: el correo al suscriptor solo lleva el enlace de BAJA (firmado, sobre live_site)", (bool) $ok, 'hrefs=' . json_encode($subs[0]['hrefs'] ?? []));
			r("H10-C-$n-0", "Plantilla $n, opcion 0: ningun href vacio ni «#» en ningun correo", !array_filter(array_merge($moder, $subs), fn($x) => array_filter($x['hrefs'], fn($h) => $h === '' || $h === '#')));
		}
	}
	if ($pers) { foreach (['manage', 'notify_managers', 'notify_author', 'notify'] as $k) { q("DELETE FROM jos_mail_templates WHERE template_id='com_engage.$k' AND language='en-GB' AND subject LIKE 'R3-%'"); } }
}
// Sin live_site y con 0 no hay ni la baja
$cfgPath = "$SITE/configuration.php"; $cfgOrig = file_get_contents($cfgPath);
register_shutdown_function(function () use ($cfgPath, $cfgOrig) { file_put_contents($cfgPath, $cfgOrig); });
file_put_contents($cfgPath, preg_replace("/public \\\$live_site = '[^']*';/", "public \$live_site = '';", $cfgOrig)); usleep(4500000);
$setCom(['email_base_url' => '', 'email_base_learned' => '']); // 0.8.4: sin ninguna fuente de base
[$man, $pub, $sub] = $ejecutar('sinbase0', '0', false);
$todos = array_merge($man, $pub, $sub);
r('H10-D', 'Opcion 0 SIN live_site: ningun correo lleva href alguno (tampoco la baja: sin base fiable no se pone) ni token firmado', $todos && !array_filter($todos, fn($x) => $x['hrefs'] !== [] || $x['tokens'] !== 0), 'correos=' . count($todos));
file_put_contents($cfgPath, $cfgOrig); usleep(4500000);
resumen('h10');
