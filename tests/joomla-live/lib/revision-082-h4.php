<?php
/**
 * 0.8.3, H4 contra el Joomla REAL: Gravatar sin rastro del correo antes del consentimiento. El HTML publico (modo ask) solo lleva una referencia opaca y la URL
 * real sale de `avatar.resolve` (POST + token CSRF, ACL de ver el comentario, limite de frecuencia). Comprueba por HTTP, como invitado, Registered y Manager:
 *   1) HTML: ni hash (sha256/md5), ni correo, ni URL de Gravatar; la referencia es IGUAL para todos (cache de pagina compatible) y es el HMAC esperado
 *   2) endpoint: token, CSRF (sin token, token falso, token de otra sesion, GET, cross-site), respuesta por comentario (publicado si; sin publicar, spam, papelera,
 *      de contenido restringido para quien no lo ve, token falso, id inexistente: null IDENTICO), tamanos y mezclas, limite de frecuencia 429, tareas ajenas 403
 *   3) modo always: el comportamiento anterior (el administrador lo eligio) sigue en el HTML, y el modo off no menciona a Gravatar
 * Restaura los parametros del plugin al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
$uid = ['reg' => usuario('r3reg', 2), 'reg2' => usuario('r3reg2', 2), 'mgr' => usuario('r3mgr', 6)];
$C   = siembra($uid);
$secretCfg = preg_match("/public \\\$secret = '([^']*)';/", file_get_contents("$SITE/configuration.php"), $m) ? $m[1] : '';
$plugPrev = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='gravatar' AND folder='engage'");
$setMode = function (string $mode) { q("UPDATE jos_extensions SET params='" . esc(json_encode(['mode' => $mode, 'rating' => 'g', 'default_image' => 'identicon'])) . "' WHERE element='gravatar' AND folder='engage'"); };
register_shutdown_function(function () use ($plugPrev) { q("UPDATE jos_extensions SET params='" . esc((string) $plugPrev) . "' WHERE element='gravatar' AND folder='engage'"); q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%' OR body LIKE '%R3B-%'"); borraUsuarios(); });
$setMode('ask');
@mkdir("$WORK/tmp");
$g = new Cliente($BASE, "$WORK/tmp", 'r3g'); $reg = new Cliente($BASE, "$WORK/tmp", 'r3reg'); $reg2 = new Cliente($BASE, "$WORK/tmp", 'r3reg2'); $mgr = new Cliente($BASE, "$WORK/tmp", 'r3mgr');
$reg->login('r3reg', $PASS); $reg2->login('r3reg2', $PASS); $mgr->login('r3mgr', $PASS);
$actores = ['invitado' => $g, 'registrado' => $reg2, 'autor' => $reg, 'manager' => $mgr];
$OP = '/index.php?option=com_engage';
$tokenSitio = fn(int $id, int $size = 48) => substr(hash_hmac('sha256', "engage-gravatar-v1|$id|$size", $secretCfg), 0, 32);
r('H4-00', 'Hay secreto en configuration.php (para calcular las referencias esperadas)', $secretCfg !== '');
$emails = []; foreach (q("SELECT id, email FROM jos_engage_comments WHERE name LIKE 'R3N-%'")->fetch_all(MYSQLI_ASSOC) as $x) { $emails[] = $x['email']; }
$emails[] = 'r3reg@example.invalid'; $emails[] = 'r3reg2@example.invalid'; $emails[] = 'r3mgr@example.invalid';

// ------------------------------------------------------------------ 1) HTML
$paginas = [];
foreach ($actores as $n => $c) { $paginas[$n] = $c->get($PAGE)['body']; }
$rastros = [];
foreach ($paginas as $n => $h) {
	foreach ($emails as $e) { foreach ([$e, hash('sha256', strtolower($e)), md5(strtolower($e))] as $s) { if ($s !== '' && stripos($h, $s) !== false && !($n === 'manager' && $s === $e)) { $rastros[] = "$n " . substr($s, 0, 20); } } }
	if (preg_match('#gravatar\.com/avatar/#i', $h)) { $rastros[] = "$n URL de gravatar.com/avatar"; }
}
r('H4-01', 'HTML del articulo (invitado, Registered, autor, Manager; modo ask): ningun hash sha256/md5 del correo, ninguna URL de gravatar.com/avatar y ningun correo (salvo el que el Manager ve como moderador)', !$rastros, implode(' | ', array_slice($rastros, 0, 4)));
$refs = [];
foreach ($paginas as $n => $h) { preg_match_all('#<img[^>]*data-engage-gravatar="([^"]+)"#', $h, $mm); $refs[$n] = $mm[1]; }
// El Manager ve ademas comentarios sin publicar: se compara la referencia de cada comentario que ve cada actor con la del invitado (la misma para todos)
$ok = count($refs['invitado']) >= 2;
foreach ($refs as $r0) { foreach ($r0 as $rf) { if (in_array(explode('.', $rf)[0], array_map(fn($x) => explode('.', $x)[0], $refs['invitado']), true)) { $ok = $ok && in_array($rf, $refs['invitado'], true); } } }
r('H4-02', 'La referencia de cada avatar es IGUAL para todos los visitantes (el HTML sigue siendo cacheable por Joomla)', $ok, count($refs['invitado']) . ' avatares');
$esp = true; foreach ($refs['invitado'] as $rf) { if (!preg_match('/^([1-9][0-9]*)\.48\.([0-9a-f]{32})$/', $rf, $m3) || !hash_equals($tokenSitio((int) $m3[1]), $m3[2])) { $esp = false; } }
r('H4-03', 'Cada referencia es <id>.48.<HMAC-SHA256 del id con el secreto del sitio, 32 hex> (se recalcula aparte y coincide)', $esp && $secretCfg !== '');
$ids48 = array_map(fn($rf) => (int) explode('.', $rf)[0], $refs['invitado']);
r('H4-04', 'Solo salen referencias de los comentarios publicados que el visitante ve (no del spam, sin publicar ni papelera)', !array_intersect($ids48, [$C['spam'], $C['sinpublicar'], $C['papelera'], $C['restringido'], $C['artsinpublicar']]) && in_array($C['publicado'], $ids48, true), 'ids=' . implode(',', $ids48));

// ------------------------------------------------------------------ 2) Endpoint
$tk = fn(Cliente $c) => ($j = json_decode($c->get("$OP&task=avatar.token")['body'], true)) && isset($j['token']) ? $j['token'] : '';
$resolve = function (Cliente $c, array $refs, ?string $token = null, array $cab = []) use ($OP, $tk) {
	$token = $token ?? $tk($c);
	$x = $c->post("$OP&task=avatar.resolve", ['c' => implode(',', $refs), $token => 1], $cab);
	return [$x['code'], json_decode($x['body'], true), $x];
};
// Si una ejecucion anterior agoto el cupo (240 por minuto desde esta IP), se espera a que la ventana pase
for ($i = 0; $i < 80; $i++) { $tt = json_decode($g->get("$OP&task=avatar.token")['body'], true)['token'] ?? ''; $x = $g->post("$OP&task=avatar.resolve", ['c' => '1.48.' . str_repeat('0', 32), $tt => 1]); if ($x['code'] !== 429) { break; } sleep(1); }
$t0 = $g->get("$OP&task=avatar.token");
r('H4-05', 'avatar.token (GET): 200, JSON con un token de 32 hex y respuesta sin cache (no-store, Vary: Cookie)', $t0['code'] === 200 && preg_match('/^[0-9a-f]{32}$/', json_decode($t0['body'], true)['token'] ?? '') === 1 && stripos($t0['headers'], 'no-store') !== false && stripos($t0['headers'], 'Vary: Cookie') !== false, "HTTP {$t0['code']}");
$ref = fn(int $id) => "$id.48." . $tokenSitio($id);
$pedir = [$ref($C['publicado']), $ref($C['spam']), $ref($C['sinpublicar']), $ref($C['papelera']), $ref($C['restringido']), $ref($C['artsinpublicar']), $ref($C['delregistrado']), '999999.48.' . $tokenSitio(999999)];
foreach ($actores as $n => $c) {
	[$code, $j] = $resolve($c, $pedir);
	$u = (array) ($j['urls'] ?? []);
	$resumen = json_encode(array_map(fn($v) => $v === null ? null : preg_replace('~avatar/[0-9a-f]{10}[0-9a-f]+~', 'avatar/HASH', $v), $u), JSON_UNESCAPED_SLASHES) . ' C=' . json_encode($C);
	$esperadoPub = 'https://www.gravatar.com/avatar/' . hash('sha256', 'r3mail-publicado@privado.invalid') . '?s=48&r=g&d=identicon';
	$bien = $code === 200 && ($u[$C['publicado'] . '.48'] ?? '') === $esperadoPub
		&& ($u[$C['delregistrado'] . '.48'] ?? '') === 'https://www.gravatar.com/avatar/' . hash('sha256', 'r3reg@example.invalid') . '?s=48&r=g&d=identicon';
	foreach (['spam', 'sinpublicar', 'papelera', 'restringido', 'artsinpublicar'] as $k) {
		// Mismo criterio que las reacciones y el hilo: el contenido restringido (nivel Registered) lo ve quien tiene el nivel; el articulo sin publicar, solo quien modera (core.edit.state)
		$visible = ($k === 'restringido' && in_array($n, ['registrado', 'autor', 'manager'], true)) || ($k === 'artsinpublicar' && $n === 'manager');
		$kk = $C[$k] . '.48';
		if (!$visible && !(array_key_exists($kk, $u) && $u[$kk] === null)) { $bien = false; }
		if ($visible && ($u[$kk] ?? null) === null) { $bien = false; }
	}
	if (!(array_key_exists('999999.48', $u) && $u['999999.48'] === null)) { $bien = false; }
	r("H4-06-$n", "avatar.resolve como $n: URL solo de los visibles (publicado y del registrado); spam, sin publicar, papelera, articulo sin publicar, inexistente y (para el invitado) contenido restringido = null", $bien, "HTTP $code $resumen");
}
// identico para «no existe», «no visible» y «token falso»
[, $a] = $resolve($g, ['999999.48.' . $tokenSitio(999999)]); [, $b] = $resolve($g, [$ref($C['spam'])]); [, $c3] = $resolve($g, [$C['publicado'] . '.48.' . str_repeat('0', 32)]);
r('H4-07', 'Para el invitado, un id inexistente, un comentario sin ver y un token falso dan EXACTAMENTE la misma respuesta (una entrada null)', array_values((array) $a['urls']) === [null] && array_values((array) $b['urls']) === [null] && array_values((array) $c3['urls']) === [null]);
// CSRF
$cs = [];
[$code] = $resolve($g, [$ref($C['publicado'])], 'f' . str_repeat('0', 31)); $cs[] = "token falso=$code";
$x = $g->post("$OP&task=avatar.resolve", ['c' => $ref($C['publicado'])]); $cs[] = "sin token={$x['code']}";
$tokReg = $tk($reg2); [$code] = $resolve($g, [$ref($C['publicado'])], $tokReg); $cs[] = "token de otra sesion=$code";
$x = $g->get("$OP&task=avatar.resolve&c=" . $ref($C['publicado']) . '&' . $tk($g) . '=1'); $cs[] = "GET={$x['code']}";
$x = $g->post("$OP&task=avatar.resolve&" . $tk($g) . '=1', ['c' => $ref($C['publicado'])]); $cs[] = "token en la URL={$x['code']}";
$x = $g->post("$OP&task=avatar.resolve", ['c' => $ref($C['publicado']), $tk($g) => 1], ['Sec-Fetch-Site: cross-site']); $cs[] = "cross-site={$x['code']}";
$x = $g->get("$OP&task=avatar.token", ['Sec-Fetch-Site: cross-site']); $cs[] = "token cross-site={$x['code']}";
r('H4-08', 'CSRF y metodo: token falso, sin token, token de OTRA sesion, GET, token en la URL y cabecera cross-site se rechazan (403/405)', $cs === ['token falso=403', 'sin token=403', 'token de otra sesion=403', 'GET=405', 'token en la URL=403', 'cross-site=403', 'token cross-site=403'], implode(', ', $cs));
// entradas hostiles
$mal = [];
foreach (['', 'x', '1.48', str_repeat('1.48.' . str_repeat('a', 32) . ',', 3), "1.48." . $tokenSitio(1) . "\n", '1.5000.' . $tokenSitio(1), "1.48.' OR 1=1--", '<script>', implode(',', array_map(fn($i) => "$i.48." . $tokenSitio($i), range(1, 101)))] as $x) {
	[$code, $j, $raw] = $resolve($g, [$x]);
	if ($code !== 400 || ($j['ok'] ?? true) !== false || stripos($raw['body'], $x === '' ? 'zzzz' : substr($x, 0, 12)) !== false && strlen($x) > 8) { $mal[] = json_encode(substr($x, 0, 20)) . "=$code"; }
}
r('H4-09', 'Peticiones mal formadas (vacia, basura, sin token, tamano enorme, SQL, HTML, 101 referencias): 400 generico que no repite nada de lo recibido', !$mal, implode(' | ', array_slice($mal, 0, 4)));
// otras tareas
$ot = []; foreach (['display', 'main', 'getModel', 'execute', 'foo', ''] as $t) { $x = $g->get("$OP&task=avatar." . $t); if ($x['code'] !== 403) { $ot[] = "$t={$x['code']}"; } }
$x = $g->get("$OP&controller=avatar"); if ($x['code'] !== 403) { $ot[] = "controller=avatar {$x['code']}"; }
r('H4-10', 'Cualquier otra tarea del controlador avatar (display, main, getModel...) = 403', !$ot, implode(' | ', $ot));
// limite de frecuencia: un invitado, 260 peticiones seguidas
$cl = new Cliente($BASE, "$WORK/tmp", 'r3rl'); $tkRl = $tk($cl); $n429 = 0; $n200 = 0;
for ($i = 0; $i < 270; $i++) { $x = $cl->post("$OP&task=avatar.resolve", ['c' => $ref($C['publicado']), $tkRl => 1]); if ($x['code'] === 429) { $n429++; } elseif ($x['code'] === 200) { $n200++; } }
r('H4-11', 'Limite de frecuencia: 270 peticiones seguidas de un invitado = unas 240 correctas y el resto 429', $n429 >= 10 && $n200 >= 200 && $n200 <= 245, "200=$n200 429=$n429");
usleep(1500000);
// ------------------------------------------------------------------ 3) Modos
$setMode('always'); usleep(500000);
$h = (new Cliente($BASE, "$WORK/tmp", 'r3a'))->get($PAGE)['body'];
r('H4-12', 'Modo always (lo eligio el administrador; documentado): el comportamiento anterior no cambia, la URL con el hash sigue en src y no hay data-engage-gravatar', preg_match('#<img src="https://www\.gravatar\.com/avatar/' . hash('sha256', 'r3mail-publicado@privado.invalid') . '\?#', $h) === 1 && strpos($h, 'data-engage-gravatar=') === false);
$setMode('off'); usleep(500000);
$h = (new Cliente($BASE, "$WORK/tmp", 'r3o'))->get($PAGE)['body'];
r('H4-13', 'Modo off: el HTML no menciona gravatar.com ni lleva data-engage-gravatar', stripos($h, 'gravatar.com') === false && strpos($h, 'data-engage-gravatar') === false);
$setMode('ask');
$log = logNuevo(0);
resumen('h4');
