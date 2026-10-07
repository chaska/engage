<?php
/**
 * 0.8.0 (tanda 2): selector de orden, filtro «solo mis favoritos», copiar enlace e insignias contra un Joomla REAL por HTTP (curl) con invitado,
 * Registered, Manager, Editor y el super administrador. Comprueba:
 *  - la barra del HTML (enlaces con rel="nofollow", aria-current, conmutador de favoritos oculto, mismo HTML para dos invitados, canonical);
 *  - el ORDEN servido frente a un calculo independiente desde la BD (newest / oldest / top, comments_ordering asc/desc, max_level 1/2/3/6, respuestas
 *    cronologicas bajo su padre, paginacion, akengage_cid con orden) y 60+ valores de ataque en akengage_sort y akengage_fav;
 *  - «solo mis favoritos» (lista plana, publicados, solo los del usuario, invitado ignorado, cabeceras sin cache, noindex, vacia, paginacion);
 *  - las opciones (sort_selector, default_sort, copy_link, show_badges, reacciones apagadas, favoritos apagados) y su validacion en el panel;
 *  - insignias (autor, moderador, ambas, bloqueado, sin nombres de grupo, la estrella existente no se repite) y el boton Copiar (URL absoluta
 *    validada y escapada, sin parametros personales, Host hostil);
 *  - la cache de pagina de Joomla y la cache conservadora con el plugin engagecache: una URL por orden y nunca estado de usuario en el HTML.
 * Requisitos: 03-sembrar-y-probar.sh ($WORK/ids.json) y el paquete instalado (02). Escribe $WORK/resultados-tanda2-http.json. Sale con 1 si algo FALLA.
 * Restaura parametros, permisos, autor del articulo y configuracion al acabar. Contrasenas al azar en $WORK/tanda2pass.txt (no en el repo).
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$SITE = $WORK . '/' . (getenv('SITE_DIR') ?: 'site');
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$DBN  = getenv('DB_NAME') ?: 'joomla_test';
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), $DBN);
$db->set_charset('utf8mb4');
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-8s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [" . substr($ev, 0, 240) . "]" : '');
}
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
function hdr(array $resp, string $h): string { return preg_match('/^' . preg_quote($h, '/') . ':\s*(.+?)\r?$/mi', $resp['headers'], $m) ? trim($m[1]) : ''; }

$ASSET = (int) $ids['art_publico_asset'];
$PAGE  = "/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}";
$passFile = "$WORK/tanda2pass.txt";
if (!is_file($passFile)) { file_put_contents($passFile, 'Aa1!' . bin2hex(random_bytes(10))); chmod($passFile, 0600); }
$PASS = trim(file_get_contents($passFile));
$ADMIN_PASS = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
$cfgPath = "$SITE/configuration.php";
$cfgOrig = file_get_contents($cfgPath);

// ---- Estado original (se restaura al final)
$prev = [
	'com'    => one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"),
	'rules'  => one("SELECT rules FROM jos_assets WHERE name='com_engage'"),
	'author' => (int) one("SELECT created_by FROM jos_content WHERE id=" . (int) $ids['art_publico']),
	'cache'  => q("SELECT enabled, IFNULL(params,'') FROM jos_extensions WHERE element='cache' AND folder='system'")->fetch_row(),
	'ecache' => q("SELECT enabled FROM jos_extensions WHERE element='engagecache' AND folder='system'")->fetch_row(),
];
$setCom = function (array $extra = []) use ($db) {
	$p = array_merge(['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100'], $extra);
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
};
$limpiaCache = function () use ($SITE) { foreach (['cache', 'administrator/cache'] as $d) { foreach (glob("$SITE/$d/*", GLOB_ONLYDIR) ?: [] as $g) { exec('rm -rf ' . escapeshellarg($g)); } } }; // todos los grupos (page, com_content, _system, com_plugins...)
$restaurar = function () use ($db, $prev, $ASSET, $cfgPath, $cfgOrig, $SITE, $ids, $limpiaCache) {
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string($prev['com']) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_assets SET rules='" . $db->real_escape_string($prev['rules']) . "' WHERE name='com_engage'");
	q("UPDATE jos_content SET created_by=" . (int) $prev['author'] . " WHERE id=" . (int) $ids['art_publico']);
	q("DELETE FROM jos_engage_reactions");
	q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
	q("UPDATE jos_extensions SET enabled=" . (int) $prev['cache'][0] . ", params='" . $db->real_escape_string((string) $prev['cache'][1]) . "' WHERE element='cache' AND folder='system'");
	q("UPDATE jos_extensions SET enabled=" . (int) $prev['ecache'][0] . " WHERE element='engagecache' AND folder='system'");
	file_put_contents($cfgPath, $cfgOrig);
	$limpiaCache();
	q("UPDATE jos_users SET block=0 WHERE username='t2blk'");
};
register_shutdown_function(function () use ($restaurar) { if (getenv('NO_RESTAURAR') === '1') { return; } $restaurar(); }); // NO_RESTAURAR=1: deja los datos para depurar

// ---- Usuarios de prueba
$mkUser = function (string $user, int $group, int $block = 0) use ($PASS, $db) {
	$id = (int) one("SELECT id FROM jos_users WHERE username='$user'");
	$h = password_hash($PASS, PASSWORD_BCRYPT);
	if (!$id) {
		q("INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, activation, params, resetCount, otpKey, otep, requireReset, authProvider) VALUES ('$user', '$user', '$user@example.invalid', '$h', $block, 0, NOW(), '', '{}', 0, '', '', 0, '')");
		$id = (int) $db->insert_id;
		q("INSERT INTO jos_user_usergroup_map (user_id, group_id) VALUES ($id, $group)");
	} else { q("UPDATE jos_users SET password='$h', block=$block WHERE id=$id"); }
	return $id;
};
$u1 = $mkUser('t2reg1', 2); $u2 = $mkUser('t2reg2', 2); $uM = $mkUser('t2mgr', 6); $uE = $mkUser('t2edi', 4); $uB = $mkUser('t2blk', 6);
$uAdmin = (int) one("SELECT id FROM jos_users WHERE username='admintest'");

// ---- Hilo (fechas fijas)
q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
$cm = function (string $body, int $by, string $name, int $enabled, ?int $parent, string $when) use ($db, $ASSET) {
	$p = $parent === null ? 'NULL' : $parent; $nm = $by ? 'NULL' : "'$name'"; $em = $by ? 'NULL' : "'" . strtolower($name) . "@example.invalid'";
	q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET,$p,'" . $db->real_escape_string("<p>$body</p>") . "',$nm,$em,'203.0.113.7','t',$enabled,'$when',$by)");
	return (int) $db->insert_id;
};
$c1 = $cm('Raiz de reg1', $u1, '', 1, null, '2026-09-01 10:00:00');
$c2 = $cm('Raiz de un invitado', 0, 'Invitado', 1, null, '2026-09-02 10:00:00');
$c3 = $cm('Raiz del manager', $uM, '', 1, null, '2026-09-03 10:00:00');
$c4 = $cm('Raiz de reg2', $u2, '', 1, null, '2026-09-04 10:00:00');
$c5 = $cm('Respuesta de un invitado a c1', 0, 'Invitado', 1, $c1, '2026-09-05 10:00:00');
$c6 = $cm('Respuesta de reg2 a c1', $u2, '', 1, $c1, '2026-09-06 10:00:00');
$c7 = $cm('Nieta del manager', $uM, '', 1, $c5, '2026-09-07 10:00:00');
$c8 = $cm('Raiz del admin', $uAdmin, '', 1, null, '2026-09-08 10:00:00');
$c9 = $cm('Raiz sin publicar', 0, 'Pendiente', 0, null, '2026-09-09 10:00:00');
$c10 = $cm('Raiz spam', 0, 'Spammer', -3, null, '2026-09-10 10:00:00');
$c11 = $cm('Respuesta tardia a c3', $u1, '', 1, $c3, '2026-09-11 10:00:00');
$re = fn(int $c, int $u, int $t) => q("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($c,$u,$t,NOW())");
// puntuaciones: c1 = +3 (mas un me gusta de su propio autor, que no cuenta), c4 = +3, c2 = +1, c3 = -1, c8 = 0, respuestas con muchas valoraciones (no cuentan para la raiz)
$re($c1, $uM, 1); $re($c1, $u2, 1); $re($c1, $uE, 1); $re($c1, $u1, 1);
$re($c4, $u1, 1); $re($c4, $uM, 1); $re($c4, $uAdmin, 1); $re($c4, $u2, 1);
$re($c2, $u1, 1);
$re($c3, $u1, 2); $re($c3, $uM, 1);       // -1 + el propio del manager (no cuenta) = -1
$re($c6, $u1, 1); $re($c6, $uM, 1); $re($c6, $uE, 1); $re($c6, $uAdmin, 1);
// favoritos: reg1 = c1 (propio), c3, c5, c6 y c9 (sin publicar: no debe salir); mgr = c2 y c11
foreach ([$c1, $c3, $c5, $c6, $c9] as $c) { $re($c, $u1, 3); }
foreach ([$c2, $c11] as $c) { $re($c, $uM, 3); }
$expSeqCache = [];

// ---- Orden esperado: calculo independiente desde la BD
$comentarios = function (bool $todos = false) {
	$w = $todos ? '1' : 'enabled=1';
	return q("SELECT id, parent_id, created, created_by, email FROM jos_engage_comments WHERE asset_id=" . $GLOBALS['ASSET'] . " AND $w")->fetch_all(MYSQLI_ASSOC);
};
$score = function () use ($comentarios) {
	$coms = []; foreach ($comentarios(true) as $c) { $coms[$c['id']] = $c; }
	$s = [];
	foreach (q("SELECT r.comment_id, r.user_id, r.type, u.email AS ue FROM jos_engage_reactions r LEFT JOIN jos_users u ON u.id=r.user_id WHERE r.type IN (1,2)")->fetch_all(MYSQLI_ASSOC) as $x) {
		$c = $coms[$x['comment_id']] ?? null; if (!$c) { continue; }
		if ((int) $x['user_id'] === (int) $c['created_by']) { continue; }
		$s[$x['comment_id']] = ($s[$x['comment_id']] ?? 0) + ((int) $x['type'] === 1 ? 1 : -1);
	}
	return $s;
};
/** Secuencia de IDs esperada. $modo: newest | oldest | top | null (orden de siempre con $dir asc|desc). */
$esperado = function (?string $modo, string $dir = 'asc', bool $todos = false) use ($comentarios, $score) {
	$coms = []; foreach ($comentarios($todos) as $c) { $coms[(int) $c['id']] = $c; }
	$sc = $score();
	$cmpDesc = fn($a, $b) => [$b['created'], (int) $b['id']] <=> [$a['created'], (int) $a['id']];
	$cmpAsc  = fn($a, $b) => [$a['created'], (int) $a['id']] <=> [$b['created'], (int) $b['id']];
	$roots = array_filter($coms, fn($c) => empty($c['parent_id']));
	$hijos = [];
	foreach ($coms as $c) { if (!empty($c['parent_id']) && isset($coms[(int) $c['parent_id']])) { $hijos[(int) $c['parent_id']][] = $c; } }
	if ($modo === 'newest') { uasort($roots, $cmpDesc); }
	elseif ($modo === 'oldest') { uasort($roots, $cmpAsc); }
	elseif ($modo === 'top') { uasort($roots, fn($a, $b) => [($sc[$b['id']] ?? 0), $b['created'], (int) $b['id']] <=> [($sc[$a['id']] ?? 0), $a['created'], (int) $a['id']]); }
	else { uasort($roots, $dir === 'desc' ? $cmpDesc : $cmpAsc); }
	foreach ($hijos as &$h) { usort($h, ($modo === null && $dir === 'desc') ? $cmpDesc : $cmpAsc); } unset($h);
	$out = [];
	$v = function ($c) use (&$v, &$out, $hijos) { $out[] = (int) $c['id']; foreach ($hijos[(int) $c['id']] ?? [] as $h) { $v($h); } };
	foreach ($roots as $c) { $v($c); }
	return $out;
};
$seq = fn(string $html) => preg_match_all('/<article\b[^>]*\bid="akengage-comment-(\d+)"/s', $html, $m) ? array_map('intval', $m[1]) : [];
$js = fn(array $v) => implode(',', $v);

$setCom();
$G = new Cliente($BASE, "$WORK/tmp", 't2-guest'); $G2 = new Cliente($BASE, "$WORK/tmp", 't2-guest2');
$R1 = new Cliente($BASE, "$WORK/tmp", 't2-r1'); $R2 = new Cliente($BASE, "$WORK/tmp", 't2-r2'); $MG = new Cliente($BASE, "$WORK/tmp", 't2-mg'); $ED = new Cliente($BASE, "$WORK/tmp", 't2-ed'); $AD = new Cliente($BASE, "$WORK/tmp", 't2-ad');
r('T-00', 'inicio de sesion de Registered x2, Manager y Editor', $R1->login('t2reg1', $PASS) && $R2->login('t2reg2', $PASS) && $MG->login('t2mgr', $PASS) && $ED->login('t2edi', $PASS));
$logAntes = is_file("$WORK/php-errors-site.log") ? filesize("$WORK/php-errors-site.log") : 0;
$get = fn(Cliente $c, string $extra = '') => $c->get($PAGE . $extra);

// ===== 1. La barra en el HTML
$p1 = $get($G); $p2 = $get($G2); $h = $p1['body'];
r('T-01', 'invitado: barra «Sort by» con 3 enlaces (Newest, Oldest, Top rated) y rel="nofollow" en todos', $p1['code'] === 200 && substr_count($h, 'class="akengage-sort-link') === 3 && substr_count($h, 'rel="nofollow"') >= 4 && str_contains($h, 'Sort by') && str_contains($h, 'Top rated'));
preg_match_all('/<a class="akengage-sort-link[^"]*" href="([^"]+)"/', $h, $mh);
r('T-02', 'los enlaces de orden son relativos (sin esquema ni anfitrion), con akengage_sort, akengage_limitstart=0 y el ancla de la seccion', count($mh[1]) === 3 && !array_filter($mh[1], fn($u) => preg_match('~^[a-z]+:|//~i', html_entity_decode($u))) && str_contains($mh[1][0], 'akengage_sort=newest') && str_contains($mh[1][2], 'akengage_sort=top') && !array_filter($mh[1], fn($u) => !str_contains($u, 'akengage_limitstart=0') || !str_ends_with($u, '#akengage-comments-section')), implode(' | ', $mh[1]));
r('T-03', 'sin seleccion, con comments_ordering=asc el actual es «Oldest» (un solo aria-current)', substr_count($h, 'aria-current="true"') === 1 && preg_match('/akengage-sort-link is-active" href="[^"]*oldest[^"]*" rel="nofollow" aria-current="true"/', $h) === 1);
r('T-04', 'el conmutador «Only my favourites» existe pero viene oculto (hidden) para el invitado y no lleva ningun dato de usuario', preg_match('/<a class="akengage-fav-toggle"[^>]* data-engage-fav-toggle hidden>/', $h) === 1);
$norm = fn(string $x) => preg_replace(['/name="[0-9a-f]{32}" value="1"/', '/"csrf\.token":"[0-9a-f]{32}"/'], ['name="T" value="1"', '"csrf.token":"T"'], $x);
$secc = fn(string $x) => preg_match('/<section id="akengage-comments-section".*?<\/section>/s', $x, $mm) ? $norm($mm[0]) : '';
r('T-05', 'la seccion de comentarios de dos invitados distintos es identica byte a byte (nada por usuario)', strlen($secc($p1['body'])) > 2000 && $secc($p1['body']) === $secc($p2['body']));
r('T-06', 'sin orden explicito no hay enlace canonico nuestro ni noindex', !str_contains($h, 'rel="canonical"') && !str_contains($h, 'noindex'));
$pt = $get($G, '&akengage_sort=top');
r('T-07', 'con akengage_sort=top: canonical sin parametros de orden', preg_match('/<link href="[^"]*id=1&amp;catid=\d+" rel="canonical"/', $pt['body']) === 1 && !preg_match('/<link href="[^"]*akengage[^"]*" rel="canonical"/', $pt['body']), (string) (preg_match('/<link[^>]*canonical[^>]*>/', $pt['body'], $mm) ? $mm[0] : 'sin canonical'));
r('T-08', 'con akengage_sort=top el enlace actual es «Top rated»', preg_match('/akengage-sort-link is-active" href="[^"]*top[^"]*" rel="nofollow" aria-current="true"/', $pt['body']) === 1);
$sc = fn(string $html) => (int) preg_match_all('/<script\b[^>]*src=/', $html);
r('T-09', 'el JavaScript de las reacciones y el de las herramientas se cargan (defer) sin otras fuentes', str_contains($h, 'reactions.js') && str_contains($h, 'tools.js') && !preg_match('/<script[^>]+src="https?:\/\/(?!127\.0\.0\.1)/', $h));

// ===== 2. Orden servido = orden esperado
foreach (['asc', 'desc'] as $dir) {
	foreach ([1, 2, 3, 6] as $ml) {
		$setCom(['comments_ordering' => $dir, 'max_level' => (string) $ml]);
		$okTodos = true; $ev = [];
		foreach ([null, 'newest', 'oldest', 'top'] as $modo) {
			$got = $seq($get($G, $modo ? "&akengage_sort=$modo" : '')['body']);
			$exp = $esperado($modo, $dir);
			if ($got !== $exp) { $okTodos = false; $ev[] = ($modo ?? 'sin') . ': ' . $js($got) . ' != ' . $js($exp); }
		}
		r("T-10-$dir-$ml", "comments_ordering=$dir, max_level=$ml: sin seleccion, newest, oldest y top sirven exactamente el orden esperado (raices por el modo, respuestas cronologicas bajo su padre)", $okTodos, implode(' ; ', $ev) ?: $js($esperado('top')));
	}
}
$setCom();
$exTop = $esperado('top');
r('T-11', 'el orden «top» es el esperado a mano: raices c4 y c1 (+3 cada una; empate: la mas reciente primero), c2, c8, c3 (-1); respuestas cronologicas', $exTop === [$c4, $c1, $c5, $c7, $c6, $c2, $c8, $c3, $c11], $js($exTop));
r('T-12', 'la valoracion no cuenta los me gusta del autor a su propio comentario (c1 vale 3 y no 4)', ($score())[$c1] === 3);
// Reply en nivel 3 respeta max_level con cualquier orden
foreach ([3, 6] as $ml) {
	$setCom(['max_level' => (string) $ml]);
	$a = $get($R1, '&akengage_sort=top')['body'];
	$t = $get($R1)['body'];
	$ra = preg_match('/id="akengage-comment-' . $c7 . '".*?data-akengageid="(\d+)"/s', $a, $x1) ? $x1[1] : '?';
	$rt = preg_match('/id="akengage-comment-' . $c7 . '".*?data-akengageid="(\d+)"/s', $t, $x2) ? $x2[1] : '?';
	r("T-13-$ml", "max_level=$ml: el boton Responder del nivel 3 apunta al mismo comentario con y sin orden (" . ($ml === 3 ? 'su padre de nivel 2' : 'el mismo') . ')', $ra === $rt && $ra === (string) ($ml === 3 ? $c5 : $c7), "$ra/$rt");
}
$setCom();

// Paginacion
$setCom(['default_limit' => '4']);
$full = $esperado('top'); $paginas = [];
for ($s = 0; $s < count($full); $s += 4) { $paginas[] = $seq($get($G, "&akengage_sort=top&akengage_limitstart=$s")['body']); }
$union = array_merge(...$paginas);
r('T-14', 'paginacion de 4 en 4 con akengage_sort=top: las paginas, una detras de otra, forman el orden esperado sin repetir ni saltar', $union === $full && count($paginas[0]) === 4, $js($union));
$pg1 = $get($G, '&akengage_sort=top')['body'];
preg_match_all('/class="page-link"[^>]*href="([^"]+)"|href="([^"]+)"[^>]*class="page-link"/', $pg1, $pl);
$hrefs = array_filter(array_merge($pl[1], $pl[2]), fn($u) => $u !== '' && $u !== '#');
r('T-15', 'los enlaces de la paginacion conservan akengage_sort=top', count($hrefs) >= 2 && !array_filter($hrefs, fn($u) => !str_contains(html_entity_decode($u), 'akengage_sort=top')), implode(' | ', array_slice($hrefs, 0, 3)));
// akengage_cid con orden: salta a la pagina que contiene el comentario en ESE orden
$fila = array_search($c6, $full); $paginaEsperada = intdiv($fila, 4);
$pc = $seq($get($G, "&akengage_sort=top&akengage_cid=$c6")['body']);
r('T-16', 'akengage_cid + akengage_sort=top: abre la pagina que contiene al comentario en ese orden', in_array($c6, $pc, true) && $pc === $paginas[$paginaEsperada], $js($pc));
$setCom();

// ===== 3. Valores de ataque
$ataques = ['', 'NEWEST', 'Top', 'top ', ' top', "top\n", "top\r\n", "top%00", 'top;DROP TABLE jos_users', "top' OR '1'='1", 'top,u.password', 'c.created', 'c.created DESC', 'reaction_score', 'u.password', '1', 'RAND()', '(SELECT 1)', "t\u{043e}p", "top\u{200B}", '../../etc/passwd', '<script>alert(1)</script>', str_repeat('A', 3000), 'top/**/', "top\u{0000}", '%', '%00', 'null', 'true', '0', '-1', '9999999999999999999'];
$base = $seq($get($G)['body']); $badAll = [];
foreach ($ataques as $a) {
	$resp = $get($G, '&akengage_sort=' . rawurlencode($a));
	if ($resp['code'] !== 200 || $seq($resp['body']) !== $base || stripos($resp['body'], 'SQL') !== false && stripos($resp['body'], 'error') !== false) { $badAll[] = substr($a, 0, 25) . ':' . $resp['code']; }
}
foreach (['akengage_sort[]=top', 'akengage_sort[x]=top', 'akengage_sort[][]=1', 'akengage_sort[]=top&akengage_sort[]=newest', 'akengage_sort=top&akengage_sort=newest'] as $raw) {
	$resp = $G->get($PAGE . '&' . $raw);
	$got = $seq($resp['body']);
	// el ultimo valor repetido (PHP) puede ser valido: solo se exige 200 y que sea el orden de siempre o uno de los modos validos
	$validos = [$base, $esperado('newest'), $esperado('oldest'), $esperado('top')];
	if ($resp['code'] !== 200 || !in_array($got, $validos, true)) { $badAll[] = $raw . ':' . $resp['code']; }
}
r('T-20', count($ataques) . ' valores de ataque (SQL, saltos de linea, NUL, unicode parecido, 3000 caracteres, arrays) en akengage_sort: 200 y el orden de siempre, sin errores', !$badAll, implode(' | ', $badAll));
$cur = $seq($get($G, '&akengage_sort=newest')['body']);
$mixto = $seq($get($G, '&akengage_sort=newest%00')['body']);
r('T-21', 'una variante con NUL de un valor valido ("newest%00") NO se acepta', $mixto === $base && $cur !== $base);
$badF = [];
foreach (['01', '1 ', ' 1', "1\n", 'true', 'yes', '2', '1;DROP', '%31', '１', '1.0', 'on'] as $a) {
	$resp = $get($R1, '&akengage_fav=' . rawurlencode($a));
	if ($resp['code'] !== 200 || $seq($resp['body']) !== $base || str_contains($resp['body'], 'My favourite comments')) { $badF[] = $a; }
}
foreach (['akengage_fav[]=1', 'akengage_fav[x]=1'] as $raw) { $resp = $R1->get($PAGE . '&' . $raw); if ($resp['code'] !== 200 || $seq($resp['body']) !== $base) { $badF[] = $raw; } }
r('T-22', 'akengage_fav: solo la cadena exacta 1 activa la lista (01, "1 ", "1\\n", true, 1.0, arrays... no)', !$badF, implode(' | ', $badF));
$errNuevos = (is_file("$WORK/php-errors-site.log") ? filesize("$WORK/php-errors-site.log") : 0) - $logAntes;
r('T-23', 'ningun aviso ni error en el registro de PHP tras todos los ataques', $errNuevos === 0, "$errNuevos bytes nuevos");

// Limites de la paginacion con orden y con favoritos (valores negativos, enormes, cero, no numericos)
// (con clientes nuevos: akengage_limit y akengage_limitstart se guardan en la sesion, como siempre, y no deben contaminar el resto de la prueba)
$malLim = [];
$LG = new Cliente($BASE, "$WORK/tmp", 't2-lim-g'); $LR = new Cliente($BASE, "$WORK/tmp", 't2-lim-r'); $LR->login('t2reg1', $PASS);
foreach (['akengage_limitstart=-1', 'akengage_limitstart=999999999999999999999', 'akengage_limitstart=abc', 'akengage_limitstart[]=3', 'akengage_limit=0', 'akengage_limit=-3', 'akengage_limit=99999999', 'akengage_limit=abc', 'akengage_limit[]=2', 'akengage_limit=1&akengage_limitstart=3'] as $lim) {
	foreach (['', '&akengage_sort=top', '&akengage_fav=1', '&akengage_fav=1&akengage_sort=newest'] as $modo) {
		$cl = ($modo !== '' && str_contains($modo, 'fav')) ? $LR : $LG;
		$resp = $cl->get($PAGE . $modo . '&' . $lim);
		if ($resp['code'] !== 200 || !str_contains($resp['body'], 'akengage-comments-section')) { $malLim[] = "$lim$modo:{$resp['code']}"; }
	}
}
$errLim = (is_file("$WORK/php-errors-site.log") ? filesize("$WORK/php-errors-site.log") : 0) - $logAntes;
r('T-24', 'limites de paginacion raros (negativos, enormes, cero, texto, arrays) con orden y con favoritos: 200 y sin errores de PHP', !$malLim && $errLim === 0, implode(' | ', $malLim) . " ($errLim bytes de registro)");

// ===== 4. Solo mis favoritos
$f = $get($R1, '&akengage_fav=1'); $fb = $f['body'];
$sf = $seq($fb);
r('T-30', 'reg1 con akengage_fav=1: lista PLANA con SUS favoritos publicados (c1, c3, c5, c6) y ningun otro, en el orden de siempre', $f['code'] === 200 && $sf === [$c1, $c3, $c5, $c6], $js($sf));
r('T-31', 'no aparecen el favorito sin publicar (c9), el de otro usuario (c2, c11) ni el spam', !array_intersect($sf, [$c9, $c2, $c11, $c10, $c4, $c8, $c7]));
r('T-32', 'la lista es plana: ningun <ul> de nivel 2 ni 3, aunque c5 y c6 sean respuestas', !str_contains($fb, 'akengage-comment-list--level2') && !str_contains($fb, 'akengage-comment-list--level3'));
r('T-33', 'cada respuesta lleva «In reply to» con su padre y todos un enlace «View in thread» con akengage_cid y ancla', substr_count($fb, 'akengage-comment-replyto-link') === 2 && substr_count($fb, 'akengage-context-link') === 4 && preg_match('/akengage-context-link" href="[^"]*akengage_cid=' . $c5 . '[^"]*#akengage-comment-' . $c5 . '"/', $fb) === 1);
r('T-34', 'los enlaces «View in thread» y la fecha NO llevan akengage_fav ni akengage_sort (llevan a la lista completa)', !preg_match('/akengage-context-link" href="[^"]*akengage_(fav|sort)/', $fb) && !preg_match('/akengage-comment-permalink[^>]*>\s*<a href="[^"]*akengage_fav/s', $fb));
r('T-35', 'cabecera «My favourite comments (4)», conmutador visible con aria-current y texto «Show all comments»', str_contains($fb, 'My favourite comments (4)') && preg_match('/<a class="akengage-fav-toggle is-active"[^>]*data-engage-fav-toggle aria-current="true">/', $fb) === 1 && str_contains($fb, 'Show all comments'));
r('T-36', 'sin botones de Responder en la lista de favoritos (su nivel depende del hilo)', !str_contains($fb, 'akengage-comment-reply-btn'));
$cc = hdr($f, 'Cache-Control');
r('T-37', 'la lista de favoritos no se cachea (no-store/no-cache) y varia con la cookie; con noindex,nofollow', (stripos($cc, 'no-store') !== false || stripos($cc, 'no-cache') !== false) && stripos(hdr($f, 'Vary'), 'Cookie') !== false && preg_match('/<meta name="robots" content="noindex, nofollow"/', $fb) === 1, "$cc | " . hdr($f, 'Vary'));
$fn = $seq($get($R1, '&akengage_fav=1&akengage_sort=newest')['body']);
r('T-38', 'favoritos + akengage_sort=newest: mismo conjunto, de mas reciente a mas antigua (c6, c5, c3, c1)', $fn === [$c6, $c5, $c3, $c1], $js($fn));
$ft = $seq($get($R1, '&akengage_fav=1&akengage_sort=top')['body']);
r('T-39', 'favoritos + akengage_sort=top: por valoracion (c1 +3, c6 +4, c3 -1, c5 0): c6, c1, c5, c3', $ft === [$c6, $c1, $c5, $c3], $js($ft));
// privacidad
$e2 = $get($R2, '&akengage_fav=1');
r('T-40', 'reg2 (sin favoritos) con akengage_fav=1: lista vacia con el mensaje, la barra para volver y SIN ver los de reg1', $seq($e2['body']) === [] && str_contains($e2['body'], 'You have not marked any comment of this article as a favourite yet') && str_contains($e2['body'], 'My favourite comments (0)') && str_contains($e2['body'], 'Show all comments'));
$mf = $seq($get($MG, '&akengage_fav=1')['body']);
r('T-41', 'el Manager (que ve los sin publicar) solo recibe SUS favoritos publicados (c2, c11) y no ve los de reg1', $mf === [$c2, $c11], $js($mf));
$gf = $get($G, '&akengage_fav=1');
r('T-42', 'invitado con akengage_fav=1: se ignora (lista normal, sin cabecera de favoritos, sin estrellas) y los enlaces de orden no arrastran el parametro', $seq($gf['body']) === $base && !str_contains($gf['body'], 'My favourite comments') && !str_contains($gf['body'], 'is-favorite') && !preg_grep('/akengage_fav/', preg_match_all('/class="akengage-sort-link[^"]*" href="([^"]+)"/', $gf['body'], $ml) ? $ml[1] : []), 'seq=' . (int) ($seq($gf['body']) === $base));
r('T-43', 'la pagina normal de un usuario con sesion no lleva «is-favorite» ni favoritos pintados en el HTML (los pinta el JS)', !str_contains($get($R1)['body'], 'akengage-is-favorite'));
// favoritos con paginacion
$setCom(['default_limit' => '2']);
$p1f = $seq($get($R1, '&akengage_fav=1&akengage_limitstart=0')['body']); $p2f = $seq($get($R1, '&akengage_fav=1&akengage_limitstart=2')['body']);
r('T-44', 'favoritos con paginacion de 2 en 2: [c1,c3] y [c5,c6]', $p1f === [$c1, $c3] && $p2f === [$c5, $c6], $js($p1f) . ' / ' . $js($p2f));
$pgf = $get($R1, '&akengage_fav=1&akengage_limitstart=0')['body'];
preg_match_all('/href="([^"]*akengage_limitstart=2[^"]*)"/', $pgf, $pm);
r('T-45', 'los enlaces de paginacion de la lista de favoritos conservan akengage_fav=1', !empty($pm[1]) && !array_filter($pm[1], fn($u) => !str_contains(html_entity_decode($u), 'akengage_fav=1')), implode(' | ', array_slice($pm[1], 0, 2)));
$setCom();
// favorito de un comentario de otro articulo no sale
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (" . (int) $ids['art_otro_asset'] . ",'<p>otro</p>','X','x@example.invalid','203.0.113.7','t',1,NOW(),0)");
$cOtro = (int) $db->insert_id; $re($cOtro, $u1, 3);
r('T-46', 'un favorito de un comentario de OTRO articulo no aparece en la lista de este', !in_array($cOtro, $seq($get($R1, '&akengage_fav=1')['body']), true));
q("DELETE FROM jos_engage_reactions WHERE comment_id=$cOtro"); q("DELETE FROM jos_engage_comments WHERE id=$cOtro");
// contenido restringido: reg1 tiene un favorito en un articulo que ya no puede ver
q("UPDATE jos_content SET state=0 WHERE id=" . (int) $ids['art_publico']);
$sinPub = $get($R1, '&akengage_fav=1');
q("UPDATE jos_content SET state=1 WHERE id=" . (int) $ids['art_publico']);
r('T-47', 'articulo despublicado: la pagina no sirve comentarios ni favoritos (404/403, nada que filtrar)', in_array($sinPub['code'], [403, 404], true) && !str_contains($sinPub['body'], 'Raiz de reg1'), (string) $sinPub['code']);

// ===== 5. Opciones
$setCom(['reactions_enabled' => '0']);
$h = $get($R1, '&akengage_sort=top&akengage_fav=1')['body'];
r('T-50', 'reacciones apagadas: no hay «Top rated», ni conmutador de favoritos; akengage_sort=top y akengage_fav=1 se ignoran', !str_contains($h, 'Top rated') && !str_contains($h, 'akengage-fav-toggle') && $seq($h) === $esperado(null, 'asc') && substr_count($h, 'akengage-sort-link') === 2);
$setCom(['reactions_favorites' => '0']);
$h = $get($R1, '&akengage_fav=1')['body'];
r('T-51', 'favoritos apagados: sin conmutador y akengage_fav=1 se ignora', !str_contains($h, 'akengage-fav-toggle') && $seq($h) === $esperado(null, 'asc') && str_contains($h, 'Top rated'));
$setCom(['sort_selector' => '0']);
$h0 = $get($G)['body']; $hx = $get($G, '&akengage_sort=top&akengage_fav=1')['body'];
r('T-52', 'sort_selector=0: ni barra ni conmutador; akengage_sort y akengage_fav se ignoran (misma lista que sin parametros)', !str_contains($h0, 'akengage-toolbar') && !str_contains($h0, 'akengage-fav-toggle') && $seq($hx) === $seq($h0) && !str_contains($hx, 'canonical'));
$hf = $get($R1, '&akengage_fav=1')['body'];
r('T-53', 'sort_selector=0: tampoco la lista de favoritos por URL', !str_contains($hf, 'My favourite comments') && $seq($hf) === $seq($h0));
$setCom(['default_sort' => 'top']);
$hd = $get($G)['body'];
r('T-54', 'default_sort=top (sin selector): sin seleccion se sirve «top»', $seq($hd) === $esperado('top'));
$setCom(['default_sort' => 'top', 'sort_selector' => '1']);
$hd = $get($G)['body'];
r('T-55', 'default_sort=top con selector: «Top rated» es el actual, sin seleccion explicita (no canonical)', $seq($hd) === $esperado('top') && preg_match('/akengage-sort-link is-active" href="[^"]*top[^"]*" rel="nofollow" aria-current="true"/', $hd) === 1 && !str_contains($hd, 'canonical'));
$ho = $seq($get($G, '&akengage_sort=oldest')['body']);
r('T-56', 'la eleccion del visitante manda sobre default_sort', $ho === $esperado('oldest'));
$setCom(['default_sort' => 'top', 'reactions_enabled' => '0']);
r('T-57', 'default_sort=top con reacciones apagadas: se ignora (orden de siempre)', $seq($get($G)['body']) === $esperado(null, 'asc'));
foreach (['bogus', 'TOP', 'top;DROP'] as $v) { $setCom(['default_sort' => $v]); $okv = $seq($get($G)['body']) === $esperado(null, 'asc'); if (!$okv) { break; } }
r('T-58', 'un default_sort ajeno guardado en la BD se trata como automatico', $okv);
$setCom(['comments_ordering' => 'desc']);
$hd = $get($G)['body'];
r('T-59', 'comments_ordering=desc sin seleccion: sigue mandando (raices y respuestas descendentes, como en la 0.7.1) y «Newest» es el actual', $seq($hd) === $esperado(null, 'desc') && preg_match('/akengage-sort-link is-active" href="[^"]*newest[^"]*" rel="nofollow" aria-current="true"/', $hd) === 1, $js($seq($hd)));
$setCom();

// Validacion estricta en el panel (opciones modernas)
if ($AD->loginAdmin('admintest', $ADMIN_PASS)) {
	$pa = $AD->get('/administrator/index.php?option=com_engage&view=settings&section=design');
	$tk = preg_match('/"csrf\.token":"([0-9a-f]{32})"/', $pa['body'], $mt) ? $mt[1] : Cliente::token($pa['body']);
	$save = fn(string $k, $v) => $AD->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => $k, 'value' => $v, $tk => 1]);
	$col = fn(string $k) => json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true)[$k] ?? null;
	$setCom();
	r('T-60', 'la categoria Diseno del panel muestra las 4 opciones nuevas', count(array_filter(['show_badges', 'copy_link', 'sort_selector', 'default_sort'], fn($k) => str_contains($pa['body'], '"' . $k . '"') || str_contains($pa['body'], '[' . $k . ']') || str_contains($pa['body'], "'$k'") || str_contains($pa['body'], 'data-key="' . $k . '"'))) === 4);
	$x = $save('default_sort', 'newest'); r('T-61', 'guardar default_sort=newest: 200 y queda en la BD', $x['code'] === 200 && $col('default_sort') === 'newest', (string) $x['code']);
	$malos = [];
	foreach (['bogus', 'TOP', "top\n", '', 'c.created; DROP TABLE x', '1'] as $v) { $x = $save('default_sort', $v); if ($x['code'] < 400 || $col('default_sort') !== 'newest') { $malos[] = "$v:{$x['code']}"; } }
	r('T-62', 'default_sort rechaza (4xx) valores fuera de la lista cerrada y no cambia nada', !$malos, implode(' | ', $malos));
	$malos = [];
	foreach (['sort_selector', 'copy_link', 'show_badges'] as $k) {
		$x = $save($k, '0'); if ($x['code'] !== 200 || (string) $col($k) !== '0') { $malos[] = "$k=0:{$x['code']}"; }
		foreach (['2', 'yes', '', ' 1', "1\n", 'true', '-1'] as $v) { $x = $save($k, $v); if ($x['code'] < 400 || (string) $col($k) !== '0') { $malos[] = "$k=" . json_encode($v) . ":{$x['code']}"; } }
		$save($k, '1');
	}
	r('T-63', 'sort_selector, copy_link y show_badges aceptan 0/1 y rechazan 2, yes, vacio, " 1", "1\\n", true, -1', !$malos, implode(' | ', $malos));
	$x = $AD->post('/administrator/index.php?option=com_engage&task=settings.save&format=json', ['scope' => 'com_engage', 'key' => 'default_sort', 'value' => 'top']);
	r('T-64', 'sin token CSRF no se guarda nada', $x['code'] >= 400 && $col('default_sort') === 'newest', (string) $x['code']);
} else { r('T-60', 'no se pudo iniciar sesion como administrador', false); }
$setCom();

// ===== 6. Insignias y estrella
q("UPDATE jos_content SET created_by=$u2 WHERE id=" . (int) $ids['art_publico']);
$hb = $get($G)['body'];
$badgesDe = function (string $html, int $cid) { return preg_match('/<article[^>]*id="akengage-comment-' . $cid . '".*?<\/footer>/s', $html, $m) ? (preg_match_all('/akengage-badge--(author|moderator)/', $m[0], $b) ? $b[1] : []) : null; };
r('T-70', 'autor del articulo = reg2: sus comentarios (c4, c6) llevan «Author»; los de reg1 y de invitados, nada', $badgesDe($hb, $c4) === ['author'] && $badgesDe($hb, $c6) === ['author'] && $badgesDe($hb, $c1) === [] && $badgesDe($hb, $c2) === [] && $badgesDe($hb, $c5) === []);
r('T-71', 'moderadores (Manager, Super Usuario): «Moderator» en c3, c7, c8, c11 es de reg1 (nada)', $badgesDe($hb, $c3) === ['moderator'] && $badgesDe($hb, $c7) === ['moderator'] && $badgesDe($hb, $c8) === ['moderator'] && $badgesDe($hb, $c11) === []);
r('T-72', 'el texto es solo «Author» / «Moderator» y la insignia no lleva ni un digito, ni nombres de grupo, ni permisos', preg_match_all('/<span class="akengage-badges">.*?<\/span><\/span><\/span>/s', $hb, $bm) > 0 && !array_filter($bm[0], fn($s) => preg_match('/\d/', strip_tags($s)) || preg_match('/Manager|Super|Editor|Registered|Publisher|core\.|group/i', strip_tags($s))), json_encode(array_map('strip_tags', array_slice($bm[0], 0, 3))));
r('T-73', 'cada insignia lleva texto visible, un icono propio (svg aria-hidden) y no depende del color', preg_match_all('/akengage-badge akengage-badge--(author|moderator)"><svg[^>]*aria-hidden="true"[^>]*>.*?<\/svg><span class="akengage-badge-text">(Author|Moderator)<\/span>/s', $hb) === 5, (string) preg_match_all('/akengage-badge akengage-badge--/', $hb));
r('T-74', 'nombre y microdatos intactos: itemprop="name" contiene solo el nombre (sin el texto de la insignia)', preg_match('/itemprop="name" class="fw-bold">' . preg_quote((string) one("SELECT name FROM jos_users WHERE username='t2mgr'"), '/') . '<\/span>/', $hb) === 1 && !preg_match('/itemprop="name"[^>]*>[^<]*(Author|Moderator)/', $hb));
q("UPDATE jos_content SET created_by=$uM WHERE id=" . (int) $ids['art_publico']);
$hb2 = $get($G)['body'];
r('T-75', 'si el autor del articulo es ademas moderador: las dos insignias en orden fijo (Autor, Moderador)', $badgesDe($hb2, $c3) === ['author', 'moderator'] && $badgesDe($hb2, $c7) === ['author', 'moderator']);
q("UPDATE jos_content SET created_by=$uE WHERE id=" . (int) $ids['art_publico']);
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET,NULL,'<p>del editor</p>',NULL,NULL,'203.0.113.7','t',1,'2026-09-12 10:00:00',$uE)"); $cEd = (int) $db->insert_id;
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET,NULL,'<p>del bloqueado</p>',NULL,NULL,'203.0.113.7','t',1,'2026-09-12 11:00:00',$uB)"); $cBl = (int) $db->insert_id;
q("UPDATE jos_users SET block=1 WHERE id=$uB");
$hb3 = $get($G)['body'];
r('T-76', 'Editor (core.edit.state por permiso del componente): «Moderator»; moderador BLOQUEADO: ninguna insignia', $badgesDe($hb3, $cEd) === ['author', 'moderator'] && $badgesDe($hb3, $cBl) === []);
q("UPDATE jos_users SET block=0 WHERE id=$uB");
$hb4 = $get($G)['body'];
r('T-77', 'desbloqueado vuelve a tener la insignia (la ACL se evalua en cada peticion)', $badgesDe($hb4, $cBl) === ['moderator']);
q("DELETE FROM jos_engage_comments WHERE id IN ($cEd,$cBl)");
q("UPDATE jos_content SET created_by=$u2 WHERE id=" . (int) $ids['art_publico']);
// la estrella existente no se duplica
$hm = $get($MG)['body']; // un moderador mira
$cabecera = fn(string $html, int $cid) => preg_match('/<article[^>]*id="akengage-comment-' . $cid . '".*?<\/footer>/s', $html, $m) ? $m[0] : '';
r('T-78', 'un moderador que mira: la insignia «Moderator» sustituye a la estrella del nombre (no se repite) y queda el nombre de usuario', substr_count($cabecera($hm, $c3), 'akengage-commenter-ismoderator') === 0 && str_contains($cabecera($hm, $c3), 'akengage-badge--moderator') && str_contains($cabecera($hm, $c3), 'akengage-commenter-username'));
r('T-79', 'un usuario registrado sin permiso no ve nada del bloque de moderacion (estrella, nombre de usuario)', !str_contains($cabecera($get($R1)['body'], $c3), 'akengage-commenter-username'));
$setCom(['show_badges' => '0']);
$hn = $get($MG)['body'];
r('T-80', 'show_badges=0: ninguna insignia y vuelve la estrella de siempre para el moderador', !str_contains($hn, 'akengage-badge') && !str_contains($hn, 'akengage-name-wrap') && str_contains($cabecera($hn, $c3), 'akengage-commenter-ismoderator'));
$setCom();

// ===== 7. Copiar enlace
$hc = $get($R1)['body'];
preg_match_all('/<button type="button" class="akengage-copy-btn" data-engage-copy="([^"]*)" data-engage-id="(\d+)"/', $hc, $cb, PREG_SET_ORDER);
$okU = true; $evU = '';
foreach ($cb as $m) {
	$u = html_entity_decode($m[1]);
	$esp = "/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}&akengage_cid={$m[2]}#akengage-comment-{$m[2]}"; // 0.8.1: RELATIVA (el HTML puede estar en la cache)
	if ($u !== $esp) { $okU = false; $evU = "$u != $esp"; break; }
}
r('T-90', 'un boton «Copiar enlace» por comentario publicado (' . count($cb) . ') con la URL RELATIVA de la pagina (0.8.1) + akengage_cid + ancla', count($cb) === 9 && $okU, $evU ?: (string) count($cb));
r('T-91', 'el boton lleva aria-label, title, dos iconos svg aria-hidden, viene oculto (hidden) y no hay boton en el sin publicar ni el spam', substr_count($hc, 'data-engage-copywrap hidden') === 9 && preg_match_all('/data-engage-copy="[^"]*"[^>]*aria-label="Copy the link to this comment" title="Copy the link to this comment"><svg[^>]*aria-hidden="true"/', $hc) === 9);
$hm2 = $get($MG)['body'];
r('T-92', 'el moderador ve los sin publicar y el spam pero SIN boton de copiar en ellos', preg_match('/id="akengage-comment-' . $c9 . '".*?<\/article>/s', $hm2, $a9) && !str_contains($a9[0], 'akengage-copy-btn') && preg_match('/id="akengage-comment-' . $c10 . '".*?<\/article>/s', $hm2, $a10) && !str_contains($a10[0], 'akengage-copy-btn'));
$hs = $get($R1, '&akengage_sort=top&akengage_limitstart=0&akengage_fav=0')['body'];
preg_match_all('/data-engage-copy="([^"]*)"/', $hs, $cs);
r('T-93', 'en una lista ordenada el enlace copiado NO arrastra akengage_sort, akengage_fav ni la paginacion', count($cs[1]) === 9 && !array_filter($cs[1], fn($u) => preg_match('/akengage_(sort|fav|limitstart|limit)=/', html_entity_decode($u))));
$hf = $get($R1, '&akengage_fav=1&akengage_sort=top')['body'];
preg_match_all('/data-engage-copy="([^"]*)"/', $hf, $cf);
r('T-94', 'en la lista de favoritos el enlace copiado tampoco lleva el filtro personal', count($cf[1]) === 4 && !array_filter($cf[1], fn($u) => preg_match('/akengage_(sort|fav|limitstart)=/', html_entity_decode($u))));
$hh = $G->req('GET', $PAGE, [], ['Host: evil.example:8080']);
preg_match_all('/data-engage-copy="([^"]*)"/', $hh['body'], $ch);
r('T-95', 'Host de la peticion distinto: el enlace es RELATIVO y no lleva el anfitrion (0.8.1; antes se reflejaba en el HTML y la cache lo compartia con todos)', $hh['code'] === 200 && count($ch[1]) === 9 && str_starts_with(html_entity_decode($ch[1][0]), '/') && !str_contains($hh['body'], 'data-engage-copy="http'), $ch[1][0] ?? '');
$hosts = ['evil.example"onmouseover="alert(1)', "evil.example\"><script>alert(1)</script>", 'evil.example/../x', "evil.example'onfocus='x", 'user@evil.example', 'evil example', 'evil.example:99999', 'javascript:alert(1)//', '[::1]x', 'evil.example#x', 'evil.example?x'];
$mal = [];
foreach ($hosts as $hv) {
	$resp = $G->req('GET', $PAGE, [], ["Host: $hv"]);
	$b = $resp['body'];
	if (preg_match('/"onmouseover="|"><script>alert|onfocus=\'x|<script>alert\(1\)/', $b) || preg_match('/data-engage-copy="[^"]*[<>\'\s][^"]*"/', $b) || preg_match('/data-engage-copy="(?!\/[^\/])[^"]+"/', $b)) { $mal[] = substr($hv, 0, 30) . ':' . $resp['code']; }
}
r('T-96', count($hosts) . ' cabeceras Host hostiles (comillas, etiquetas, userinfo, espacios, puertos raros, javascript:): ningun atributo roto, ningun esquema ni anfitrion en el enlace (relativo), ningun script inyectado', !$mal, implode(' | ', $mal));
$setCom(['copy_link' => '0']);
$hn = $get($R1)['body'];
r('T-97', 'copy_link=0: sin botones, sin tools.js y sin opciones de script', !str_contains($hn, 'akengage-copy') && !str_contains($hn, 'tools.js') && !str_contains($hn, 'akeeba.Engage.Tools'));
$setCom();

// ===== 8. Cache de pagina de Joomla y cache conservadora (engagecache)
$cfgOn = str_replace('public $caching = 0;', 'public $caching = 1;', $cfgOrig);
file_put_contents($cfgPath, $cfgOn);
q("UPDATE jos_extensions SET enabled=1, params='{\"browsercache\":\"0\",\"cachetime\":\"15\"}' WHERE element='cache' AND folder='system'");
q("UPDATE jos_extensions SET enabled=1 WHERE element='engagecache' AND folder='system'"); // con la cache de Joomla (conservadora) activa hace falta este plugin para que la paginacion y el orden entren en la clave
$limpiaCache();
sleep(4);
$K = new Cliente($BASE, "$WORK/tmp", 't2-cache');
$a1 = $K->get($PAGE . '&akengage_sort=top'); $a2 = $K->get($PAGE); $a3 = $K->get($PAGE . '&akengage_sort=top');
$archivos = (int) trim((string) shell_exec('ls ' . escapeshellarg("$SITE/cache/page") . ' ' . escapeshellarg("$SITE/administrator/cache/page") . ' 2>/dev/null | grep -c cache-page'));
r('T-100', 'cache de pagina activa: la pagina ordenada y la normal se guardan por separado (2 entradas) y cada una sirve su orden', $archivos >= 2 && $seq($a1['body']) === $esperado('top') && $seq($a2['body']) === $esperado(null, 'asc') && $seq($a3['body']) === $esperado('top'), "$archivos archivos; " . $js($seq($a1['body'])) . ' | ' . $js($seq($a2['body'])) . ' | ' . $js($seq($a3['body'])));
r('T-101', 'el HTML cacheado del invitado no lleva estado de usuario: sin sesion de nadie, sin favoritos ni reacciones pulsadas, conmutador oculto, igual que el que no esta en cache', !str_contains($a3['body'], 'user.logout') && !str_contains($a3['body'], 'aria-pressed="true"') && !str_contains($a3['body'], 'is-favorite') && preg_match('/data-engage-fav-toggle hidden>/', $a3['body']) === 1, 'logout=' . (int) !str_contains($a3['body'], 'user.logout') . ' fav=' . (int) !str_contains($a3['body'], 'is-favorite') . ' hid=' . preg_match('/data-engage-fav-toggle hidden>/', $a3['body']) . ' code=' . $a3['code']);
$u1b = $get($R1, '&akengage_sort=top'); $u1f = $get($R1, '&akengage_fav=1');
r('T-102', 'con la cache activa el usuario con sesion recibe su pagina (no la cacheada): la lista de favoritos sale con SUS cuatro favoritos', $seq($u1f['body']) === [$c1, $c3, $c5, $c6] && $seq($u1b['body']) === $esperado('top'));
$aG = $K->get($PAGE . '&akengage_fav=1');
r('T-103', 'el invitado con akengage_fav=1 sobre la cache NO recibe favoritos de nadie (misma lista que sin parametro)', $seq($aG['body']) === $esperado(null, 'asc') && !str_contains($aG['body'], 'My favourite comments'), $js($seq($aG['body'])));
// cache conservadora + engagecache
q("UPDATE jos_extensions SET enabled=0 WHERE element='cache' AND folder='system'");
q("UPDATE jos_extensions SET enabled=1 WHERE element='engagecache' AND folder='system'");
$limpiaCache();
sleep(2);
$K2 = new Cliente($BASE, "$WORK/tmp", 't2-cons');
$b1 = $K2->get($PAGE . '&akengage_sort=top'); $b2 = $K2->get($PAGE); $b3 = $K2->get($PAGE . '&akengage_sort=oldest'); $b4 = $K2->get($PAGE . '&akengage_sort=top'); $b5 = $K2->get($PAGE);
r('T-104', 'cache conservadora + plugin engagecache: top, normal y oldest no se mezclan, tambien las segundas peticiones (desde la cache)', $seq($b1['body']) === $esperado('top') && $seq($b2['body']) === $esperado(null, 'asc') && $seq($b3['body']) === $esperado('oldest') && $seq($b4['body']) === $esperado('top') && $seq($b5['body']) === $esperado(null, 'asc'), $js($seq($b1['body'])) . ' | ' . $js($seq($b2['body'])) . ' | ' . $js($seq($b3['body'])) . ' | ' . $js($seq($b4['body'])) . ' | ' . $js($seq($b5['body'])) . ' exp ' . $js($esperado('top')) . ' / ' . $js($esperado(null, 'asc')));
r('T-105', 'la segunda peticion servida desde la cache conserva los textos del aviso y las opciones de script de las herramientas', str_contains($b4['body'], 'COM_ENGAGE_TOOLS_COPY_OK') && str_contains($b4['body'], 'akeeba.Engage.Tools'), (string) preg_match('/COM_ENGAGE_TOOLS_COPY_OK[^,]{0,40}/', $b4['body'], $mx) . ($mx[0] ?? ''));
file_put_contents($cfgPath, $cfgOrig);
q("UPDATE jos_extensions SET enabled=" . (int) $prev['cache'][0] . ", params='" . $db->real_escape_string((string) $prev['cache'][1]) . "' WHERE element='cache' AND folder='system'");
q("UPDATE jos_extensions SET enabled=" . (int) $prev['ecache'][0] . " WHERE element='engagecache' AND folder='system'");
$limpiaCache();
sleep(4);

$errNuevos = (is_file("$WORK/php-errors-site.log") ? filesize("$WORK/php-errors-site.log") : 0) - $logAntes;
$lineasEngage = trim((string) shell_exec('tail -c ' . max(1, $errNuevos) . ' ' . escapeshellarg("$WORK/php-errors-site.log") . ' | grep -Eci "com_engage|plugins/[a-z]+/engage|modules/mod_engage"'));
r('T-110', 'ningun aviso ni error de PHP de Engage en todo el recorrido (los "Deprecated" del SEF de Joomla con un Host hostil no son de Engage)', (int) $lineasEngage === 0, "$errNuevos bytes nuevos, $lineasEngage lineas de Engage");

file_put_contents("$WORK/resultados-tanda2-http.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$fallos = count(array_filter($RES, fn($x) => $x['estado'] === 'FALLA'));
echo "\n" . count($RES) . ' comprobaciones, ' . $fallos . " FALLA\n";
exit($fallos ? 1 : 0);
