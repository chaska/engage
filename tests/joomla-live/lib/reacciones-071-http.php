<?php
/**
 * 0.7.1: ataque a las reacciones contra un Joomla REAL por HTTP (curl; invitado, Registered x2 y Manager). Cubre lo que corrige la 0.7.1:
 *  MEDIO-2  ACL de categoria: la reaccion y la consulta masiva deben dar a cada persona lo MISMO que la pagina del articulo
 *           (categoria Special, con otro nivel, sin publicar, en la papelera, archivada, y categoria PADRE sin publicar o Special).
 *  BAJO-1   un comentario de invitado con el email del usuario es «propio» (no se puede dar me gusta) y, al iniciar sesion, el me gusta
 *           previo se elimina; un me gusta propio que quedara no cuenta ni se muestra.
 *  BAJO-2   con «No me gusta» desactivado, la respuesta de toggle tampoco revela counts.dislike.
 *  BAJO-3   la consulta masiva acota los contenidos DISTINTOS por peticion (10) y tiene un limite de frecuencia por usuario/IP (240/min).
 * Requisitos: 03-sembrar-y-probar.sh ($WORK/ids.json), siembra-categorias-071.php ($WORK/ids-071.json) y el paquete instalado.
 * Escribe $WORK/resultados-reacciones-071-http.json. Sale con 1 si algo FALLA. Restaura todo al acabar.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$i71  = json_decode(@file_get_contents("$WORK/ids-071.json") ?: '', true) ?: die("Falta $WORK/ids-071.json (ejecuta siembra-categorias-071.php)\n");
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

$ART = (int) $i71['art']; $ASSET = (int) $i71['art_asset']; $H = (int) $i71['cat_hija']; $P = (int) $i71['cat_padre'];
$PAGE = "/index.php?option=com_content&view=article&id=$ART&catid=$H";
$PAGE_PUB = "/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}";
$PUBASSET = (int) $ids['art_publico_asset'];
$PASS = trim(file_get_contents("$WORK/reaccionespass.txt") ?: '') ?: die("Falta reaccionespass.txt (ejecuta antes reacciones-http.php)\n");

$prev = [
	'com'  => one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"),
	'cats' => q("SELECT id,published,access FROM jos_categories WHERE id IN ($H,$P)")->fetch_all(MYSQLI_ASSOC),
];
$resetLimite = function () use ($WORK) { foreach (['/cache', '/administrator/cache'] as $d) { exec('rm -rf ' . escapeshellarg("$WORK/" . (getenv('SITE_DIR') ?: 'site') . "$d/com_engage_reactions")); } };
$setCom = function (array $extra = []) use ($db) {
	$p = array_merge(['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc'], $extra);
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
};
$setCat = function (int $id, int $published, int $access) { q("UPDATE jos_categories SET published=$published, access=$access WHERE id=$id"); };
$restaurar = function () use ($db, $prev, $ASSET, $PUBASSET, $resetLimite) {
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string($prev['com']) . "' WHERE element='com_engage' AND type='component'");
	foreach ($prev['cats'] as $c) { q("UPDATE jos_categories SET published={$c['published']}, access={$c['access']} WHERE id={$c['id']}"); }
	q("DELETE FROM jos_engage_comments WHERE asset_id IN ($ASSET,$PUBASSET) OR asset_id >= 900001");
	q("DELETE FROM jos_engage_reactions");
	$resetLimite();
};
register_shutdown_function(function () use ($restaurar) { $restaurar(); });

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
$uA = $mkUser('reacreg1', 2); $uB = $mkUser('reacreg2', 2); $uM = $mkUser('reacmgr', 6);
$cm = function (int $asset, string $body, int $by, string $name = '', string $email = '', int $enabled = 1) use ($db) {
	$nm = $by ? 'NULL' : "'$name'"; $em = $by ? 'NULL' : "'" . ($email !== '' ? $email : strtolower($name) . "@example.invalid") . "'";
	q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset,'" . $db->real_escape_string("<p>$body</p>") . "',$nm,$em,'203.0.113.7','t',$enabled,NOW(),$by)");
	return (int) $db->insert_id;
};
q("DELETE FROM jos_engage_comments WHERE asset_id IN ($ASSET,$PUBASSET) OR asset_id >= 900001"); q("DELETE FROM jos_engage_reactions");
$setCom(); $setCat($H, 1, 1); $setCat($P, 1, 1); $resetLimite();

$G = new Cliente($BASE, "$WORK/tmp", 'r71-guest'); $A = new Cliente($BASE, "$WORK/tmp", 'r71-a'); $M = new Cliente($BASE, "$WORK/tmp", 'r71-m');
r('X-00', 'inicio de sesion de reacreg1 (Registered) y reacmgr (Manager)', $A->login('reacreg1', $PASS) && $M->login('reacmgr', $PASS));
$STATE = '/index.php?option=com_engage&task=reactions.state&format=json&ids=';
$TOG   = '/index.php?option=com_engage&task=reactions.toggle&format=json';
$state = fn(Cliente $c, string $idsq) => $c->get($STATE . $idsq);
$tokens = [];
$tok = function (Cliente $c) use (&$tokens, $state, $PUBASSET, $cm) {
	$k = spl_object_id($c);
	if (!isset($tokens[$k])) { static $tmp = null; $tmp = $tmp ?? $cm($PUBASSET, 'x', 0, 'Token', '', 1); $tokens[$k] = js($state($c, (string) $tmp))['token'] ?? ''; }
	return $tokens[$k];
};
$tog = fn(Cliente $c, $cid, $type) => $c->post($TOG, ['comment_id' => $cid, 'type' => $type, $tok($c) => 1]);
$vis = fn(Cliente $c, int $cid) => isset((js($state($c, (string) $cid))['items'] ?? [])[(string) $cid]);

// ======================= 1. MEDIO-2: ACL de categoria (lo que dice la pagina == lo que dice Engage)
echo "-- 1. Categorias (Special, otro nivel, sin publicar, papelera, archivada, padre)\n";
$cG = $cm($ASSET, 'Comentario de invitado en el articulo de la categoria hija', 0, 'InvCat');
$esc = [
	// [id, descripcion, [categoria => [published, access]], pagina visible esperada por actor [G, A, M] segun JOOMLA (se mide y se compara; null = solo se compara con la pagina)]
	['K-01', 'categoria publica (control)', [$H => [1, 1], $P => [1, 1]], [true, true, true]],
	['K-02', 'categoria hija con nivel Special (Manager si lo tiene, Registered no)', [$H => [1, 3], $P => [1, 1]], [false, false, true]],
	['K-03', 'categoria hija con nivel Registered', [$H => [1, 2], $P => [1, 1]], [false, true, true]],
	['K-04', 'categoria hija SIN PUBLICAR (ni el Manager la ve)', [$H => [0, 1], $P => [1, 1]], [false, false, false]],
	['K-05', 'categoria hija en la PAPELERA', [$H => [-2, 1], $P => [1, 1]], [false, false, false]],
	['K-06', 'categoria hija ARCHIVADA (Joomla la sigue sirviendo)', [$H => [2, 1], $P => [1, 1]], [true, true, true]],
	['K-07', 'categoria PADRE sin publicar (hija publicada): lo que haga la pagina', [$H => [1, 1], $P => [0, 1]], null],
	['K-08', 'categoria PADRE con nivel Special (hija publica): lo que haga la pagina', [$H => [1, 1], $P => [1, 3]], null],
	['K-09', 'categoria PADRE en la papelera (hija publicada): lo que haga la pagina', [$H => [1, 1], $P => [-2, 1]], null],
];
$actores = ['invitado' => $G, 'Registered' => $A, 'Manager' => $M];
foreach ($esc as [$id, $desc, $cats, $esp]) {
	foreach ($cats as $cid => [$pub, $acc]) { $setCat($cid, $pub, $acc); }
	$resetLimite();
	$i = 0; $okPag = true; $okEng = true; $ev = []; $okTog = true;
	foreach ($actores as $nom => $c) {
		$pg = $c->get($PAGE); $paginaOk = ($pg['code'] === 200 && str_contains($pg['body'], 'Texto de prueba de categorias'));
		$engOk = $vis($c, $cG);
		if ($esp !== null && $paginaOk !== $esp[$i]) { $okPag = false; }
		if ($engOk !== $paginaOk) { $okEng = false; }
		if ($c !== $G) {
			$t = $tog($c, $cG, 'like'); $hecho = ($t['code'] === 200);
			if ($hecho !== $paginaOk || (!$paginaOk && $t['code'] !== 404)) { $okTog = false; }
			if ($hecho) { $tog($c, $cG, 'like'); }   // deshace
		}
		$ev[] = "$nom:pag=" . ($paginaOk ? 'si' : $pg['code']) . ',consulta=' . ($engOk ? 'si' : 'no');
		$i++;
	}
	if ($esp !== null) { r($id . 'a', "$desc: la PAGINA responde como se espera (comprobacion del propio ataque)", $okPag, implode(' ', $ev)); }
	r($id . 'b', "$desc: la consulta masiva (state) devuelve el comentario SOLO a quien ve la pagina", $okEng, implode(' ', $ev));
	r($id . 'c', "$desc: reaccionar (toggle) funciona SOLO a quien ve la pagina; a los demas 404 generico", $okTog);
}
$setCat($H, 1, 1); $setCat($P, 1, 1);
$ok1 = $vis($G, $cG) && $tog($A, $cG, 'like')['code'] === 200 && $tog($M, $cG, 'like')['code'] === 200;
r('K-10', 'regresion: articulo publico en categorias publicas: invitado lo ve, y Registered y Manager reaccionan', $ok1);
q("DELETE FROM jos_engage_reactions");
$cP = $cm($PUBASSET, 'Comentario en el articulo publico de siempre', 0, 'InvPub');
r('K-11', 'regresion: el articulo publico de las pruebas anteriores sigue igual (invitado ve, Registered reacciona)', $vis($G, $cP) && $tog($A, $cP, 'like')['code'] === 200);
q("DELETE FROM jos_engage_reactions");

// ======================= 2. BAJO-1: comentario propio por email
echo "-- 2. Comentario propio por email verificado de la sesion\n";
$setCom();
$g1 = $cm($ASSET, 'Invitado con el email del usuario A', 0, 'Suplantador', 'reacreg1@example.invalid');
$t1 = $tog($A, $g1, 'like'); $t2 = $tog($A, $g1, 'dislike'); $t3 = $tog($A, $g1, 'favorite');
r('P-01', 'comentario de INVITADO con el email de A: A no puede darle me gusta ni no me gusta (403), como al propio por created_by', $t1['code'] === 403 && $t2['code'] === 403 && !(int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$g1 AND type IN (1,2)"), "like={$t1['code']} dislike={$t2['code']}");
r('P-02', 'sigue pudiendo marcarlo como favorito (privado)', $t3['code'] === 200);
$st = js($state($A, (string) $g1));
r('P-03', 'la consulta lo marca como propio (own = true) para A', ($st['items'][(string) $g1]['own'] ?? null) === true, json_encode($st['items'][(string) $g1] ?? null));
$stB = js($state($M, (string) $g1));
r('P-04', 'para otra persona (Manager) NO es propio y puede reaccionar', ($stB['items'][(string) $g1]['own'] ?? null) === false && $tog($M, $g1, 'like')['code'] === 200);
q("DELETE FROM jos_engage_reactions");
// El me gusta previo y el cambio de propietario al iniciar sesion
$x = $cm($ASSET, 'Invitado ajeno al que A da me gusta', 0, 'Otro', 'otro.x@example.invalid');
$l = $tog($A, $x, 'like'); $l2 = $tog($A, $x, 'favorite');
r('P-05', '(preparacion) A da me gusta y favorito a un comentario de invitado ajeno', $l['code'] === 200 && $l2['code'] === 200 && (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$x AND user_id=$uA") === 2);
q("UPDATE jos_engage_comments SET email='reacreg1@example.invalid' WHERE id=$x");   // ahora el comentario lleva el email de A
$A2 = new Cliente($BASE, "$WORK/tmp", 'r71-a2');
$A2->login('reacreg1', $PASS);                                                        // onUserLogin -> el comentario pasa a ser de A
$dueno = (int) one("SELECT created_by FROM jos_engage_comments WHERE id=$x");
r('P-06', 'al iniciar sesion el comentario de invitado con el email de A pasa a ser de A (created_by)', $dueno === $uA, "created_by=$dueno");
$likes = (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$x AND user_id=$uA AND type IN (1,2)");
$favs  = (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$x AND user_id=$uA AND type=3");
r('P-07', 'el me gusta previo de A sobre su (ahora) propio comentario se ELIMINA; el favorito se conserva', $likes === 0 && $favs === 1, "me gusta/no me gusta=$likes favorito=$favs");
$stA = js($state($A2, (string) $x)); $it = $stA['items'][(string) $x] ?? [];
r('P-08', 'la consulta de A no muestra me gusta activo en su comentario y el contador es 0', ($it['mine']['like'] ?? true) === false && ($it['like'] ?? 9) === 0 && ($it['own'] ?? false) === true, json_encode($it));
// Un me gusta propio que quedara (datos antiguos o escrito por otra via) no cuenta ni se muestra
q("INSERT IGNORE INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($x,$uA,1,NOW())");
$stA = js($state($A2, (string) $x)); $it = $stA['items'][(string) $x] ?? [];
$stG = js($state($G, (string) $x)); $ig = $stG['items'][(string) $x] ?? [];
r('P-09', 'un me gusta propio que quedara en la base de datos no se muestra como activo (mine.like = false)', ($it['mine']['like'] ?? true) === false, json_encode($it));
r('P-10', '...ni cuenta para los demas (contador publico 0)', ($ig['like'] ?? 9) === 0, json_encode($ig));
q("DELETE FROM jos_engage_reactions");

// ======================= 3. BAJO-2: toggle no revela counts.dislike
echo "-- 3. Contador de no me gusta con la opcion desactivada\n";
$setCom();
$d1 = $cm($PUBASSET, 'Comentario con no me gusta de otros', 0, 'ConDislike');
$tog($M, $d1, 'dislike');
$on = js($tog($A2, $d1, 'like')) ?? []; $tog($A2, $d1, 'like');
r('D-01', '(control) con la opcion activada toggle devuelve counts.dislike = 1', ($on['counts']['dislike'] ?? -1) === 1, json_encode($on['counts'] ?? null));
$setCom(['reactions_dislike' => '0']);
$off = js($tog($A2, $d1, 'like')) ?? [];
r('D-02', 'con «No me gusta: No» toggle devuelve counts.dislike = 0 (antes revelaba el numero real)', ($off['counts']['dislike'] ?? -1) === 0 && ($off['counts']['like'] ?? -1) === 1, json_encode($off['counts'] ?? null));
$so = js($state($A2, (string) $d1)); 
r('D-03', 'y state tambien devuelve 0 (coherente)', ($so['items'][(string) $d1]['dislike'] ?? -1) === 0);
$setCom(); q("DELETE FROM jos_engage_reactions");

// ======================= 4. BAJO-3: amplificacion de state
echo "-- 4. Consulta masiva: contenidos distintos y frecuencia\n";
$cids = [];
for ($k = 1; $k <= 12; $k++) { $cids[] = $cm(900000 + $k, "fantasma $k", 0, "Fantasma$k"); }
$idsOk = implode(',', array_merge([$cG], array_slice($cids, 0, 9)));      // 10 contenidos distintos (1 real + 9)
$idsMal = implode(',', array_merge([$cG], array_slice($cids, 0, 10)));    // 11 distintos
$x10 = $state($G, $idsOk); $x11 = $state($G, $idsMal); $xa = $state($A2, $idsMal);
r('R-01', 'hasta 10 contenidos distintos por peticion se aceptan (200)', $x10['code'] === 200, "code={$x10['code']}");
r('R-02', '11 contenidos distintos se rechazan enteros con 400 generico (invitado y registrado)', $x11['code'] === 400 && $xa['code'] === 400 && js($x11) === ['ok' => false, 'error' => 'invalid'], "g={$x11['code']} a={$xa['code']}");
$many = $state($G, implode(',', array_slice($cids, 0, 12)));
r('R-03', '12 comentarios de 12 contenidos: 400', $many['code'] === 400);
$mismo = [$cm($PUBASSET, 'a', 0, 'M1'), $cm($PUBASSET, 'b', 0, 'M2'), $cm($PUBASSET, 'c', 0, 'M3')];
r('R-04', 'muchos comentarios del MISMO contenido (el caso real de una pagina) siguen aceptandose', $state($G, implode(',', $mismo))['code'] === 200);
$resetLimite();
$n429 = 0; $primero = 0; $total = 260; $okAntes = 0;
for ($k = 1; $k <= $total; $k++) { $z = $state($G, (string) $cG); if ($z['code'] === 429) { $n429++; $primero = $primero ?: $k; } elseif ($z['code'] === 200) { $okAntes++; } }
$gen = js($z) ?? [];
r('R-05', "invitado: $total consultas seguidas -> las primeras 240 pasan y el resto 429 (limite por IP)", $okAntes === 240 && $n429 === $total - 240 && $primero === 241, "200=$okAntes 429=$n429 primera429=#$primero");
r('R-06', 'el 429 es generico (cuerpo fijo, sin pistas) y sin cache', ($gen['error'] ?? '') === 'rate' && str_contains((string) preg_replace('/\s+/', ' ', $z['headers']), 'no-store'), $z['body']);
$za = $state($A2, (string) $cG);
r('R-07', 'el limite es por persona: otro usuario con sesion (misma IP) sigue pudiendo consultar', $za['code'] === 200);
$resetLimite();
$zg = $state($G, (string) $cG);
r('R-08', 'pasada la ventana (aqui: limite reiniciado) el invitado vuelve a poder consultar', $zg['code'] === 200);
$resetLimite();

file_put_contents("$WORK/resultados-reacciones-071-http.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$f = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
printf("\n%d comprobaciones, %d FALLAN\n", count($RES), $f);
exit($f ? 1 : 0);
