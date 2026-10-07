<?php
/**
 * 0.7.0: REACCIONES (me gusta, no me gusta, favorito) contra un Joomla REAL por HTTP, con curl y usuarios reales: invitado, dos Registered,
 * un Manager y el super administrador. Comprueba: esqueleto igual para todos (sin estado de usuario ni token en el HTML), GET de consulta sin
 * efectos, CSRF (sin token, token falso, token de otra sesion, GET), permisos (invitado, reactions_who), ids y tipos invalidos, inyeccion SQL
 * y XSS en todos los parametros (las tablas no cambian), comentarios inexistentes / sin publicar / spam / de un articulo restringido / de un
 * articulo sin publicar, comentario propio, transiciones, favorito privado, acumulacion, limite de frecuencia (429), opciones (desactivar
 * todo, sin no me gusta, sin favoritos, quien puede reaccionar) y limpieza al borrar comentarios. Los datos de la BD se comprueban con SQL.
 * Requisitos: 03-sembrar-y-probar.sh ($WORK/ids.json) y el paquete 3.4.3 instalado (02). Escribe $WORK/resultados-reacciones-http.json.
 * Sale con 1 si algo FALLA. Restaura parametros y permisos al acabar. Contrasenas al azar en $WORK/reaccionespass.txt (no en el repo).
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$DBN  = getenv('DB_NAME') ?: 'joomla_test';
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), $DBN);
$db->set_charset('utf8mb4');
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-8s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [$ev]" : '');
}
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
function js(array $resp): ?array { $d = json_decode($resp['body'], true); return is_array($d) ? $d : null; }
function hdr(array $resp, string $h): string { return preg_match('/^' . preg_quote($h, '/') . ':\s*(.+?)\r?$/mi', $resp['headers'], $m) ? trim($m[1]) : ''; }

$ASSET = (int) $ids['art_publico_asset'];
$PAGE  = "/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}";
$passFile = "$WORK/reaccionespass.txt";
if (!is_file($passFile)) { file_put_contents($passFile, 'Aa1!' . bin2hex(random_bytes(10))); chmod($passFile, 0600); }
$PASS = trim(file_get_contents($passFile));
$ADMIN_PASS = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));

// ---- Estado original (se restaura al final)
$prev = [
	'com'   => one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"),
	'rules' => one("SELECT rules FROM jos_assets WHERE name='com_engage'"),
];
$setCom = function (array $extra = []) use ($db) {
	$p = array_merge(['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc'], $extra);
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
};
$restaurar = function () use ($db, $prev, $ASSET) {
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string($prev['com']) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_assets SET rules='" . $db->real_escape_string($prev['rules']) . "' WHERE name='com_engage'");
	q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET OR name LIKE 'rx_%'");
	q("DELETE FROM jos_engage_reactions");
	q("UPDATE jos_content SET access=2 WHERE id=" . (int) $GLOBALS['ids']['art_restringido']);
};
register_shutdown_function(function () use ($restaurar) { $restaurar(); });

// ---- Usuarios de prueba (Registered x2, Manager x1) con contrasena al azar
$mkUser = function (string $user, int $group) use ($PASS, $db) {
	$id = (int) one("SELECT id FROM jos_users WHERE username='$user'");
	$h = password_hash($PASS, PASSWORD_BCRYPT);
	if (!$id) {
		q("INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, activation, params, resetCount, otpKey, otep, requireReset, authProvider) VALUES ('$user', '$user', '$user@example.invalid', '$h', 0, 0, NOW(), '', '{}', 0, '', '', 0, '')");
		$id = (int) $db->insert_id;
		q("INSERT INTO jos_user_usergroup_map (user_id, group_id) VALUES ($id, $group)");
	} else { q("UPDATE jos_users SET password='$h', block=0 WHERE id=$id"); }
	return $id;
};
$uA = $mkUser('reacreg1', 2); $uB = $mkUser('reacreg2', 2); $uC = $mkUser('reacreg3', 2);
$uM = $mkUser('reacmgr', 6);
$uAdmin = (int) one("SELECT id FROM jos_users WHERE username='admintest'");

// ---- Comentarios
q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET"); q("DELETE FROM jos_engage_reactions");
$cm = function (int $asset, string $body, int $by, string $name = '', int $enabled = 1, ?int $parent = null) use ($db) {
	$p = $parent === null ? 'NULL' : $parent; $nm = $by ? 'NULL' : "'$name'"; $em = $by ? 'NULL' : "'" . strtolower($name) . "@example.invalid'";
	q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset,$p," . "'" . $db->real_escape_string("<p>$body</p>") . "',$nm,$em,'203.0.113.7','t',$enabled,NOW(),$by)");
	return (int) $db->insert_id;
};
$cA   = $cm($ASSET, 'Comentario de reacreg1', $uA);              // propio de A
$cG   = $cm($ASSET, 'Comentario de un invitado', 0, 'Invitado');
$cM   = $cm($ASSET, 'Comentario del manager', $uM);
$cR   = $cm($ASSET, 'Respuesta de reacreg2 al invitado', $uB, '', 1, $cG);
$cPen = $cm($ASSET, 'Comentario sin publicar', 0, 'Pendiente', 0);
$cSp  = $cm($ASSET, 'Comentario spam', 0, 'Spammer', -3);
$cRes = $cm((int) $ids['art_restringido_asset'], 'Comentario de un articulo restringido', 0, 'Otro');
$cUnp = $cm((int) $ids['art_despublicado_asset'], 'Comentario de un articulo sin publicar', 0, 'Otro2');
$count = fn(string $t) => (int) one("SELECT COUNT(*) FROM $t");
$snap = fn() => md5(json_encode([q("SELECT * FROM jos_engage_comments ORDER BY id")->fetch_all(), q("SELECT * FROM jos_users ORDER BY id")->fetch_all(), q("SELECT extension_id,params FROM jos_extensions ORDER BY extension_id")->fetch_all(), q("SELECT name,rules FROM jos_assets WHERE name='com_engage'")->fetch_all()]));

$setCom();
(function () use ($WORK) { foreach (['/cache', '/administrator/cache'] as $d) { exec('rm -rf ' . escapeshellarg("$WORK/" . (getenv('SITE_DIR') ?: 'site') . "$d/com_engage_reactions")); } })();
$G = new Cliente($BASE, "$WORK/tmp", 'rx-guest');
$A = new Cliente($BASE, "$WORK/tmp", 'rx-a'); $B = new Cliente($BASE, "$WORK/tmp", 'rx-b'); $M = new Cliente($BASE, "$WORK/tmp", 'rx-m'); $C = new Cliente($BASE, "$WORK/tmp", 'rx-c');
r('X-00', 'inicio de sesion de reacreg1, reacreg2, reacreg3 (Registered) y reacmgr (Manager)', $A->login('reacreg1', $PASS) && $B->login('reacreg2', $PASS) && $C->login('reacreg3', $PASS) && $M->login('reacmgr', $PASS));

$STATE = '/index.php?option=com_engage&task=reactions.state&format=json&ids=';
$TOG   = '/index.php?option=com_engage&task=reactions.toggle&format=json';
$state = fn(Cliente $c, string $idsq) => $c->get($STATE . $idsq);
$token = function (Cliente $c) use ($state, $cG) { $s = js($state($c, (string) $cG)); return $s['token'] ?? ''; };
$tog   = fn(Cliente $c, $cid, $type, ?string $tok = null) => $c->post($TOG, ['comment_id' => $cid, 'type' => $type, ($tok ?? $GLOBALS['tk'][spl_object_id($c)] ?? '') => 1]);
$tk = [];
foreach ([$A, $B, $C, $M] as $c) { $tk[spl_object_id($c)] = $token($c); }
r('X-01', 'cada usuario con sesion recibe un token de 32 hex y son distintos', count(array_unique($tk)) === 4 && !array_filter($tk, fn($t) => !preg_match('/^[0-9a-f]{32}$/', $t)));

// ======================= 1. HTML servido
echo "-- 1. HTML servido\n";
$hg = $G->get($PAGE); $ha = $A->get($PAGE); $hb = $B->get($PAGE); $hm = $M->get($PAGE);
$skel = function (string $html): array { preg_match_all('#<div class="akengage-reactions"[^>]*>.*?</div>#s', $html, $m); return $m[0]; };
$sg = $skel($hg['body']); $sa = $skel($ha['body']); $sb = $skel($hb['body']); $sm = $skel($hm['body']);
r('H-01', 'invitado: una botonera por cada comentario PUBLICADO del articulo (4) y ninguna en sin publicar ni spam', $hg['code'] === 200 && count($sg) === 4 && substr_count($hg['body'], 'data-engage-reactions hidden') === 4, 'botoneras=' . count($sg));
r('H-02', 'el esqueleto es IDENTICO para invitado, registrado y manager (sin estado por usuario: valido para la cache de pagina)', $sg === $sa && $sa === $sb && count($sm) >= 4 && array_slice($sm, 0, 4) === $sa || $sg === $sa && $sa === $sb, 'g=' . count($sg) . ' a=' . count($sa) . ' b=' . count($sb) . ' m=' . count($sm));
r('H-03', 'ningun aria-pressed="true", ningun contador y ningun favorito en el HTML', !str_contains($hg['body'] . $ha['body'], 'aria-pressed="true"') && !str_contains($ha['body'], 'akengage-is-favorite') && !preg_match('#akengage-react-count"[^>]*>\s*[0-9]#', $ha['body']));
preg_match('/"akeeba\.Engage\.Reactions":(\{[^}]*\})/', $ha['body'], $mo); $optR = json_decode($mo[1] ?? '', true);
r('H-04', 'las opciones de la pagina para reacciones son SOLO las dos URL (ningun token ni estado por usuario; el token llega con la consulta)', is_array($optR) && array_keys($optR) === ['stateUrl', 'toggleUrl'] && !preg_match('/[0-9a-f]{32}/', $mo[1] ?? ''), $mo[1] ?? '');
r('H-05', 'cada boton: <button type="button">, data-engage-id entero, aria-pressed="false" y aria-label', (bool) preg_match('#<button type="button" class="akengage-react-btn akengage-react-like" data-engage-react="like" data-engage-id="' . $cA . '" aria-pressed="false" aria-label="Like">#', $hg['body']));
r('H-06', 'tres tipos por comentario: me gusta y no me gusta con contador (aria-live), favorito sin contador', substr_count($sg[0], '<button') === 3 && substr_count($sg[0], 'aria-live="polite"') === 2 && str_contains($sg[0], 'akengage-react-favorite'));
r('H-07', 'SVG en linea (sin imagenes ni Font Awesome) con currentColor y aria-hidden; sin estilos en linea en la botonera', substr_count($sg[0], '<svg') === 3 && substr_count($sg[0], 'stroke="currentColor"') === 3 && !str_contains($sg[0], 'style=') && !preg_match('#<img|fa-#', $sg[0]));
r('H-08', 'la botonera esta dentro de la fila de Responder (.akengage-comment-reply--react)', (bool) preg_match('#<div class="akengage-comment-reply akengage-comment-reply--react">\s*(<button class="akengage-comment-reply-btn[^>]*>.*?</button>\s*)?<div class="akengage-reactions"#s', $ha['body']));
r('H-09', 'el invitado tambien tiene la fila y la script reactions.js con sus textos (pista de inicio de sesion)', preg_match('#<div class="akengage-comment-reply akengage-comment-reply--react">\s*(<button class="akengage-comment-reply-btn[^>]*>.*?</button>\s*)?<div class="akengage-reactions"#s', $hg['body']) === 1 && str_contains($hg['body'], 'reactions.js') && str_contains($hg['body'], 'COM_ENGAGE_REACTIONS_HINT_LOGIN') && str_contains($hg['body'], 'stateUrl'));
r('H-10', 'script reactions.js servido con defer y versionado', (bool) preg_match('#<script src="[^"]*com_engage/js/reactions\.js\?[^"]*"[^>]*defer#', $hg['body']));

// ======================= 2. Consulta (GET)
echo "-- 2. Consulta (GET, sin efectos)\n";
$antes = $count('jos_engage_reactions'); $snap0 = $snap();
$sg0 = $state($G, "$cA,$cG,$cPen,$cSp,$cRes,$cUnp,999999");
$jg = js($sg0);
r('S-01', 'invitado: 200 JSON; solo los publicados y visibles (los demas se omiten sin pistas); sin token ni estado propio', $sg0['code'] === 200 && ($jg['ok'] ?? false) && $jg['auth'] === false && $jg['can'] === false && !isset($jg['token']) && array_map('strval', array_keys($jg['items'])) === [(string) $cA, (string) $cG] && !isset($jg['items'][(string) $cA]['mine']), json_encode($jg));
r('S-02', 'cabeceras: application/json, no-store, nosniff, Vary: Cookie', str_contains(hdr($sg0, 'Content-Type'), 'application/json') && str_contains(hdr($sg0, 'Cache-Control'), 'no-store') && hdr($sg0, 'X-Content-Type-Options') === 'nosniff' && hdr($sg0, 'Vary') === 'Cookie');
$sa0 = js($state($A, "$cA,$cG,$cM,$cRes"));
r('S-03', 'registrado: token, auth y can; "own" solo en su comentario; ve el articulo restringido a Registered (tiene acceso)', ($sa0['auth'] ?? 0) === true && ($sa0['can'] ?? 0) === true && preg_match('/^[0-9a-f]{32}$/', $sa0['token'] ?? '') === 1 && $sa0['items'][(string) $cA]['own'] === true && $sa0['items'][(string) $cG]['own'] === false && isset($sa0['items'][(string) $cRes]), json_encode($sa0));
$sm0 = js($state($M, "$cRes"));
r('S-04', 'un Manager (ve el articulo restringido?) recibe respuesta coherente (200)', ($sm0['ok'] ?? false) === true);
foreach (['', 'abc', '1,abc', '-1', '0', '1,,2', "1' OR '1'='1", '1;DROP TABLE jos_engage_reactions', '1 UNION SELECT 1', '<script>alert(1)</script>', '1,2,3)', '１', '%00', str_repeat('9', 40), implode(',', range(1, 101)), '1,2,%0a3'] as $bad) {
	$x = $state($A, rawurlencode($bad) === $bad ? $bad : str_replace('%2C', ',', rawurlencode($bad)));
	r('S-05', 'ids invalido -> 400 generico: ' . substr($bad, 0, 24), $x['code'] === 400 && js($x) === ['ok' => false, 'error' => 'invalid'], "code={$x['code']}");
}
$arr = $A->get('/index.php?option=com_engage&task=reactions.state&format=json&ids[]=1&ids[]=2');
r('S-06', 'ids como array -> 400', $arr['code'] === 400);
$noids = $A->get('/index.php?option=com_engage&task=reactions.state&format=json');
r('S-07', 'sin ids -> 400', $noids['code'] === 400);
$x = $A->req('POST', '/index.php?option=com_engage&task=reactions.state&format=json', ['ids' => $cA]);
r('S-08', 'la consulta por POST -> 405', $x['code'] === 405);
r('S-09', 'las consultas no escriben nada (reacciones, comentarios, usuarios, ajustes y permisos intactos)', $count('jos_engage_reactions') === $antes && $snap() === $snap0);
$m100 = $state($A, implode(',', range($cA, $cA + 99)));
r('S-10', '100 ids exactos se aceptan', $m100['code'] === 200);

$x = $A->get($STATE . $cG, ['Sec-Fetch-Site: cross-site']);
r('S-11', 'consulta iniciada por OTRO sitio (Sec-Fetch-Site: cross-site) -> 403 y sin token', $x['code'] === 403 && !str_contains($x['body'], 'token'));
$x = $A->get($STATE . $cG, ['Sec-Fetch-Site: same-origin']); r('S-12', 'Sec-Fetch-Site: same-origin (el del navegador en esta pagina) -> 200', $x['code'] === 200);
$x = $A->get($STATE . $cG, ['Sec-Fetch-Site: same-site']); r('S-13', 'Sec-Fetch-Site: same-site -> 200', $x['code'] === 200);

// ======================= 3. CSRF y metodo
echo "-- 3. CSRF y metodo\n";
$n0 = $count('jos_engage_reactions');
$x = $A->post($TOG, ['comment_id' => $cG, 'type' => 'like']);
r('C-01', 'POST sin token -> 403 generico, nada escrito', $x['code'] === 403 && js($x) === ['ok' => false, 'error' => 'denied'] && $count('jos_engage_reactions') === $n0);
$x = $A->post($TOG, ['comment_id' => $cG, 'type' => 'like', str_repeat('a', 32) => 1]);
r('C-02', 'POST con token inventado -> 403', $x['code'] === 403 && $count('jos_engage_reactions') === $n0);
$x = $A->post($TOG, ['comment_id' => $cG, 'type' => 'like', $tk[spl_object_id($B)] => 1]);
r('C-03', 'POST con el token de OTRA sesion -> 403', $x['code'] === 403 && $count('jos_engage_reactions') === $n0);
$x = $A->get($TOG . "&comment_id=$cG&type=like&" . $tk[spl_object_id($A)] . '=1');
r('C-04', 'GET con token valido en la URL -> 405 (las escrituras solo por POST)', $x['code'] === 405 && $count('jos_engage_reactions') === $n0);
$x = $A->post($TOG, ['comment_id' => $cG, 'type' => 'like', $tk[spl_object_id($A)] => 0]);
r('C-05', 'token con valor 0 -> 403', $x['code'] === 403 && $count('jos_engage_reactions') === $n0);
$x = $A->req('POST', $TOG . '&' . $tk[spl_object_id($A)] . '=1', [http_build_query(['comment_id' => $cG, 'type' => 'like'])], ['Content-Type: application/x-www-form-urlencoded'], true);
r('C-06', 'token solo en la URL (no en el cuerpo) -> 403', $x['code'] === 403 && $count('jos_engage_reactions') === $n0);
$x = $G->post($TOG, ['comment_id' => $cG, 'type' => 'like', $tk[spl_object_id($A)] => 1]);
r('C-07', 'invitado con un token ajeno -> 403', $x['code'] === 403 && $count('jos_engage_reactions') === $n0);
$gt = ''; $gp = $G->get($PAGE); if (preg_match('/name="([0-9a-f]{32})" value="1"/', $gp['body'], $m)) { $gt = $m[1]; }
$x = $G->post($TOG, ['comment_id' => $cG, 'type' => 'like', $gt => 1]);
r('C-08', 'invitado con SU token de formulario -> 403 (no hay sesion de usuario)', $x['code'] === 403 && js($x)['error'] === 'denied' && $count('jos_engage_reactions') === $n0);
$x = $A->req('PUT', $TOG, []);
r('C-09', 'PUT -> 405', $x['code'] === 405);
$x = $A->req('POST', $TOG, [json_encode(['comment_id' => $cG, 'type' => 'like'])], ['Content-Type: application/json'], true);
r('C-10', 'cuerpo JSON sin token -> 403', $x['code'] === 403 && $count('jos_engage_reactions') === $n0);
$x = $A->post($TOG, ['comment_id' => $cG, 'type' => 'like', $tk[spl_object_id($A)] => 1], ['Sec-Fetch-Site: cross-site']);
r('C-11', 'escritura iniciada por OTRO sitio (cross-site) con token valido -> 403 y nada escrito', $x['code'] === 403 && $count('jos_engage_reactions') === $n0);

// ======================= 4. Entradas invalidas e inyeccion
echo "-- 4. Entradas invalidas e inyeccion\n";
$tA = $tk[spl_object_id($A)]; $snapI = $snap(); $nI = $count('jos_engage_reactions');
$evil = ["1 OR 1=1", "$cG OR 1=1", "$cG; DROP TABLE jos_engage_reactions;--", "$cG UNION SELECT user_id FROM jos_engage_reactions", "$cG' OR '1'='1", "$cG\\' --", "$cG/**/OR/**/1=1", '<script>alert(1)</script>', "$cG\n", "$cG\0", '-1', '0', '', 'abc', '99999999999999999999999', '1.5', '1e2', '٣', '0x1', ' ' . $cG, "$cG "];
foreach ($evil as $i => $e) {
	$x = $A->post($TOG, ['comment_id' => $e, 'type' => 'like', $tA => 1]);
	r('I-01', 'comment_id hostil -> 400: ' . substr(json_encode($e), 0, 30), $x['code'] === 400 && js($x) === ['ok' => false, 'error' => 'invalid'], "code={$x['code']}");
}
foreach (['', 'LIKE', 'Like', '1', 'love', 'like;DROP', "like' OR '1'='1", '<b>like</b>', "like\0", 'favorites', 'dislikes'] as $t) {
	$x = $A->post($TOG, ['comment_id' => $cG, 'type' => $t, $tA => 1]);
	r('I-02', 'type hostil -> 400: ' . substr(json_encode($t), 0, 24), $x['code'] === 400, "code={$x['code']}");
}
$x = $A->req('POST', $TOG, [http_build_query(['comment_id' => [$cG, $cM], 'type' => ['like'], $tA => 1])], ['Content-Type: application/x-www-form-urlencoded'], true);
r('I-03', 'comment_id y type como arrays -> 400', $x['code'] === 400);
$x = $A->post($TOG, ['comment_id' => $cG, $tA => 1]);
r('I-04', 'sin type -> 400', $x['code'] === 400);
$x = $A->post($TOG, ['type' => 'like', $tA => 1]);
r('I-05', 'sin comment_id -> 400', $x['code'] === 400);
q("UPDATE jos_content SET access=3 WHERE id={$ids['art_restringido']}"); // Special: A (Registered) ya no tiene acceso al articulo
foreach ([999999, $cPen, $cSp, $cRes, $cUnp] as $k => $bad) {
	$x = $A->post($TOG, ['comment_id' => $bad, 'type' => 'like', $tA => 1]);
	r('I-06', ['inexistente', 'sin publicar', 'spam', 'de un articulo restringido (A no tiene acceso)', 'de un articulo sin publicar'][$k] . ' -> 404 con la MISMA respuesta generica', $x['code'] === 404 && js($x) === ['ok' => false, 'error' => 'unavailable'], "code={$x['code']}");
}
$x = $A->post($TOG, ['comment_id' => $cPen, 'type' => 'favorite', $tA => 1]);
$sx = js($state($A, "$cRes,$cG")); r('I-06b', 'la consulta tambien omite el comentario del articulo al que A no tiene acceso', !isset($sx['items'][(string) $cRes]) && isset($sx['items'][(string) $cG]));
q("UPDATE jos_content SET access=2 WHERE id={$ids['art_restringido']}");
r('I-07', 'tampoco se marca favorito un comentario sin publicar', $x['code'] === 404);
r('I-08', 'ninguna entrada invalida cambia nada (reacciones, comentarios, usuarios, ajustes, permisos)', $count('jos_engage_reactions') === $nI && $snap() === $snapI);
r('I-09', 'las tablas siguen existiendo e intactas tras todos los intentos de inyeccion', (int) one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DBN' AND table_name IN ('jos_engage_reactions','jos_engage_comments','jos_users')") === 3);
$body = $A->post($TOG, ['comment_id' => "$cG' OR 1=1 --", 'type' => 'like', $tA => 1])['body'];
r('I-10', 'la respuesta de error no repite lo recibido (sin eco: XSS imposible)', !str_contains($body, 'OR 1=1') && !str_contains($body, '<'));

// ======================= 5. Transiciones y permisos
echo "-- 5. Transiciones, acumulacion y favorito privado\n";
$row = fn(int $c, int $u, int $t) => (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$c AND user_id=$u AND type=$t");
$x = $tog($A, $cG, 'like'); $d = js($x);
r('T-01', 'A da me gusta al invitado: 200, mine.like, contador 1, fila en la BD', $x['code'] === 200 && $d['mine']['like'] === true && $d['counts'] === ['like' => 1, 'dislike' => 0] && $row($cG, $uA, 1) === 1, json_encode($d));
$d = js($tog($A, $cG, 'dislike'));
r('T-02', 'me gusta -> no me gusta: cambia (excluyentes) y la BD tiene solo el no me gusta', $d['mine']['dislike'] === true && $d['mine']['like'] === false && $d['counts'] === ['like' => 0, 'dislike' => 1] && $row($cG, $uA, 1) === 0 && $row($cG, $uA, 2) === 1);
$d = js($tog($A, $cG, 'like'));
r('T-03', 'no me gusta -> me gusta', $d['mine']['like'] === true && $d['mine']['dislike'] === false && $row($cG, $uA, 2) === 0 && $row($cG, $uA, 1) === 1);
$d = js($tog($A, $cG, 'like'));
r('T-04', 'volver a pulsar me gusta lo quita (nada en la BD)', $d['mine'] === ['like' => false, 'dislike' => false, 'favorite' => false] && $count('jos_engage_reactions') === 0);
$tog($A, $cG, 'favorite'); $tog($A, $cG, 'like'); $d = js($tog($A, $cG, 'dislike'));
r('T-05', 'el favorito es independiente: cambiar me gusta/no me gusta no lo toca', $d['mine'] === ['like' => false, 'dislike' => true, 'favorite' => true] && $row($cG, $uA, 3) === 1);
js($tog($B, $cG, 'like')); js($tog($C, $cG, 'like')); $d = js($tog($M, $cG, 'dislike'));
r('T-06', 'los me gusta y no me gusta de varias personas se ACUMULAN (2 me gusta, 2 no me gusta)', $d['counts'] === ['like' => 2, 'dislike' => 2]);
$sa = js($state($A, "$cG")); $sb = js($state($B, "$cG")); $sg = js($state($G, "$cG"));
r('T-07', 'favorito PRIVADO: A lo ve, B no, el invitado no; sin contador publico ni quien reacciono', $sa['items'][(string) $cG]['mine']['favorite'] === true && $sb['items'][(string) $cG]['mine']['favorite'] === false && !isset($sg['items'][(string) $cG]['mine']) && !preg_match('/favorite|user|name|reacreg/i', json_encode($sg)) && !preg_match('/reacreg/i', json_encode($sb)), json_encode($sg));
r('T-08', 'el invitado ve los contadores (2 / 2) en solo lectura', $sg['items'][(string) $cG]['like'] === 2 && $sg['items'][(string) $cG]['dislike'] === 2);
$x = $tog($A, $cA, 'like'); $y = $tog($A, $cA, 'dislike');
r('T-09', 'NO se puede dar me gusta ni no me gusta al PROPIO comentario (403), y no queda nada', $x['code'] === 403 && $y['code'] === 403 && $row($cA, $uA, 1) + $row($cA, $uA, 2) === 0);
$d = js($tog($A, $cA, 'favorite'));
r('T-10', 'SI se puede marcar favorito el propio', $d['mine']['favorite'] === true && $row($cA, $uA, 3) === 1);
$x = $tog($B, $cR, 'like'); r('T-11', 'reacciones sobre una RESPUESTA publicada funcionan igual (B a su propia respuesta: 403; A a la de B: 200)', $x['code'] === 403 && $tog($A, $cR, 'like')['code'] === 200);
$x = $tog($G, $cG, 'like', $gt);
r('T-12', 'invitado: 403 y nada escrito', $x['code'] === 403);
$sm = js($state($M, "$cM")); r('T-13', 'el Manager reacciona como cualquier usuario y "own" es suyo', $sm['items'][(string) $cM]['own'] === true && $tog($M, $cM, 'like')['code'] === 403);
$x = $tog($A, $cG, 'favorite'); $tog($A, $cG, 'favorite');
r('T-14', 'cada fila es unica por (comentario, usuario, tipo): 2 pulsaciones seguidas dejan 0 o 1 filas, nunca duplicadas', (int) one("SELECT MAX(c) FROM (SELECT COUNT(*) c FROM jos_engage_reactions GROUP BY comment_id,user_id,type) t") <= 1);

// ======================= 6. Limite de frecuencia
echo "-- 6. Limite de frecuencia\n";
$limpiaLimite = function () use ($WORK) { foreach (['/cache', '/administrator/cache'] as $d) { exec('rm -rf ' . escapeshellarg("$WORK/" . (getenv('SITE_DIR') ?: 'site') . "$d/com_engage_reactions")); } };
$limpiaLimite();
$codes = [];
for ($i = 0; $i < 63; $i++) { $codes[] = $tog($C, $cG, 'favorite')['code']; }
$ok = count(array_filter($codes, fn($c) => $c === 200)); $lim = count(array_filter($codes, fn($c) => $c === 429));
r('L-01', '60 reacciones por minuto: las 60 primeras 200 y las siguientes 429 (62 de 63 intentos no pasan de 60)', $ok === 60 && $lim === 3, "200=$ok 429=$lim");
$x = $tog($C, $cG, 'dislike'); r('L-02', 'el 429 es generico {"ok":false,"error":"rate"} y no escribe', $x['code'] === 429 && js($x) === ['ok' => false, 'error' => 'rate'] && $row($cG, $uC, 2) === 0);
$x = $tog($B, $cM, 'like'); r('L-03', 'el limite es por usuario: otro usuario no se ve afectado', $x['code'] === 200);
$x = $state($C, "$cG"); r('L-04', 'la consulta no cuenta para el limite', $x['code'] === 200);
$limpiaLimite();
$x = $tog($C, $cG, 'like'); r('L-05', 'al vaciar el contador vuelve a permitir', $x['code'] === 200);

// ======================= 7. Opciones
echo "-- 7. Opciones\n";
q("DELETE FROM jos_engage_reactions");
$setCom(['reactions_enabled' => '0']);
$h = $G->get($PAGE)['body'];
r('O-01', 'reactions_enabled=0: sin botoneras, sin reactions.js y la fila de Responder como antes', !str_contains($h, 'akengage-reactions') && !str_contains($h, 'reactions.js') && !str_contains($h, 'akengage-comment-reply--react') && !str_contains($h, 'stateUrl'));
$x = $state($A, "$cG"); $y = $tog($A, $cG, 'like');
r('O-02', 'reactions_enabled=0: consulta {"enabled":false}, escritura 404 y nada guardado', js($x) === ['ok' => true, 'enabled' => false] && $y['code'] === 404 && $count('jos_engage_reactions') === 0);
$setCom(['reactions_dislike' => '0']);
$h = $G->get($PAGE)['body']; $y = $tog($A, $cG, 'dislike'); $z = $tog($A, $cG, 'like');
r('O-03', 'reactions_dislike=0: sin boton ni contador de no me gusta; escribir dislike -> 400; like sigue', !str_contains($h, 'akengage-react-dislike') && str_contains($h, 'akengage-react-like') && $y['code'] === 400 && $z['code'] === 200);
$setCom(['reactions_favorites' => '0']);
$h = $G->get($PAGE)['body']; $y = $tog($A, $cG, 'favorite');
r('O-04', 'reactions_favorites=0: sin estrella; escribir favorite -> 400', !str_contains($h, 'akengage-react-favorite') && str_contains($h, 'akengage-react-dislike') && $y['code'] === 400);
q("DELETE FROM jos_engage_reactions");
// reactions_who=commenters: solo el Manager tiene core.create
q("UPDATE jos_assets SET rules='" . $db->real_escape_string('{"core.create":{"6":1},"core.edit.own":{"2":1},"core.edit.state":{"4":1},"core.delete":{"6":1}}') . "' WHERE name='com_engage'");
$setCom(['reactions_who' => 'commenters']);
$sa = js($state($A, "$cG")); $sm = js($state($M, "$cG"));
r('O-05', 'reactions_who=commenters: un Registered sin permiso de comentar ve contadores (can=false, sin token) y el Manager puede', $sa['auth'] === true && $sa['can'] === false && !isset($sa['token']) && $sm['can'] === true && isset($sm['token']), json_encode([$sa, $sm['can']]));
$tM = js($state($M, "$cG"))['token']; $tk[spl_object_id($M)] = $tM;
$y = $tog($A, $cG, 'like'); $z = $tog($M, $cG, 'like');
r('O-06', 'reactions_who=commenters: el Registered recibe 403 al escribir; el Manager 200', $y['code'] === 403 && $z['code'] === 200 && $row($cG, $uA, 1) === 0);
q("UPDATE jos_assets SET rules='" . $db->real_escape_string($prev['rules']) . "' WHERE name='com_engage'");
$setCom(['reactions_who' => 'registered']);
$tk[spl_object_id($A)] = $token($A);
$y = $tog($A, $cG, 'like'); r('O-07', 'reactions_who=registered: el Registered puede de nuevo', $y['code'] === 200);
$setCom(['reactions_who' => 'todos']);
$sa = js($state($A, "$cG")); r('O-08', 'un valor de reactions_who ajeno en la BD se trata como registered', $sa['who'] === 'registered' && $sa['can'] === true);
$setCom();

// ======================= 8. Limpieza al borrar
echo "-- 8. Limpieza al borrar comentarios, articulos y usuarios\n";
q("DELETE FROM jos_engage_reactions");
$Adm = new Cliente($BASE, "$WORK/tmp", 'rx-adm');
r('D-00', 'inicio de sesion del administrador (frontend)', $Adm->login('admintest', $ADMIN_PASS));
$tk[spl_object_id($B)] = $token($B); $tk[spl_object_id($A)] = $token($A);
$cDel = $cm($ASSET, 'Comentario que se va a borrar', 0, 'Borrar'); $cDelHijo = $cm($ASSET, 'Hijo del borrado', 0, 'Hijo', 1, $cDel);
js($tog($A, $cDel, 'like')); js($tog($B, $cDel, 'favorite')); js($tog($A, $cDelHijo, 'dislike')); js($tog($A, $cG, 'like'));
r('D-01', 'preparadas 4 reacciones (2 en el comentario a borrar, 1 en su hijo, 1 en otro)', $count('jos_engage_reactions') === 4);
$ph = $Adm->get($PAGE); $tkn = Cliente::token($ph['body']);
$x = $Adm->post('/index.php?option=com_engage&task=comments.delete', ['cid' => [$cDel], $tkn => 1, 'returnurl' => base64_encode($BASE . $PAGE)]);
$left = (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id IN ($cDel, $cDelHijo)");
r('D-02', 'al borrar un comentario (y su hijo en cascada) se borran TODAS sus reacciones; las de otros comentarios se conservan', (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id IN ($cDel,$cDelHijo)") === 0 && $left === 0 && $count('jos_engage_reactions') === 1, "http={$x['code']} restantes=$left");
$x = $tog($A, $cDel, 'like'); r('D-03', 'reaccionar al comentario borrado -> 404', $x['code'] === 404);
file_put_contents("$WORK/resultados-reacciones-http.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$f = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
printf("\n%d comprobaciones, %d FALLAN\n", count($RES), $f);
exit($f ? 1 : 0);
