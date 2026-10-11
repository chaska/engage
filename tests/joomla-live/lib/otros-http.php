<?php
/**
 * 0.8.2: comentarios en el contenido de OTROS componentes, contra el Joomla REAL por HTTP (curl). Usa los plugins de prueba
 * tests/joomla-live/lib/tercero-prueba (el «tercero» bien hecho, que es el ejemplo de docs/COMENTARIOS-EN-OTROS-COMPONENTES.md) y
 * lib/tercero-hostil (responde con tipos raros y texto con HTML/JS). Requiere haber ejecutado lib/otros-datos.php crear.
 *   M) matriz: 14 elementos x invitado / Registered / Manager x 4 configuraciones de cache: hilo solo si published, categoria, access,
 *      parent_access y comments_show lo permiten; formulario / aviso de cerrado segun comments_enabled y comments_close_after;
 *   H) 12 fichas hostiles (tipos raros, article, access invalido...): nada, salvo las dos con texto hostil, que salen ESCAPADAS;
 *   A) asset_id en bruto invalido (0, -5, abc, 12abc, 1e3, 0x1A, desconocido, enorme...): nada; sin avisos de PHP;
 *   C) contexto falsificado: el contexto no concede nada; com_content.* y el de los articulos no pasan por el camino generico;
 *   P) la peticion no decide el activo (asset_id, option, task, view en la URL), vista de comentarios inalcanzable por URL directa;
 *   E) escritura: publicar un comentario solo en lo que se puede ver y esta abierto;
 *   K) cache de pagina: el orden de los visitantes no filtra nada (invitado primero / Registered primero);
 *   S) sin avisos ni errores nuevos en el registro de PHP.
 * Escribe $WORK/resultados-otros-http.json. Sale con 1 si algo FALLA. Restaura la configuracion de cache al acabar.
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
	printf("%-9s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [" . substr($ev, 0, 240) . "]" : '');
}
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
$CFG = __DIR__ . '/xss-config.php';
$cfg = fn(string ...$a) => shell_exec('php ' . escapeshellarg($CFG) . ' ' . implode(' ', array_map('escapeshellarg', $a)) . ' 2>&1');
register_shutdown_function(function () use ($cfg) { $cfg('restore'); });
$log = "$WORK/php-errors-" . (getenv('SITE_DIR') ?: 'site') . ".log";
$logAntes = is_file($log) ? filesize($log) : 0;

$PASS = trim(file_get_contents("$WORK/otrospass.txt"));
$mk = function (string $user) use ($BASE, $WORK, $PASS) { $c = new Cliente($BASE, "$WORK/tmp", "otros-$user"); if (!$c->login($user, $PASS)) { die("No se pudo iniciar sesion como $user\n"); } return $c; };
$nuevo = fn() => new Cliente($BASE, "$WORK/tmp", 'otros-g' . bin2hex(random_bytes(3)));
$guest = $nuevo(); $reg = $mk('otrosreg'); $mgr = $mk('otrosmgr');
$ACT = ['invitado' => $guest, 'registered' => $reg, 'manager' => $mgr];
$hilo  = fn(string $h, int $asset) => str_contains($h, 'id="akengage-comments-section"') && str_contains($h, "MARCA-$asset");
$sinHilo = fn(string $h, int $asset) => !str_contains($h, 'id="akengage-comments-section"') && !str_contains($h, "MARCA-$asset");
$form  = fn(string $h) => str_contains($h, 'comment.save');
$cerrado = fn(string $h) => str_contains($h, 'akengage-comments-section') && str_contains($h, 'Comments are closed');
// ¿Pueden comentar los invitados en este sitio? (los permisos de com_engage no los toca esta prueba): se mira en el elemento publico
$ref = $guest->get('/index.php?tp=1')['body'];
$guestForm = $form($ref);
echo "(Los invitados " . ($guestForm ? 'SI' : 'NO') . " pueden comentar en este sitio; no se cambian los permisos)\n";

// [id => [invitado, registered, manager]] con 'T' = hilo, '-' = nada
$M = [
	1  => ['T', 'T', 'T'], 2 => ['-', '-', '-'], 3 => ['-', 'T', 'T'], 4 => ['-', '-', 'T'], 5 => ['-', '-', '-'], 6 => ['-', '-', '-'], 7 => ['T', 'T', 'T'],
	8  => ['T', 'T', 'T'], 9 => ['T', 'T', 'T'], 10 => ['T', 'T', 'T'], 11 => ['-', '-', '-'], 12 => ['-', '-', '-'], 13 => ['T', 'T', 'T'], 14 => ['T', 'T', 'T'],
];
$configs = [
	'sin cache'                         => ['0', '0', '1', '1'],
	'cache de paginas + engagecache'    => ['1', '1', '1', '1'],
	'cache de paginas SIN engagecache'  => ['1', '1', '0', '1'],
	'conservadora + engagecache'        => ['1', '0', '1', '1'],
];
$malas = [];
foreach ($configs as $nombre => $c) {
	$cfg('set', ...$c);
	// Cada configuracion empieza con la cache vacia; el orden «invitado primero» es el peor caso para un fallo de cache
	foreach ($M as $n => $esperado) {
		$asset = 900000 + $n;
		foreach (array_keys($ACT) as $i => $actor) {
			$h = $ACT[$actor]->get("/index.php?tp=$n"); $b = $h['body'];
			$ok = $h['code'] === 200 && (($esperado[$i] === 'T') ? $hilo($b, $asset) : $sinHilo($b, $asset));
			if (!$ok) { $malas[] = "[$nombre] elemento $n como $actor: esperado {$esperado[$i]}, codigo {$h['code']}, hilo=" . var_export($hilo($b, $asset), true); }
		}
	}
	r('O-M-' . substr(md5($nombre), 0, 4), "matriz de 14 elementos x 3 actores con «{$nombre}»: hilo solo donde published, categoria, access, parent_access y comments_show lo permiten", !$malas, implode(' | ', array_slice($malas, 0, 4)));
	$malas = [];
}

// ---- Formulario y cierre (sin cache)
$cfg('set', '0', '0', '1', '1');
$b1 = $reg->get('/index.php?tp=1')['body'];
r('O-F1', 'Registered en un elemento abierto: hilo + formulario, sin aviso de cerrado', $hilo($b1, 900001) && $form($b1) && !$cerrado($b1));
$b8 = $reg->get('/index.php?tp=8')['body'];
r('O-F8', 'comments_enabled = 0: el hilo se ve pero SIN formulario y con el aviso de cerrado', $hilo($b8, 900008) && !$form($b8) && $cerrado($b8));
$b9 = $reg->get('/index.php?tp=9')['body']; $b9b = $reg->get('/index.php?tp=9')['body'];
r('O-F9', 'comments_close_after = 30 con publicacion hace 40 dias: cerrado, y sigue cerrado en la 2.a visita (la fecha de la cache no se mueve)', $hilo($b9, 900009) && !$form($b9) && $cerrado($b9) && !$form($b9b) && $cerrado($b9b));
$b10 = $reg->get('/index.php?tp=10')['body'];
r('O-F10', 'comments_close_after = 30 con publicacion hace 10 dias: abierto', $hilo($b10, 900010) && $form($b10) && !$cerrado($b10));
$b7 = $reg->get('/index.php?tp=7')['body'];
r('O-F7', 'categoria archivada (2): se ve, como en Joomla', $hilo($b7, 900007));
$b14 = $mgr->get('/index.php?tp=14')['body'];
r('O-F14', 'sin parent_access ni categoria (NULL): se ve', $hilo($b14, 900014));

// ---- Texto hostil del tercero (13 = el tercero «bueno» pasa texto hostil tal cual; 910003 y 910011 = el hostil)
$hostiles = [['/index.php?tp=13', 900013], ['/index.php?tp_asset=910003', 910003], ['/index.php?tp_asset=910011', 910011]];
foreach ($hostiles as [$ruta, $asset]) {
	foreach (['sin cache' => ['0', '0', '1', '1'], 'cache de paginas' => ['1', '1', '1', '1']] as $nombre => $c) {
		$cfg('set', ...$c);
		foreach ([$guest, $reg] as $cl) {
			$res = $cl->get($ruta); $res2 = $cl->get($ruta);
			foreach ([$res, $res2] as $k => $x) {
				$b = $x['body'];
				$mal = [];
				if (!$hilo($b, $asset)) { $mal[] = 'no sale el hilo'; }
				if (preg_match('~<script[^>]*>[^<]*(window\.__xss|alert\()~i', $b)) { $mal[] = 'script del atacante'; }
				if (preg_match('~<img[^>]*onerror~i', $b)) { $mal[] = 'img onerror'; }
				if (str_contains($b, '<img src=x')) { $mal[] = 'img sin escapar'; }
				if (stripos($b, 'javascript:window.__xss') !== false) { $mal[] = 'URL javascript:'; }
				if (stripos($b, 'evil.test') !== false) { $mal[] = 'URL de otro sitio'; }
				if (stripos($b, 'Bcc: x@y.z') !== false) { $mal[] = 'cabecera en el correo'; }
				if (!str_contains($b, '&lt;script&gt;') && $asset !== 910003 + 0 && false) { $mal[] = 'x'; }
				if (!$mal && !str_contains($b, 'window.__xss=1;alert(document.domain)&lt;/script&gt;')) { $mal[] = 'el titulo no aparece escapado (se esperaba el texto como texto)'; }
				r('O-X-' . $asset . '-' . substr(md5($nombre . $k . spl_object_id($cl)), 0, 3), "texto hostil de $asset con «{$nombre}», visita " . ($k + 1) . ': escapado, sin script ni URL peligrosa', !$mal, implode(', ', $mal));
			}
		}
	}
}
$cfg('set', '0', '0', '1', '1');

// ---- Fichas hostiles: nada (salvo las dos con texto hostil, ya comprobadas, y la 910008, que se ve de solo lectura)
$esperadoH = [910001 => '-', 910002 => '-', 910003 => 'T', 910004 => '-', 910005 => '-', 910006 => '-', 910007 => '-', 910008 => 'T', 910009 => '-', 910010 => '-', 910011 => 'T', 910012 => '-'];
$malas = [];
foreach ($esperadoH as $a => $e) {
	foreach ($ACT as $actor => $cl) {
		$b = $cl->get("/index.php?tp_asset=$a")['body'];
		if (($e === 'T') !== $hilo($b, $a)) { $malas[] = "$a como $actor: esperado $e"; }
	}
}
r('O-H', '12 fichas hostiles x 3 actores: access/published/parent_access/category_published invalidos, tipo `article` o raro, parametros que no son un Registry: no abren nada', !$malas, implode(' | ', array_slice($malas, 0, 5)));
$b8h = $reg->get('/index.php?tp_asset=910008')['body'];
r('O-H8', 'parametros que no son un Registry (910008): el hilo se ve pero cerrado (nadie dijo que los comentarios estuvieran abiertos)', $hilo($b8h, 910008) && !$form($b8h));

// ---- asset_id en bruto invalido
$crudos = ['0', '-5', '-1', 'abc', '12abc', '1e3', '0x1A', '1.5', '999999', '4294967296', '99999999999999999999', '%20900001', '900001%20', '900001%0a', '9000010', '%2B900001', '900001abc', '', 'null', '900001%00'];
$malos = [];
foreach ($crudos as $a) {
	foreach ([$guest, $mgr] as $cl) {
		$x = $cl->get("/index.php?tp_asset=$a"); $b = $x['body'];
		if ($x['code'] >= 500 || str_contains($b, 'akengage-comments-section') || preg_match('/MARCA-\d+/', $b)) { $malos[] = "$a ({$x['code']})"; }
	}
}
r('O-A', count($crudos) . ' asset_id en bruto invalidos o desconocidos x invitado y Manager: nada y sin errores 5xx', !$malos, implode(', ', $malos));
// Un asset_id valido pero de otro tipo (el articulo, reconocido por el plugin de Engage) en un contexto ajeno: reservado para com_content
$art = (int) $ids['art_publico_asset'];
$x = $mgr->get("/index.php?tp_asset=$art&tp_ctx=com_terceroprueba.elemento")['body'];
r('O-A2', 'el asset de un ARTICULO en el contexto de otro componente: nada (el tipo `article` es de com_content)', !str_contains($x, 'akengage-comments-section'));

// ---- Contexto falsificado
$malos = [];
foreach (['com_content.article', 'com_content.category', 'com_content.featured', 'COM_CONTENT.ARTICLE', 'com_content.', 'mod_custom.content', 'text', 'x"><script>alert(1)</script>', rawurlencode(str_repeat('a', 150))] as $ctx) {
	$c = rawurlencode($ctx);
	$x = $guest->get("/index.php?tp_asset=900003&tp_ctx=$c")['body']; // el elemento 3 es solo para Registered
	if (str_contains($x, 'akengage-comments-section') || str_contains($x, 'MARCA-900003')) { $malos[] = "invitado/$ctx"; }
	if ($ctx !== rawurlencode(str_repeat('a', 150)) && $ctx !== 'text' && $ctx !== 'mod_custom.content' && !str_contains($ctx, 'x"')) {
		$x2 = $mgr->get("/index.php?tp_asset=900001&tp_ctx=$c")['body']; // el 1 es publico, pero los contextos de com_content no entran por el camino generico
		if (stripos($ctx, 'com_content') === 0 && (str_contains($x2, 'akengage-comments-section') && $ctx !== 'x')) { $malos[] = "manager/$ctx"; }
	}
}
r('O-C1', 'contexto falsificado (com_content.*, mod_*, texto raro) con un elemento restringido: el invitado no ve nada; los de com_content no usan el camino generico', !$malos, implode(', ', $malos));
$x = $guest->get('/index.php?tp_asset=900003&tp_ctx=com_otro.cosa')['body']; $y = $reg->get('/index.php?tp_asset=900003&tp_ctx=com_otro.cosa')['body'];
r('O-C2', 'otro contexto cualquiera (com_otro.cosa): lo decide el activo, no el texto; el invitado nada y el Registered el hilo', !str_contains($x, 'MARCA-900003') && $hilo($y, 900003));
$x = $guest->get('/index.php?tp_asset=900001&tp_ctx=com_a.item')['body'];
r('O-C3', 'cualquier contexto con un activo publico: el hilo (el contexto no abre ni cierra)', $hilo($x, 900001));

// ---- La peticion no decide el activo ni la tarea
$x = $guest->get('/index.php?tp=1&asset_id=900003&akengage_order_Dir=DESC;DROP&layout=edit')['body'];
r('O-P1', 'asset_id (y layout, akengage_order_Dir) de la peticion NO cambian el activo: sale el 900001, no el 900003', $hilo($x, 900001) && !str_contains($x, 'MARCA-900003'));
$malos = [];
foreach (['/index.php?option=com_engage&view=comments&asset_id=900001', '/index.php?option=com_engage&task=comments.main&asset_id=900001', '/index.php?option=com_engage&task=main&asset_id=900001', '/index.php?option=com_engage&view=comments&layout=default&asset_id=900001&format=raw', '/index.php?option=com_engage&view=comments&asset_id=900003'] as $u) {
	foreach ($ACT as $actor => $cl) {
		$x = $cl->get($u);
		if (preg_match('/MARCA-9\d+/', $x['body']) || str_contains($x['body'], 'akengage-comments-section')) { $malos[] = "$actor $u"; }
	}
}
r('O-P2', 'la vista de comentarios no es alcanzable por URL directa (5 URLs x 3 actores): ni hilo ni marcas', !$malos, implode(' | ', $malos));

// ---- Cache estatica de vistas de Joomla (BaseController::getView): una vista ajena `comments` no es la de Engage
foreach ([$guest, $reg] as $cl) {
	$x = $cl->get('/index.php?tp=1&tp_vista=1')['body'];
	r('O-V1', 'con una vista `comments` ajena ya en la cache estatica de vistas de Joomla, Engage usa la SUYA (hilo y marca, sin «VISTA-AJENA»)', $hilo($x, 900001) && !str_contains($x, 'VISTA-AJENA'));
}

// ---- layout / view de la peticion: lista cerrada (LFI, CVE-2026-40383)
$lfi = ['../../../../configuration', '..%2f..%2f..%2f..%2fconfiguration', '....//....//....//configuration', '%00', 'default%00', 'default%00.php', '../x', '..\\..\\x', 'x/y', '_form', 'default_form', 'edit', 'a.b', '%2e%2e/%2e%2e/configuration', rawurlencode(str_repeat('a', 500)), 'default/../../../configuration'];
$malos = [];
foreach ($lfi as $l) {
	foreach (['/index.php?tp=1&layout=', '/index.php?option=com_engage&view=comment&layout=', '/index.php?option=com_engage&view=comments&layout=', '/index.php?option=com_engage&task=comment.edit&layout=', '/index.php?option=com_engage&view=../../x&layout='] as $u) {
		foreach ([$guest, $mgr] as $cl) {
			$x = $cl->get($u . $l); $b = $x['body'];
			if ($x['code'] >= 500 || str_contains($b, 'class JConfig') || str_contains($b, '$password') || str_contains($b, 'root:x:') || preg_match('/MARCA-9\d+/', $b) && !str_contains($u, 'tp=1')) { $malos[] = substr($u, 0, 50) . $l . " ({$x['code']})"; }
		}
	}
}
r('O-L', count($lfi) . ' valores de layout hostiles x 5 rutas x 2 actores: nunca se incluye un fichero ajeno (sin configuracion ni /etc/passwd), sin 5xx ni hilo por URL directa', !$malos, implode(' | ', array_slice($malos, 0, 4)));

// ---- Escritura
$cfg('set', '0', '0', '1', '1');
$publicar = function (Cliente $cl, int $asset, string $texto, string $ruta = '/index.php?tp=1') use ($BASE) {
	$t = Cliente::token($cl->get($ruta)['body']) ?: Cliente::token($cl->get('/')['body']);

	return $cl->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode($BASE . '/'), $t => 1, 'jform' => ['asset_id' => (string) $asset, 'parent_id' => '0', 'name' => 'Prueba', 'email' => 'p@example.invalid', 'body' => $texto]]);
};
$cuenta = fn(string $texto) => (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE body LIKE '%" . $db->real_escape_string($texto) . "%'");
$n = 0; $casos = [
	// [asset, actor, ¿debe quedar guardado?, ruta de la pagina de la que se saca el token]
	[900001, 'registered', true], [900002, 'registered', false], [900003, 'invitado', false], [900003, 'registered', true], [900004, 'registered', false], [900004, 'manager', true],
	[900005, 'manager', false], [900006, 'manager', false], [900007, 'registered', true], [900008, 'registered', false], [900009, 'registered', false], [900010, 'registered', true],
	[900012, 'manager', false], [910001, 'registered', false], [910006, 'manager', false], [910009, 'manager', false], [910008, 'registered', false],
	[999999, 'registered', false], [0, 'registered', false], [-5, 'registered', false],
];
$malos = [];
foreach ($casos as [$a, $actor, $debe]) {
	$tag = 'ESCRIBE-' . (++$n) . '-' . bin2hex(random_bytes(3));
	if ($actor === 'invitado' && !$guestForm && $debe) { continue; }
	$publicar($ACT[$actor], $a, $tag);
	$hay = $cuenta($tag) === 1;
	if ($hay !== $debe) { $malos[] = "$actor en $a: esperado " . ($debe ? 'guardado' : 'rechazado') . ' y fue lo contrario'; }
}
r('O-E1', count($casos) . ' intentos de comentar (por la ruta normal del formulario, con token): solo se guarda donde el activo es visible, esta publicado y abierto', !$malos, implode(' | ', $malos));
$guardado = one("SELECT CONCAT(enabled, '|', asset_id) FROM jos_engage_comments WHERE body LIKE '%ESCRIBE-1-%'");
r('O-E2', 'el comentario guardado queda enlazado a SU asset_id y publicado segun default_publish (1)', $guardado === '1|900001', (string) $guardado);
// El formulario para otro activo no sirve con el token de una pagina que no se puede ver
$tag = 'ESCRIBE-X-' . bin2hex(random_bytes(3));
$guest->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode($BASE . '/'), Cliente::token($guest->get('/')['body']) => 1, 'jform' => ['asset_id' => '900004', 'parent_id' => '0', 'name' => 'x', 'email' => 'x@example.invalid', 'body' => $tag]]);
r('O-E3', 'un invitado con un token valido de la portada tampoco comenta en un activo con padre Special', $cuenta($tag) === 0);

// ---- Cache de pagina: el orden de los visitantes no filtra nada
$malos = [];
foreach (['cache de paginas + engagecache' => ['1', '1', '1', '1'], 'cache de paginas SIN engagecache' => ['1', '1', '0', '1']] as $nombre => $c) {
	foreach ([['registered', 'invitado'], ['invitado', 'registered', 'invitado'], ['manager', 'invitado', 'registered']] as $orden) {
		$cfg('set', ...$c);
		foreach ($orden as $actor) {
			$cl = ($actor === 'invitado') ? $nuevo() : $ACT[$actor];
			foreach ([3, 4] as $n) {
				$b = $cl->get("/index.php?tp=$n")['body'];
				$veria = ($n === 3) ? in_array($actor, ['registered', 'manager'], true) : ($actor === 'manager');
				if ($veria !== $hilo($b, 900000 + $n)) { $malos[] = "[$nombre] orden " . implode('>', $orden) . ": $actor en $n"; }
			}
		}
	}
}
r('O-K', 'cache de pagina (con y sin engagecache), 3 ordenes de visita: el hilo restringido nunca llega a quien no puede verlo, ni se esconde a quien si', !$malos, implode(' | ', array_slice($malos, 0, 4)));
$cfg('set', '0', '0', '1', '1');

// ---- Registro de PHP
clearstatcache();
$nuevoLog = is_file($log) ? substr((string) file_get_contents($log), $logAntes) : '';
// Todo salvo los avisos del propio Joomla 6.1 (libraries/src/Access/Access.php, null en strpos), que no son de este cambio
$lineas = array_values(array_filter(explode("\n", trim($nuevoLog)), fn($l) => !str_contains($l, 'libraries/src/Access/Access.php')));
r('O-S', 'el registro de PHP (error_reporting=-1) no tiene avisos, deprecations ni errores nuevos de Engage ni de los plugins de prueba', !$lineas, implode(' || ', array_slice($lineas, 0, 3)));

file_put_contents("$WORK/resultados-otros-http.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$ko = count(array_filter($RES, fn($x) => $x['estado'] !== 'PASA'));
echo "\nRESUMEN: " . (count($RES) - $ko) . " PASA, $ko FALLA\n";
exit($ko ? 1 : 0);
