<?php
/**
 * 0.8.1: XSS reflejado / almacenado por cache y envenenamiento de la cache de pagina, contra el Joomla REAL por HTTP (curl). Repite el PoC de la
 * revision independiente de la 0.8.0 (`x="><script>alert(document.domain)</script>` en la URL del articulo) y variantes, con y sin cache:
 *  X)  10 configuraciones (sin cache / cache de paginas / cache conservadora, con y sin engagecache, con y sin live_site) x 17 URLs de ataque
 *      (x, p, akengage_sort, akengage_fav, limitstart, akengage_limitstart, akengage_limit, akengage_cid, cid; comillas dobles y simples, <svg onload>,
 *      javascript:, entidades, saltos de linea, //host): la respuesta del atacante Y la del siguiente visitante (cache) no contienen ningun nodo
 *      inyectado, ningun atributo on*, ningun script con alert ni el valor del atacante;
 *  H)  cabecera Host hostil y `p=ATACANTE` con la cache vacia, luego visita normal: no hay rastro (canonical, data-engage-copy, enlaces de fecha, returnurl);
 *  C)  crecimiento de la cache: 30 valores inventados de akengage_sort/akengage_fav/akengage_limit/limitstart/akengage_cid = 0 ficheros nuevos;
 *  F)  favoritos y reacciones con un padre sin publicar / spam / abuelo sin publicar: no se listan, 404 en toggle igual que un comentario inexistente;
 *  R)  returnurl del formulario relativo; `javascript:index.php;...` como returnurl no llega al href del boton Cancelar;
 *  S)  caching=1 SIN engagecache: el orden se ignora (documentado) y el semaforo del panel avisa.
 * Requisitos: 03-sembrar-y-probar.sh y el paquete instalado (02). Escribe $WORK/resultados-xss.json. Sale con 1 si algo FALLA. Restaura todo al acabar.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$SITE = $WORK . '/' . (getenv('SITE_DIR') ?: 'site');
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-8s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [" . substr($ev, 0, 260) . "]" : '');
}
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }

$ASSET = (int) $ids['art_publico_asset'];
$ART   = (int) $ids['art_publico']; $CAT = (int) $ids['cat_publica'];
$PAGE  = "/index.php?option=com_content&view=article&id=$ART&catid=$CAT";
$cfgPath = "$SITE/configuration.php";
$cfgOrig = file_get_contents($cfgPath);
$passFile = "$WORK/xsspass.txt";
if (!is_file($passFile)) { file_put_contents($passFile, 'Aa1!' . bin2hex(random_bytes(10))); chmod($passFile, 0600); }
$PASS = trim(file_get_contents($passFile));
$prev = [
	'com'    => one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"),
	'cache'  => q("SELECT enabled, IFNULL(params,'') FROM jos_extensions WHERE element='cache' AND folder='system'")->fetch_row(),
	'ecache' => q("SELECT enabled FROM jos_extensions WHERE element='engagecache' AND folder='system'")->fetch_row(),
];
$limpiaCache = function () use ($SITE) { foreach (['cache', 'administrator/cache'] as $d) { foreach (glob("$SITE/$d/*", GLOB_ONLYDIR) ?: [] as $g) { exec('rm -rf ' . escapeshellarg($g)); } } };
$restaurar = function () use ($db, $prev, $ASSET, $cfgPath, $cfgOrig, $limpiaCache) {
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string($prev['com']) . "' WHERE element='com_engage' AND type='component'");
	q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
	q("UPDATE jos_extensions SET enabled=" . (int) $prev['cache'][0] . ", params='" . $db->real_escape_string((string) $prev['cache'][1]) . "' WHERE element='cache' AND folder='system'");
	q("UPDATE jos_extensions SET enabled=" . (int) $prev['ecache'][0] . " WHERE element='engagecache' AND folder='system'");
	file_put_contents($cfgPath, $cfgOrig); $limpiaCache();
};
register_shutdown_function(function () use ($restaurar) { if (getenv('NO_RESTAURAR') !== '1') { $restaurar(); } });
$setCom = function (array $extra = []) use ($db) {
	$p = array_merge(['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100'], $extra);
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
};
$setCom();

// ---- Usuarios y comentarios (con padres sin publicar, spam y un abuelo sin publicar)
$mkUser = function (string $user, int $group) use ($PASS, $db) {
	$id = (int) one("SELECT id FROM jos_users WHERE username='$user'"); $h = password_hash($PASS, PASSWORD_BCRYPT);
	if (!$id) {
		q("INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, activation, params, resetCount, otpKey, otep, requireReset, authProvider) VALUES ('$user', '$user', '$user@example.invalid', '$h', 0, 0, NOW(), '', '{}', 0, '', '', 0, '')");
		$id = (int) $db->insert_id; q("INSERT INTO jos_user_usergroup_map (user_id, group_id) VALUES ($id, $group)");
	} else { q("UPDATE jos_users SET password='$h', block=0 WHERE id=$id"); }
	return $id;
};
$uR = $mkUser('xssreg', 2);
q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
$cm = function (string $body, int $by, int $enabled, ?int $parent, string $when) use ($db, $ASSET) {
	$p = $parent === null ? 'NULL' : $parent; $nm = $by ? 'NULL' : "'Invitado'"; $em = $by ? 'NULL' : "'inv@example.invalid'";
	q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET,$p,'" . $db->real_escape_string("<p>$body</p>") . "',$nm,$em,'203.0.113.7','t',$enabled,'$when',$by)");
	return (int) $db->insert_id;
};
$R1 = $cm('Raiz visible', $uR, 1, null, '2026-09-01 10:00:00');
$R2 = $cm('Respuesta visible', 0, 1, $R1, '2026-09-02 10:00:00');
$U0 = $cm('Raiz sin publicar', 0, 0, null, '2026-09-03 10:00:00');
$U1 = $cm('Respuesta publicada de un padre sin publicar', 0, 1, $U0, '2026-09-04 10:00:00');
$U2 = $cm('Nieta publicada (abuelo sin publicar)', 0, 1, $U1, '2026-09-05 10:00:00');
$S0 = $cm('Raiz spam', 0, -3, null, '2026-09-06 10:00:00');
$S1 = $cm('Respuesta publicada de un padre spam', 0, 1, $S0, '2026-09-07 10:00:00');
$R3 = $cm('Otra raiz visible', 0, 1, null, '2026-09-08 10:00:00');

// ---- Utilidades de comprobacion
libxml_use_internal_errors(true);
$analiza = function (string $html): array {
	$d = new DOMDocument(); @$d->loadHTML('<?xml encoding="utf-8"?>' . $html);
	$x = new DOMXPath($d); $scr = 0; $alert = 0; $on = 0; $svg = 0;
	foreach ($x->query('//script') as $s) { if (!$s->hasAttribute('src')) { $scr++; } if (stripos($s->textContent, 'alert(') !== false && stripos($s->getAttribute('type'), 'json') === false) { $alert++; } }
	foreach ($x->query('//*') as $n) { foreach ($n->attributes as $a) { if (stripos($a->name, 'on') === 0) { $on++; } } }
	$svg = $x->query('//svg[@onload]')->length;
	$href = []; foreach ($x->query('//a[@href]') as $a) { $href[] = $a->getAttribute('href'); }
	foreach ($x->query('//*[@src or @action or @formaction or @data]') as $n) { foreach (['src', 'action', 'formaction', 'data'] as $at) { if ($n->hasAttribute($at)) { $href[] = $n->getAttribute($at); } } }
	return ['scripts' => $scr, 'alert' => $alert, 'on' => $on, 'svg' => $svg, 'hrefs' => $href];
};
$G0 = new Cliente($BASE, "$WORK/tmp", 'xss-base');
$limpiaCache(); $baseHtml = $G0->get($PAGE)['body'];
$base = $analiza($baseHtml);
r('X-00', 'base: la pagina sin ataque tiene comentarios y ningun atributo on* ni script con alert', substr_count($baseHtml, 'akengage-comment-item') >= 3 && $base['on'] === 0 && $base['alert'] === 0, 'comentarios=' . substr_count($baseHtml, 'akengage-comment-item') . ' scripts=' . $base['scripts']);

// Texto SIN escapar que cierra un atributo e inyecta una etiqueta (el texto escapado, p. ej. &quot;&gt;&lt;svg%20onload=, es inofensivo y lo ve el DOM)
$PELIGRO = '/["\']\s*>\s*<script|<script[^>]*>[^<]*alert|<svg[^>]*\sonload|<img[^>]*\sonerror|<[a-z]+[^>]*["\']\s+on[a-z]+\s*=\s*["\']?alert/i';
$cargas = [
	'poc'        => 'x=%22%3E%3Cscript%3Ealert(document.domain)%3C/script%3E',
	'simples'    => 'x=%27%3E%3Cscript%3Ealert(1)%3C/script%3E',
	'svg'        => 'x=%22%3E%3Csvg%20onload%3Dalert(1)%3E',
	'js'         => 'x=javascript:alert(1)',
	'entidad'    => 'x=%26quot%3B%26gt%3B%26lt%3Bscript%26gt%3Balert(1)%26lt%3B/script%26gt%3B',
	'salto'      => 'x=a%0d%0a%22%3E%3Cscript%3Ealert(1)%3C/script%3E',
	'doble'      => 'x=%2522%253E%253Cscript%253Ealert(1)%253C/script%253E',
	'p'          => 'p=%22%20onmouseover%3D%22alert(1)',
	'sort'       => 'akengage_sort=%22%3E%3Cscript%3Ealert(1)%3C/script%3E',
	'sort2'      => 'akengage_sort=top%22%20onfocus%3D%22alert(1)',
	'fav'        => 'akengage_fav=%22%3E%3Csvg%20onload%3Dalert(1)%3E',
	'limitstart' => 'limitstart=%22%3E%3Cscript%3Ealert(1)%3C/script%3E',
	'aklimit'    => 'akengage_limitstart=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&akengage_limit=%27%3E%3Cscript%3Ealert(2)%3C/script%3E',
	'cid'        => 'cid=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&akengage_cid=%22%3E%3Cscript%3Ealert(1)%3C/script%3E',
	'cid[]'      => 'cid[]=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&x[]=%22%3E%3Cscript%3Ealert(1)%3C/script%3E',
	'barras'     => 'x=//atacante.test/%22%3E%3Cscript%3Ealert(1)%3C/script%3E',
	'junto'      => 'x=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&akengage_sort=newest&akengage_limitstart=0&akengage_fav=1',
];
$configs = [
	// nombre => [caching, cache de paginas, engagecache, live_site]
	'sin cache, con live_site'                    => [0, 0, 1, 1],
	'sin cache, sin live_site'                    => [0, 0, 1, 0],
	'cache de paginas + engagecache, con live_site' => [1, 1, 1, 1],
	'cache de paginas + engagecache, sin live_site' => [1, 1, 1, 0],
	'cache de paginas SIN engagecache, sin live_site' => [1, 1, 0, 0],
	'conservadora + engagecache, con live_site'   => [1, 0, 1, 1],
	'conservadora + engagecache, sin live_site'   => [1, 0, 1, 0],
	'conservadora SIN engagecache, sin live_site' => [1, 0, 0, 0],
	'cache de paginas SIN engagecache, con live_site' => [1, 1, 0, 1],
	'conservadora SIN engagecache, con live_site' => [1, 0, 0, 1],
];
$aplica = function (array $c) use ($cfgOrig, $cfgPath, $limpiaCache) {
	[$caching, $page, $ecache, $live] = $c;
	$cfg = str_replace('public $caching = 0;', 'public $caching = ' . (int) $caching . ';', $cfgOrig);
	if (!$live) { $cfg = preg_replace("/public \\\$live_site = '[^']*';/", "public \$live_site = '';", $cfg); }
	file_put_contents($cfgPath, $cfg);
	q("UPDATE jos_extensions SET enabled=" . (int) $page . ", params='{\"browsercache\":\"0\",\"cachetime\":\"15\"}' WHERE element='cache' AND folder='system'");
	q("UPDATE jos_extensions SET enabled=" . (int) $ecache . " WHERE element='engagecache' AND folder='system'");
	$limpiaCache(); usleep(2500000);
};
// Solo los grupos de la cache de paginas y de la cache de vistas del articulo (los demas son del propio Joomla: menus, plugins, esquemas...)
$listaCache = fn(): array => array_values(array_filter(explode("\n", trim((string) shell_exec('find ' . escapeshellarg("$SITE/cache") . ' ' . escapeshellarg("$SITE/administrator/cache") . ' -type f ! -name index.html 2>/dev/null'))), fn($f) => preg_match('~/(page|com_content|com_engage)/~', $f) === 1));
$nCache = fn(): int => count($listaCache());

$total = 0; $malos = []; $redirigidas = 0;
$ok_config = [];
foreach ($configs as $nombre => $cfg) {
	$aplica($cfg);
	$mal = [];
	foreach ($cargas as $k => $qs) {
		$limpiaCache();
		$A = new Cliente($BASE, "$WORK/tmp", 'xss-a'); $V = new Cliente($BASE, "$WORK/tmp", 'xss-v');
		$ra = $A->get($PAGE . '&' . $qs);          // el atacante (con la cache vacia: el primero que llega)
		$rv = $V->get($PAGE);                      // el siguiente visitante (normal)
		$rv2 = (new Cliente($BASE, "$WORK/tmp", 'xss-v2'))->get($PAGE . '&' . preg_replace('/^[^=]+=.*?(&|$)/', '', $qs));
		$respuestas = ['ataque' => $ra, 'visitante' => $rv];
		if (in_array($ra['code'], [301, 302], true) && $ra['loc'] !== '') { $respuestas['ataque-redirigido'] = $A->get(preg_replace('~^https?://[^/]+~', '', $ra['loc'])); $redirigidas++; if ($ra['code'] !== 301 || preg_match($PELIGRO, $ra['loc']) || preg_match('/["<>\'\s]/', $ra['loc'])) { $mal[] = "$k: redireccion " . $ra['code'] . ' a ' . substr($ra['loc'], 0, 60); } }
		foreach ($respuestas as $quien => $resp) {
			$total++;
			$an = $analiza($resp['body']);
			$fallo = [];
			if ($resp['code'] >= 500) { $fallo[] = 'HTTP ' . $resp['code']; }
			// Los bloques JSON (JSON-LD de Joomla, opciones de script) no son HTML activo: llevan la URL con % y no se interpretan
			$sinJson = preg_replace('~<script[^>]*type="application/(?:ld\+)?json"[^>]*>.*?</script>~is', '', $resp['body']);
			if (preg_match($PELIGRO, $sinJson, $mm)) { $fallo[] = 'patron: ' . substr($mm[0], 0, 40); }
			if ($an['alert'] > 0 || $an['on'] > $base['on'] || $an['svg'] > 0) { $fallo[] = "nodos inyectados (alert={$an['alert']} on={$an['on']} svg={$an['svg']})"; }
			if ($an['scripts'] > $base['scripts'] + 1) { $fallo[] = "scripts {$an['scripts']} > {$base['scripts']}"; }
			if ($quien === 'visitante' && (stripos($sinJson, 'atacante.test') !== false || stripos($sinJson, 'alert(') !== false)) { $fallo[] = 'rastro del atacante en la pagina del siguiente visitante'; }
			foreach ($an['hrefs'] as $h) { if (preg_match('~^\s*(javascript|data|vbscript):~i', $h) || str_starts_with($h, '//')) { $fallo[] = 'href peligroso: ' . substr($h, 0, 50); } }
			if ($fallo) { $mal[] = "$k/$quien: " . implode('; ', $fallo); }
		}
		unset($rv2);
	}
	r('X-' . substr(md5($nombre), 0, 4), "$nombre: " . count($cargas) . ' cargas x (ataque + visitante siguiente) = ' . (count($cargas) * 2) . ' respuestas sin nodos inyectados ni atributos on*', !$mal, implode(' | ', array_slice($mal, 0, 3)));
	foreach ($mal as $m) { $malos[] = "$nombre :: $m"; }
}
r('X-TOT', "$total respuestas HTML analizadas en " . count($configs) . " configuraciones (PoC exacto y variantes; $redirigidas peticiones con akengage_* invalido recibieron 301 a la URL limpia): " . count($malos) . ' con inyeccion', !$malos);

// ---- H) Host hostil y consulta del primer visitante con la cache vacia
$hosts = ['atacante.test', 'atacante.test:8080', 'a"><script>alert(1)</script>', "x' onmouseover='alert(1)", 'atacante.test/x?y=', 'user:pass@atacante.test', 'javascript:alert(1)//', '[::1]:99', "a b"];
foreach ($configs as $nombre => $cfg) {
	if (!$cfg[0]) { continue; }
	$aplica($cfg);
	$mal = []; $aceptados = 0;
	foreach ($hosts as $hv) {
		$limpiaCache();
		$A = new Cliente($BASE, "$WORK/tmp", 'xss-ha'); $V = new Cliente($BASE, "$WORK/tmp", 'xss-hv');
		$ra = $A->req('GET', $PAGE . '&p=ATACANTE&akengage_sort=top&x=EXTRA', [], ["Host: $hv"]);
		if ($ra['code'] === 200) { $aceptados++; }
		$rv = $V->get($PAGE);
		$rv2 = $V->get($PAGE . '&akengage_sort=top');
		foreach (['visitante' => $rv, 'visitante-orden' => $rv2] as $quien => $resp) {
			$b = preg_replace('~<script[^>]*type="application/(?:ld\+)?json"[^>]*>.*?</script>~is', '', $resp['body']);
			if (stripos($b, 'atacante.test') !== false || str_contains($b, 'ATACANTE') || str_contains($b, 'EXTRA') || preg_match($GLOBALS['PELIGRO'], $b)) { $mal[] = "$hv/$quien"; }
			if (preg_match('/data-engage-copy="(?:[a-z]+:)?\/\//i', $b)) { $mal[] = "$hv/$quien: data-engage-copy absoluto"; }
		}
	}
	r('H-' . substr(md5($nombre), 0, 4), "$nombre: " . count($hosts) . " cabeceras Host hostiles + p=ATACANTE con la cache vacia ($aceptados aceptadas por el servidor): la visita normal siguiente no tiene rastro", !$mal, implode(' | ', array_slice($mal, 0, 4)));
}
// canonical y enlaces: valores exactos con live_site
$aplica($configs['sin cache, con live_site']);
$V = new Cliente($BASE, "$WORK/tmp", 'xss-can');
$hc = $V->get($PAGE . '&akengage_sort=top&p=ATACANTE')['body'];
preg_match('/<link href="([^"]*)" rel="canonical"/', $hc, $mc);
r('H-CAN', 'canonical con orden elegido: usa el live_site de Joomla y la consulta limpia (sin p=ATACANTE ni akengage_sort)', ($mc[1] ?? '') === "http://127.0.0.1:8080/index.php?option=com_content&amp;view=article&amp;id=$ART&amp;catid=$CAT", $mc[1] ?? '(sin canonical)');
$aplica($configs['sin cache, sin live_site']);
$hn = (new Cliente($BASE, "$WORK/tmp", 'xss-can2'))->req('GET', $PAGE . '&akengage_sort=top', [], ['Host: atacante.test'])['body'];
r('H-CAN2', 'sin live_site NO hay canonical que salga de la cabecera Host', !preg_match('~rel="canonical"[^>]*atacante\.test|atacante\.test[^>]*rel="canonical"~', $hn) && !preg_match('~<link href="[^"]*atacante~', $hn), '');
$hlinks = (new Cliente($BASE, "$WORK/tmp", 'xss-can3'))->get($PAGE . '&akengage_sort=top&p=ATACANTE&Itemid=101&lang=es-ES')['body'];
preg_match_all('/href="([^"]*akengage_cid=[^"]*)"/', $hlinks, $ml);
$linksOk = $ml[1] && !array_filter($ml[1], fn($h) => preg_match('~^[a-z]+:|^//|ATACANTE|akengage_sort|atacante~i', $h));
r('H-LNK', 'los enlaces de fecha / «en respuesta a» son relativos, sin Host ni parametros del atacante, con akengage_cid y ancla (' . count($ml[1]) . ' enlaces)', (bool) $linksOk, $ml[1][0] ?? '');
preg_match('/data-engage-copy="([^"]*)"/', $hlinks, $mcp);
r('H-COPY', 'data-engage-copy es relativo (ruta + consulta validada + akengage_cid + ancla)', isset($mcp[1]) && preg_match('~^/[^"]*akengage_cid=\d+#akengage-comment-\d+$~', html_entity_decode($mcp[1])) === 1, $mcp[1] ?? '(sin boton)');
preg_match('/name="returnurl" value="([^"]*)"/', $hlinks, $mr);
$ru = isset($mr[1]) ? base64_decode(html_entity_decode($mr[1])) : '(sin formulario)';
r('H-RET', 'returnurl del formulario: relativo y sin parametros desconocidos', $ru !== '' && $ru[0] === '/' && !str_contains($ru, 'ATACANTE') && !preg_match('~://~', $ru), $ru);

// ---- C) Crecimiento de la cache
foreach (['cache de paginas + engagecache, con live_site', 'conservadora + engagecache, con live_site'] as $nombre) {
	$aplica($configs[$nombre]);
	$esPagina = $configs[$nombre][1] === 1;
	$C = new Cliente($BASE, "$WORK/tmp", 'xss-c');
	$C->get($PAGE); $C->get($PAGE); $C->get($PAGE); $n0 = $nCache(); $l0 = $listaCache();
	for ($i = 0; $i < 30; $i++) {
		$C->get($PAGE . "&akengage_sort=valor$i"); $C->get($PAGE . "&akengage_fav=v$i"); $C->get($PAGE . '&akengage_limit=' . (1000 + $i));
		$C->get($PAGE . '&akengage_limitstart=' . (10007 + $i)); $C->get($PAGE . '&akengage_limitstart=' . (21 + 20 * $i)); $C->get($PAGE . '&akengage_cid=' . (900000 + $i));
	}
	$n1 = $nCache(); $nuevos = implode(',', array_map(fn($f) => basename(dirname($f)), array_diff($listaCache(), $l0)));
	r('C-' . substr(md5($nombre), 0, 4), "$nombre: 180 peticiones con valores inventados (30 de akengage_sort, akengage_fav, akengage_limit, akengage_limitstart fuera de tope, akengage_limitstart que no es multiplo de la pagina y akengage_cid) = " . ($n1 - $n0) . ' entradas de cache nuevas (antes de la 0.8.1: +79 con solo 30 de sort/fav)', $n1 === $n0, "antes=$n0 despues=$n1 nuevos=$nuevos");
	for ($i = 0; $i < 30; $i++) { $C->get($PAGE . "&x=$i"); }
	$n2 = $nCache();
	r('C-X' . substr(md5($nombre), 0, 3), "$nombre: 30 URLs con un parametro ajeno (x=N): " . ($esPagina ? 'la cache de paginas de Joomla guarda una entrada por URL completa (inherente a Joomla, no a Engage; ver docs/ORDEN-Y-FAVORITOS.md)' : 'la cache conservadora no las distingue (ninguna entrada nueva)') . ' = +' . ($n2 - $n1), $esPagina ? ($n2 - $n1) <= 32 : $n2 === $n1, "antes=$n1 despues=$n2");
	$C->get($PAGE . '&akengage_sort=top'); $C->get($PAGE . '&akengage_sort=oldest'); $C->get($PAGE . '&akengage_limitstart=20&akengage_limit=10'); $n3 = $nCache();
	r('C-OK' . substr(md5($nombre), 0, 3), "$nombre: los valores LEGITIMOS siguen teniendo su propia entrada (top, oldest, pagina 3: +" . ($n3 - $n2) . ')', $n3 - $n2 >= 3, "antes=$n2 despues=$n3");
}

// ---- F) Favoritos y reacciones con la cadena de padres
$aplica($configs['sin cache, con live_site']);
foreach ([$R1, $R2, $U1, $U2, $S1, $R3] as $c) { q("INSERT IGNORE INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($c,$uR,3,NOW())"); }
$U = new Cliente($BASE, "$WORK/tmp", 'xss-u');
$entro = $U->login('xssreg', $PASS);
r('F-00', 'login del usuario de prueba', $entro);
$secuencia = function (string $h): array { preg_match_all('/id="akengage-comment-(\d+)"/', $h, $m); return array_map('intval', $m[1]); };
$normal = $secuencia($U->get($PAGE)['body']);
$favs   = $secuencia($U->get($PAGE . '&akengage_fav=1')['body']);
r('F-01', 'la lista normal solo muestra las respuestas de padres publicados (' . implode(',', $normal) . ')', !array_intersect([$U0, $U1, $U2, $S0, $S1], $normal) && in_array($R2, $normal, true));
$esperados = array_values(array_intersect($favs, [$R1, $R2, $R3]));
r('F-02', 'la lista «solo mis favoritos» NO incluye las respuestas que la lista normal oculta (' . implode(',', $favs) . ')', !array_intersect([$U1, $U2, $S1], $favs) && count($favs) === 3 && sort($esperados) !== null && $esperados === [$R1, $R2, $R3], 'favs=' . implode(',', $favs));
$cab = $U->get($PAGE . '&akengage_fav=1')['body'];
preg_match('~<h3[^>]*>(.*?)</h3>~s', $cab, $mh);
r('F-03', 'el encabezado cuenta solo los visibles (3) y la paginacion no los infla', str_contains($cab, '(3)'), trim(strip_tags($mh[1] ?? '(sin h3)')));
$st = json_decode($U->get('/index.php?option=com_engage&task=reactions.state&format=json&ids=' . implode(',', [$R1, $R2, $U1, $U2, $S1]))['body'], true);
$tok = $st['token'] ?? '';
r('F-04', 'state omite sin pistas las respuestas ocultas (' . implode(',', array_keys((array) ($st['items'] ?? []))) . ')', array_map('strval', array_keys((array) ($st['items'] ?? []))) === [(string) $R1, (string) $R2]);
$tog = function (int $c, string $t) use ($U, $tok): array { $x = $U->post('/index.php?option=com_engage&task=reactions.toggle&format=json', ['comment_id' => $c, 'type' => $t, $tok => 1]); return [$x['code'], json_decode($x['body'], true), $x['body']]; };
$nf = (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE user_id=$uR");
$inex = $tog(999999, 'favorite');
$todos = true; $iguales = true; $det = [];
foreach ([$U1, $U2, $S1] as $c) { foreach (['favorite', 'like'] as $t) { $x = $tog($c, $t); $todos = $todos && $x[0] === 404; $iguales = $iguales && $x[2] === $inex[2]; $det[] = "$c/$t=" . $x[0]; } }
r('F-05', 'toggle (favorito y me gusta) en respuestas de padre sin publicar / spam / abuelo sin publicar: 404', $todos, implode(' ', $det));
r('F-06', 'y la respuesta es identica byte a byte a la de un comentario que no existe (sin pistas)', $iguales, $inex[2]);
r('F-07', 'no se escribio ni se borro nada', (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE user_id=$uR") === $nf);
$x = $tog($R2, 'like');
r('F-08', 'una respuesta visible (padre publicado) sigue funcionando: 200', $x[0] === 200 && !empty($x[1]['ok']), $x[2]);
$x = $tog($R2, 'like');

// ---- R) returnurl
$aplica($configs['sin cache, con live_site']);
$pagina = $U->get($PAGE . '&p=ATACANTE')['body'];
preg_match('/name="returnurl" value="([^"]*)"/', $pagina, $mm); $rec = isset($mm[1]) ? base64_decode(html_entity_decode($mm[1])) : '';
r('R-01', 'returnurl del formulario con sesion: relativo (' . $rec . ')', $rec !== '' && $rec[0] === '/' && !str_contains($rec, 'ATACANTE'));
preg_match('/akeeba\.Engage\.Comments\.editURL"\s*:\s*"([^"]*)"/', str_replace('\\/', '/', $pagina), $me); $editUrl = isset($me[1]) ? base64_decode($me[1]) : '';
$editUrl = $editUrl ?: '';
if (preg_match('/editURL[^A-Za-z0-9+\/=]*([A-Za-z0-9+\/=]{20,})/', $pagina, $me2)) { $editUrl = base64_decode(str_replace('\\/', '/', $me2[1])); }
$editUrl = str_replace(['__ID__'], [(string) $R1], html_entity_decode($editUrl));
$vals = ['javascript:index.php;alert(document.domain)', 'JaVaScRiPt:index.php%0aalert(1)', 'data:text/html,index.php', 'https://evil.example/index.php', '//evil.example/index.php', "/index.php\"onmouseover=\"alert(1)", '/index.php?x=1'];
$mal = []; $vistos = 0;
foreach ($vals as $v) {
	$u = $editUrl; $u = preg_replace('/([?&])returnurl=[^&]*/', '$1returnurl=' . rawurlencode(base64_encode($v)), $u);
	if ($editUrl === '' || !str_contains($u, 'returnurl=')) { continue; }
	$rr = $U->get(preg_replace('~^https?://[^/]+~', '', $u));
	$vistos++;
	if (preg_match('/<a href="(javascript|data|vbscript)|href="[^"]*evil\.example|onmouseover=/i', $rr['body'])) { $mal[] = $v; }
}
r('R-02', "returnurl hostil en la pagina de edicion ($vistos variantes: javascript:index.php;..., data:, otro sitio, comillas): ninguna llega a un href", !$mal && $vistos >= 5, implode(' | ', $mal) . ($editUrl === '' ? ' (sin URL de edicion)' : ''));

// ---- S) Cache global SIN engagecache: el orden se ignora y el panel avisa
$aplica($configs['conservadora SIN engagecache, con live_site']);
$S = new Cliente($BASE, "$WORK/tmp", 'xss-s');
$s0 = $secuencia($S->get($PAGE)['body']); $s1 = $secuencia($S->get($PAGE . '&akengage_sort=newest')['body']);
r('S-01', 'caching=1 SIN el plugin Engage Cache: el orden elegido se ignora (limitacion conocida y documentada; no falla ni cuelga)', $s0 === $s1 && $s0 !== [], implode(',', $s0) . ' | ' . implode(',', $s1) . ' | cfg: ' . trim((string) shell_exec('grep -E "caching|live_site" ' . escapeshellarg($cfgPath) . ' | tr -s "\t " " " | tr "\n" ";"')) . ' | ' . implode(',', array_map(fn($r) => $r[0] . '=' . $r[1], q("SELECT element, enabled FROM jos_extensions WHERE element IN ('cache','engagecache') AND folder='system'")->fetch_all())));
$adm = new Cliente($BASE, "$WORK/tmp", 'xss-adm');
$admPass = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
if ($adm->loginAdmin('admintest', $admPass)) {
	$panel = $adm->get('/administrator/index.php?option=com_engage')['body'];
	r('S-02', 'el semaforo «Page cache» del panel avisa en ambar con la cache de Joomla activa y sin el plugin', preg_match('/Joomla cache is on but the System - Engage cache plugin is (disabled|not installed)[^<]*sort order/', $panel) === 1, preg_match('~Page cache.{0,600}~s', strip_tags($panel), $mp) ? trim(preg_replace('/\s+/', ' ', $mp[0])) : 'sin bloque');
	$aplica($configs['conservadora + engagecache, con live_site']);
	$panel = $adm->get('/administrator/index.php?option=com_engage')['body'];
	r('S-03', 'con el plugin activado el semaforo vuelve a verde', str_contains($panel, 'The Joomla page cache is on and the Engage cache plugin is enabled'), '');
} else { r('S-02', 'login de administrador', false); }

$f = fopen("$WORK/resultados-xss.json", 'w'); fwrite($f, json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); fclose($f);
$fallas = count(array_filter($RES, fn($x) => $x['estado'] === 'FALLA'));
echo "\n-> " . (count($RES) - $fallas) . " PASAN, $fallas FALLAN\n";
exit($fallas ? 1 : 0);
