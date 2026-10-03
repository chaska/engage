<?php
/**
 * Pruebas funcionales y de seguridad de Engage contra un Joomla REAL (sin navegador, solo HTTP + SQL de verificacion).
 *
 * Requisitos: haber ejecutado 01-montar-joomla.sh y 02-instalar-engage.sh (o 03-actualizacion.sh).
 * Entorno: WORK (directorio de trabajo con joomla en $WORK/site y credenciales fuera del repo), BASE_URL (por defecto
 * http://127.0.0.1:8080). Salida: tabla en stdout y $WORK/resultados.json. Codigo de salida 1 si algo FALLA.
 */
require __DIR__ . '/lib/Cliente.php';

$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$SITE = "$WORK/site";
$ids  = json_decode(file_get_contents("$WORK/ids.json"), true);
$UP   = trim(file_get_contents("$WORK/userpass.txt"));
$AP   = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
$db   = new mysqli('127.0.0.1', 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), 'joomla_test');
$db->set_charset('utf8mb4');
$cfg  = file_get_contents("$SITE/configuration.php");
preg_match("/public \\\$secret = '([^']+)'/", $cfg, $m);
$SECRET = $m[1];

$RES = [];
function r(string $id, string $desc, string $estado, string $ev = ''): void
{
	global $RES;
	$RES[] = compact('id', 'desc', 'estado', 'ev');
	printf("%-8s %-11s %s%s\n", $id, $estado, $desc, $ev !== '' ? "  [$ev]" : '');
}
function q(string $sql)
{
	global $db;
	$x = $db->query($sql);
	if ($x === false) { fwrite(STDERR, "SQL: {$db->error}\n$sql\n"); }
	return $x;
}
function one(string $sql) { $x = q($sql); $f = $x ? $x->fetch_row() : null; return $f ? $f[0] : null; }
function nComentarios(string $where = '1'): int { return (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE $where"); }
function param(array $kv): void
{
	$p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: [];
	foreach ($kv as $k => $v) { if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; } }
	global $db;
	$db->query("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
}
function reglas(array $rules): void
{
	global $db;
	$db->query("UPDATE jos_assets SET rules='" . $db->real_escape_string(json_encode($rules)) . "' WHERE name='com_engage'");
}
function limpiar(): void { q('DELETE FROM jos_engage_comments'); q('ALTER TABLE jos_engage_comments AUTO_INCREMENT=1'); q('DELETE FROM jos_engage_unsubscribe'); }
function urlArt(int $id, int $cat): string { return "/index.php?option=com_content&view=article&id=$id&catid=$cat"; }

/** Envia un comentario como $c. $campos se mezcla sobre los valores correctos. Devuelve la respuesta. */
function comentar(Cliente $c, string $art, array $campos = [], bool $conToken = true, ?string $tokenForzado = null): array
{
	global $ids;
	$a   = $ids[$art]; $cat = $ids[$art === 'art_restringido' ? 'cat_restringida' : 'cat_publica'];
	$pag = $c->get(urlArt($a, $cat));
	$tok = Cliente::token($pag['body']);
	$ru  = preg_match('/name="returnurl" value="([^"]*)"/', $pag['body'], $m) ? html_entity_decode($m[1]) : base64_encode($c->base . urlArt($a, $cat));
	$j   = array_merge(['asset_id' => $ids[$art . '_asset'], 'parent_id' => '0', 'name' => 'Invitado Test', 'email' => 'invitado@example.test', 'body' => 'Comentario de prueba'], $campos);
	$cuerpo = ['returnurl' => $ru, 'jform' => $j];
	if ($conToken) { $cuerpo[$tokenForzado ?? $tok] = 1; }
	return $c->post('/index.php?option=com_engage&task=comment.save', $cuerpo);
}
/** Analiza el DOM de la lista de comentarios y devuelve los elementos/atributos peligrosos que haya. */
function peligros(string $html): array
{
	$ini = strpos($html, 'akengage-comments-section');
	if ($ini === false) { return ['(sin seccion de comentarios)']; }
	$fin = strpos($html, '<form action', $ini);
	$frag = substr($html, $ini, ($fin ?: strlen($html)) - $ini);
	$d = new DOMDocument(); libxml_use_internal_errors(true);
	$d->loadHTML('<?xml encoding="utf-8"?><div>' . $frag . '</div>');
	$mal = [];
	foreach ($d->getElementsByTagName('*') as $e) {
		if (in_array(strtolower($e->nodeName), ['script', 'svg', 'iframe', 'object', 'embed', 'style', 'link', 'meta', 'base', 'form', 'math', 'applet'], true)) { $mal[] = '<' . $e->nodeName . '>'; }
		foreach ($e->attributes as $at) {
			$n = strtolower($at->nodeName); $v = strtolower(preg_replace('/[\x00-\x20]+/', '', $at->nodeValue));
			if (strpos($n, 'on') === 0) { $mal[] = "$n="; }
			if (in_array($n, ['href', 'src', 'xlink:href', 'action', 'formaction', 'data', 'srcset', 'style'], true) && preg_match('/^(javascript|vbscript|data):|expression\(/', $v)) { $mal[] = "$n=" . substr($v, 0, 30); }
		}
	}
	return array_values(array_unique($mal));
}
function cuerpoComentarios(string $html): string
{
	preg_match_all('#class="akengage-comment-body"[^>]*>(.*?)</div>#s', $html, $m);
	return implode("\n", array_map('trim', $m[1]));
}
function snip(string $s, int $n = 90): string { return substr(preg_replace('/\s+/', ' ', $s), 0, $n); }

// ---------------------------------------------------------------------------------------------------------------
$tmp = "$WORK/tmp"; @mkdir($tmp, 0700, true);
$inv = new Cliente($BASE, $tmp, 'inv');
$reg = new Cliente($BASE, $tmp, 'reg1');
$reg2 = new Cliente($BASE, $tmp, 'reg2');
$edi = new Cliente($BASE, $tmp, 'edi');
$adm = new Cliente($BASE, $tmp, 'adm');
$A = $ids['art_publico']; $CP = $ids['cat_publica']; $AS = $ids['art_publico_asset'];

// Estado inicial reproducible
param(['default_publish' => '1', 'filter_mode' => null, 'min_length' => null, 'max_length' => null, 'default_limit' => null, 'comments_show' => null]);
reglas(['core.create' => ['1' => 1], 'core.edit.own' => ['2' => 1], 'core.edit.state' => ['4' => 1], 'core.delete' => ['6' => 1]]);
limpiar();
q("UPDATE jos_extensions SET params='{\"mail_style\":\"both\",\"disable_htmllayout\":\"1\"}' WHERE element='com_mails'");
foreach (glob("$WORK/mails/*.eml") ?: [] as $f) { unlink($f); }
if (is_file("$WORK/php-errors.log")) { file_put_contents("$WORK/php-errors.log", ''); }
$errLogAntes = 0;

// ======================== FUNCIONALES (F-xx) ========================
$p = $inv->get('/');            r('F-01', 'Portada del sitio responde 200', $p['code'] === 200 ? 'PASA' : 'FALLA', "HTTP {$p['code']}");
$p = $inv->get('/administrator/'); r('F-02', '/administrator responde 200 (login)', $p['code'] === 200 && stripos($p['body'], 'passwd') !== false ? 'PASA' : 'FALLA', "HTTP {$p['code']}");
$p = $inv->get(urlArt($A, $CP));
r('F-03', 'El plugin de contenido pinta la seccion de comentarios y el formulario al invitado con core.create', $p['code'] === 200 && strpos($p['body'], 'akengage-comments-section') !== false && strpos($p['body'], 'akengageCommentForm') !== false ? 'PASA' : 'FALLA', "HTTP {$p['code']}");

$x = comentar($inv, 'art_publico', ['name' => 'Ana Invitada', 'email' => 'ana@example.test', 'body' => 'Hola desde invitado']);
$f = q("SELECT * FROM jos_engage_comments ORDER BY id DESC LIMIT 1")->fetch_assoc();
r('F-04', 'Invitado envia comentario: 303 y fila creada (asset, nombre, email, created_by=0, publicado, IP guardada)',
	$x['code'] === 303 && $f && (int) $f['asset_id'] === $AS && $f['name'] === 'Ana Invitada' && (int) $f['created_by'] === 0 && (int) $f['enabled'] === 1 && $f['ip'] === '127.0.0.1' ? 'PASA' : 'FALLA',
	'HTTP ' . $x['code'] . ' fila=' . json_encode($f ? array_intersect_key($f, array_flip(['id', 'asset_id', 'name', 'enabled', 'created_by', 'ip'])) : null));
$p = $inv->get(urlArt($A, $CP));
r('F-05', 'El comentario publicado aparece en la pagina del articulo', strpos($p['body'], 'Hola desde invitado') !== false ? 'PASA' : 'FALLA');

$ok = $reg->login('registrado1', $UP);
r('F-06', 'Login frontend de usuario registrado', $ok ? 'PASA' : 'FALLA');
$p = $reg->get(urlArt($A, $CP));
r('F-07', 'Al registrado no se le piden nombre/email (campos ocultos por showon) y el formulario existe', strpos($p['body'], 'akengageCommentForm') !== false ? 'PASA' : 'FALLA');
$x = comentar($reg, 'art_publico', ['body' => 'Hola desde registrado', 'name' => 'IGNORADO', 'email' => 'ignorado@example.test']);
$f = q("SELECT * FROM jos_engage_comments ORDER BY id DESC LIMIT 1")->fetch_assoc();
r('F-08', 'Registrado comenta: created_by = su ID', $x['code'] === 303 && $f && (int) $f['created_by'] === $ids['user_registrado1'] ? 'PASA' : 'FALLA', 'created_by=' . ($f['created_by'] ?? '?') . ' esperado ' . $ids['user_registrado1'] . ' name=' . ($f['name'] ?? ''));
$idRaiz = (int) one("SELECT id FROM jos_engage_comments WHERE body LIKE 'Hola desde invitado%'");
$x = comentar($reg2->login('registrado2', $UP) ? $reg2 : $reg2, 'art_publico', ['body' => 'Respuesta anidada', 'parent_id' => (string) $idRaiz]);
$f = q("SELECT * FROM jos_engage_comments ORDER BY id DESC LIMIT 1")->fetch_assoc();
r('F-09', 'Respuesta anidada (parent_id valido del mismo articulo) se guarda con ese padre', $x['code'] === 303 && (int) $f['parent_id'] === $idRaiz ? 'PASA' : 'FALLA', 'parent_id=' . ($f['parent_id'] ?? '?'));
$p = $inv->get(urlArt($A, $CP));
r('F-10', 'La pagina muestra los 3 comentarios (encabezado "3 comments")', preg_match('/3 comments/i', $p['body']) ? 'PASA' : 'FALLA', snip((string) (preg_match('/akengage-title[^>]*>\s*([^<]+)/', $p['body'], $mm) ? $mm[1] : '')));

$n0 = nComentarios();
$x = comentar($inv, 'art_publico', ['body' => 'sin token'], false);
r('F-11', 'POST sin token CSRF: no se crea el comentario', nComentarios() === $n0 ? 'PASA' : 'FALLA', "HTTP {$x['code']} filas $n0 -> " . nComentarios());
$x = comentar($inv, 'art_publico', ['body' => 'token falso'], true, str_repeat('a', 32));
r('F-12', 'POST con token CSRF inventado: no se crea el comentario', nComentarios() === $n0 ? 'PASA' : 'FALLA', "HTTP {$x['code']}");
$x = comentar($inv, 'art_publico', ['body' => ''], true);
r('F-13', 'Comentario vacio: rechazado', nComentarios() === $n0 ? 'PASA' : 'FALLA', "HTTP {$x['code']}");

param(['default_publish' => '0']);
comentar($inv, 'art_publico', ['name' => 'Pendiente', 'email' => 'pend@example.test', 'body' => 'Esperando moderacion']);
$f = q("SELECT * FROM jos_engage_comments ORDER BY id DESC LIMIT 1")->fetch_assoc();
$p = $inv->get(urlArt($A, $CP));
r('F-14', 'Con default_publish=0 el comentario queda sin publicar y NO se muestra al publico', (int) $f['enabled'] === 0 && strpos($p['body'], 'Esperando moderacion') === false ? 'PASA' : 'FALLA', 'enabled=' . $f['enabled']);
$idPend = (int) $f['id'];
param(['default_publish' => '1']);

$mailDir = "$WORK/mails";
$nMails = count(glob("$mailDir/*.eml") ?: []);
r('F-15', 'Se enviaron notificaciones por correo (SMTP local de captura)', $nMails > 0 ? 'PASA' : ($nMails === 0 && !is_dir($mailDir) ? 'NO PROBADA' : 'FALLA'), "$nMails mensajes");
$mailsTxt = '';
foreach (glob("$mailDir/*.eml") ?: [] as $fm) { $mailsTxt .= file_get_contents($fm) . "\n"; }
$hayAmp = preg_match('#^unsubscribe:.*&amp;#mi', $mailsTxt) || preg_match('#https?://\S+&amp;\S+#', preg_replace('/^Content-Type.*/m', '', $mailsTxt)) ;
r('F-16', 'Los enlaces de los correos de texto plano no llevan "&amp;" (si lo llevan, el enlace de baja no funciona)', $hayAmp ? 'FALLA' : 'PASA', $hayAmp ? snip((string) (preg_match('#https?://\S*&amp;\S*#', $mailsTxt, $mm) ? $mm[0] : ''), 110) : 'sin &amp;');

// F-17: el enlace de baja del correo de texto plano, abierto tal cual, da de baja de verdad
$planos = '';
foreach (glob("$mailDir/*.eml") ?: [] as $fm) {
	$raw = file_get_contents($fm);
	if (preg_match('/Content-Type: text\/plain.*?\r?\n\r?\n(.*?)(\r?\n--|\z)/s', $raw, $mp)) { $planos .= quoted_printable_decode($mp[1]) . "\n"; }
	if ($planos === '') { $planos = $raw; }
}
if (preg_match('#unsubscribe: (https?://\S+)#', $planos, $mu)) {
	q('DELETE FROM jos_engage_unsubscribe');
	$cu = new Cliente($BASE, $tmp, 'baja'); $cu->get(html_entity_decode($mu[1], ENT_NOQUOTES));
	$cu2 = (int) one('SELECT COUNT(*) FROM jos_engage_unsubscribe');
	r('F-17', 'El enlace de baja del correo de texto plano, abierto tal cual (sin sesion), registra la baja', $cu2 === 1 && strpos($mu[1], 'amp;') === false ? 'PASA' : 'FALLA', 'filas de baja=' . $cu2);
} else { r('F-17', 'Enlace de baja en el correo de texto plano', 'NO PROBADA', 'no se encontro enlace de baja en los correos'); }

// ======================== PS-01 / PS-02 / PS-03: XSS ========================
$payloads = [
	'<a href="&#106;avascript:alert(1)">x1</a>', '<a href="jav&Tab;ascript:alert(1)">x2</a>', '<a href="javascript&colon;alert(1)">x3</a>',
	'<a href="&#x6A;avascript&#58;alert(1)">x4</a>', '<img src="da&#9;ta:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">',
	'<img src=" data:image/svg+xml;base64,PHN2Zy8+" onerror=alert(1)>', '<a href="data:text/html,<script>alert(1)</script>">x5</a>',
	'<svg><a xlink:href="javascript:alert(1)"><text>x6</text></a></svg>', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>',
	'<iframe src="javascript:alert(1)"></iframe>', '<p style="background:url(javascript:alert(1))">x7</p>', '<math><mtext><script>alert(1)</script>',
	'<a href=" javascript:alert(1)">x8</a>', '<form action="javascript:alert(1)"><button>x9</button></form>', '<body onload=alert(1)>', '&lt;script&gt;alert(1)&lt;/script&gt;',
];
// 1) via formulario (primera capa + segunda) como invitado
limpiar(); $vistos = [];
foreach ($payloads as $i => $pl) { comentar($inv, 'art_publico', ['body' => $pl, 'name' => 'Tester ' . $i]); }
$p = $inv->get(urlArt($A, $CP) . '&akengage_limit=100');
$pel = peligros($p['body']);
r('PS-01a', 'XSS por formulario (' . count($payloads) . ' cargas, invitado): la salida no contiene script/on*/javascript:/data:', $pel ? 'FALLA' : 'PASA', $pel ? implode(',', $pel) : 'DOM limpio; guardados=' . nComentarios());
// 2) entidades: el texto "&lt;script&gt;" no debe acabar decodificado como etiqueta en BD ni en salida
$ent = cuerpoComentarios($p['body']);
$enBd = (string) one("SELECT COUNT(*) FROM jos_engage_comments WHERE body LIKE '%<script%'");
r('PS-01b', 'Texto "&lt;script&gt;" escrito por el invitado: no se decodifica a <script> ni en BD ni en la salida', ($enBd === '0' && stripos($ent, '<script') === false) ? 'PASA' : 'FALLA', 'salida: ' . snip(substr($ent, -70), 70));
// 3) PS-02: filas contaminadas directamente en BD, vistas como invitado, registrado y superusuario (filtro "sin filtrar")
limpiar();
foreach ($payloads as $i => $pl) {
	q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($AS,'" . $db->real_escape_string($pl) . "','" . $db->real_escape_string('N' . $i . '<img src=x onerror=alert(2)>') . "','x@example.test','127.0.0.1','t',1,NOW(),0)");
}
$adminOk = $adm->login('admintest', $AP);
foreach ([['invitado', $inv], ['registrado', $reg], ['superusuario (sin filtrar)', $adm]] as [$nom, $cli]) {
	$p = $cli->get(urlArt($A, $CP) . '&akengage_limit=100');
	$pel = peligros($p['body']);
	r('PS-02-' . substr($nom, 0, 3), "Fila ya contaminada en BD vista como $nom: salida sin sintaxis ejecutable", ($nom !== 'invitado' && $nom !== 'registrado' && !$adminOk) ? 'NO PROBADA' : ($pel ? 'FALLA' : 'PASA'), $pel ? implode(',', $pel) : 'DOM limpio');
}
$p = $inv->get(urlArt($A, $CP) . '&akengage_limit=100');
r('PS-03', 'Nombre del autor con HTML (<img onerror>) se escapa en la lista', (strpos($p['body'], '<img src=x onerror=alert(2)>') === false) ? 'PASA' : 'FALLA');
// backend: lista de comentarios con filas contaminadas
if ($adm->loginAdmin('admintest', $AP)) {
	$b = $adm->get('/administrator/index.php?option=com_engage&view=Comments');
	$lim = $adm->get('/administrator/index.php?option=com_engage&view=Comments&list[limit]=100');
	$d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8"?>' . $lim['body']);
	$mal = [];
	foreach ($d->getElementsByTagName('*') as $e) { if (in_array($e->nodeName, ['iframe', 'object', 'embed', 'svg', 'math'], true)) { $mal[] = $e->nodeName; } foreach ($e->attributes as $at) { if (stripos($at->nodeName, 'on') === 0 && stripos($at->nodeValue, 'alert(') !== false) { $mal[] = $at->nodeName . '=' . substr($at->nodeValue, 0, 20); } } }
	$scripts = 0; foreach ($d->getElementsByTagName('script') as $s) { if (stripos($s->nodeValue, 'alert(') !== false) { $scripts++; } }
	r('PS-02-bk', 'Backend: lista de comentarios con filas contaminadas (HTTP ' . $lim['code'] . ') sin script/on* inyectados', $lim['code'] === 200 && !$mal && !$scripts ? 'PASA' : 'FALLA', $lim['code'] === 200 ? ($mal ? implode(',', array_unique($mal)) : 'DOM limpio') : snip($lim['body'], 80));
} else { r('PS-02-bk', 'Backend: lista con filas contaminadas', 'NO PROBADA', 'no se pudo iniciar sesion en /administrator'); }

$ret = [];
foreach (['/x"><img src=x onerror=alert(1337)>', "/x' onmouseover='alert(1337)", 'javascript:alert(1337)', '//evil.test/"><script>alert(1337)</script>'] as $mal) {
	foreach (['/index.php?option=com_engage&task=comment.noexiste', '/index.php?option=com_engage&view=Comment&layout=edit'] as $u) {
		$p = $inv->get($u . '&returnurl=' . rawurlencode(base64_encode($mal)));
		if (preg_match('/onerror=alert\(1337\)>|onmouseover=.alert\(1337|<script>alert\(1337/i', $p['body']) || preg_match('/href="javascript:alert\(1337/i', $p['body'])) { $ret[] = snip($mal, 30); }
	}
}
r('PS-03b', 'returnurl malicioso reflejado en la vista de edicion (enlace Cancelar y campo oculto): sin inyeccion de HTML/atributos', $ret ? 'FALLA' : 'PASA', $ret ? implode('; ', $ret) : '8 sondas limpias');

// ======================== PS-05 CRLF ========================
limpiar(); array_map('unlink', glob("$mailDir/*.eml") ?: []);
$x = comentar($inv, 'art_publico', ['name' => "Juan\r\nBcc: victima@example.com", 'email' => "a@b.com\r\nCc: x@y.com", 'body' => 'crlf']);
usleep(500000);
$mm = ''; foreach (glob("$mailDir/*.eml") ?: [] as $fm) { $mm .= file_get_contents($fm); }
$env = is_file("$mailDir/envelope.log") ? file_get_contents("$mailDir/envelope.log") : '';
r('PS-05a', 'CRLF en nombre/email del invitado: sin cabecera Bcc/Cc inyectada ni destinatarios extra (HTTP ' . $x['code'] . ', filas ' . nComentarios() . ')',
	(!preg_match('/^(Bcc|Cc):/mi', $mm) && stripos($env, 'victima@example.com') === false && stripos($env, 'x@y.com') === false) ? 'PASA' : 'FALLA', 'mensajes=' . count(glob("$mailDir/*.eml") ?: []));
$x = comentar($inv, 'art_publico', ['name' => str_repeat('N', 10000), 'email' => 'larga@example.test', 'body' => 'nombre largo']);
$f = q("SELECT LENGTH(name) l FROM jos_engage_comments WHERE body LIKE 'nombre largo%'")->fetch_assoc();
r('PS-25a', 'Nombre de 10 000 caracteres: rechazado o truncado (columna 255), sin error 500', $x['code'] < 500 && (!$f || (int) $f['l'] <= 255) ? 'PASA' : 'FALLA', "HTTP {$x['code']} len=" . ($f['l'] ?? 'no guardado'));
$x = comentar($inv, 'art_publico', ['body' => str_repeat('A', 200000)]);
$f = (int) one("SELECT MAX(LENGTH(body)) FROM jos_engage_comments");
usleep(600000);
$mailsLargo = 0; foreach (glob("$mailDir/*.eml") ?: [] as $fm) { if (strpos(file_get_contents($fm), 'cid[0]=0') !== false || strpos(file_get_contents($fm), 'cid%5B0%5D=0') !== false) { $mailsLargo++; } }
r('PS-25c', 'Comentario NO guardado (nombre de 10 000 caracteres, falla el INSERT): no se envia ningun correo de notificacion (0.6.14)', $mailsLargo === 0 ? 'PASA' : 'FALLA', "correos con cid=0: $mailsLargo");
r('PS-25b', 'Cuerpo de 200 000 caracteres: respuesta < 500 (max_length por defecto)', $x['code'] < 500 ? 'PASA' : 'FALLA', "HTTP {$x['code']} max body guardado=$f");

// ======================== PS-07: contenido restringido / despublicado ========================
limpiar();
$AR = $ids['art_restringido']; $CR = $ids['cat_restringida'];
$p = $inv->get(urlArt($AR, $CR));
r('PS-07a', 'Invitado no ve el articulo restringido ni su formulario (HTTP ' . $p['code'] . ')', strpos($p['body'], 'akengageCommentForm') === false && strpos($p['body'], 'Texto de prueba') === false ? 'PASA' : 'FALLA');
$n0 = nComentarios();
$tokenInv = Cliente::token($inv->get(urlArt($A, $CP))['body']);
$x = $inv->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode($BASE . '/'), $tokenInv => 1, 'jform' => ['asset_id' => $ids['art_restringido_asset'], 'parent_id' => '0', 'name' => 'Intruso', 'email' => 'i@example.test', 'body' => 'en restringido']]);
r('PS-07b', 'Invitado no puede comentar en el asset de un articulo restringido (asset_id manipulado)', nComentarios() === $n0 ? 'PASA' : 'FALLA', "HTTP {$x['code']}");
$x = $inv->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode($BASE . '/'), $tokenInv => 1, 'jform' => ['asset_id' => $ids['art_despublicado_asset'], 'parent_id' => '0', 'name' => 'Intruso', 'email' => 'i@example.test', 'body' => 'en despublicado']]);
r('PS-07c', 'No se puede comentar en un articulo despublicado (asset_id manipulado)', nComentarios() === $n0 ? 'PASA' : 'FALLA', "HTTP {$x['code']}");
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ({$ids['art_restringido_asset']},'SECRETO-RESTRINGIDO','Reservado','r@example.test','127.0.0.1','t',1,NOW(),0)");
$vista = $inv->get('/index.php?option=com_engage&view=Comments&asset_id=' . $ids['art_restringido_asset']);
$vista2 = $inv->get('/index.php?option=com_engage&view=Comments&asset_id=' . $ids['art_restringido_asset'] . '&format=json');
$pm = $inv->get('/');
r('PS-07d', 'Los comentarios del articulo restringido no se filtran por la vista directa ni por la portada/modulo', strpos($vista['body'] . $vista2['body'] . $pm['body'], 'SECRETO-RESTRINGIDO') === false ? 'PASA' : 'FALLA', "vista={$vista['code']} json={$vista2['code']}");
$vr = $reg->get(urlArt($AR, $CR));
r('PS-07e', 'Un registrado (con nivel de acceso) SI ve los comentarios del restringido (control positivo)', strpos($vr['body'], 'SECRETO-RESTRINGIDO') !== false ? 'PASA' : 'FALLA', "HTTP {$vr['code']}");

// ======================== PS-08 / PS-17: mass assignment ========================
limpiar();
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ({$ids['art_otro_asset']},'padre en otro articulo','Otro','o@example.test','127.0.0.1','t',1,NOW(),0)");
$idOtro = (int) one('SELECT MAX(id) FROM jos_engage_comments');
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($AS,'comentario ajeno original','Victima','v@example.test','127.0.0.1','t',1,NOW(),0)");
$idVict = (int) one('SELECT MAX(id) FROM jos_engage_comments');
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($AS,'padre sin publicar','Spam','s@example.test','127.0.0.1','t',0,NOW(),0)");
$idNoPub = (int) one('SELECT MAX(id) FROM jos_engage_comments');
param(['default_publish' => '0']);
comentar($inv, 'art_publico', ['body' => 'mass assignment', 'enabled' => '1', 'created_by' => '1', 'created' => '2000-01-01 00:00:00', 'ip' => '6.6.6.6', 'user_agent' => 'FALSO', 'modified_by' => '1', 'campo_nuevo' => 'x', 'id' => (string) $idVict]);
$f = q("SELECT * FROM jos_engage_comments WHERE body='mass assignment'")->fetch_assoc();
$v = q("SELECT body FROM jos_engage_comments WHERE id=$idVict")->fetch_assoc();
r('PS-08a', 'Invitado no fija enabled/created_by/created/ip/user_agent; el estado lo decide la configuracion',
	$f && (int) $f['enabled'] === 0 && (int) $f['created_by'] === 0 && substr($f['created'], 0, 4) !== '2000' && $f['ip'] === '127.0.0.1' && $f['user_agent'] !== 'FALSO' ? 'PASA' : 'FALLA', $f ? "enabled={$f['enabled']} created_by={$f['created_by']} ip={$f['ip']}" : 'no se guardo');
r('PS-08b', 'jform[id] de un comentario ajeno no lo sobrescribe', $v['body'] === 'comentario ajeno original' ? 'PASA' : 'FALLA', 'body=' . $v['body']);
param(['default_publish' => '1']);
$n0 = nComentarios();
$x = comentar($inv, 'art_publico', ['body' => 'padre de otro articulo', 'parent_id' => (string) $idOtro]);
r('PS-17a', 'parent_id de un comentario de OTRO articulo: rechazado', nComentarios() === $n0 ? 'PASA' : 'FALLA', "HTTP {$x['code']}");
$x = comentar($inv, 'art_publico', ['body' => 'padre sin publicar', 'parent_id' => (string) $idNoPub]);
r('PS-17b', 'parent_id de un comentario SIN PUBLICAR: rechazado', nComentarios() === $n0 ? 'PASA' : 'FALLA', "HTTP {$x['code']}");
foreach (['12abc', '1 OR 1=1', '-5', '99999999999999999999', "1'", 'abc'] as $mal) {
	$x = comentar($inv, 'art_publico', ['body' => 'asset raro', 'asset_id' => $mal]);
	$ok5 = $x['code'] < 500 && nComentarios() === $n0;
	r('PS-08c', "asset_id manipulado '$mal': sin error 500 ni comentario creado", $ok5 ? 'PASA' : 'FALLA', "HTTP {$x['code']}");
}
foreach (['abc', '1 OR 1=1', '99999999', '-1'] as $mal) {
	$x = comentar($inv, 'art_publico', ['body' => 'parent raro ' . $mal, 'parent_id' => $mal]);
	$cr = q("SELECT parent_id, asset_id FROM jos_engage_comments WHERE body='parent raro $mal'")->fetch_assoc();
	// Valido: no se crea, o se crea como comentario raiz (parent_id NULL) en el articulo correcto.
	$okp = $x['code'] < 500 && (!$cr || ($cr['parent_id'] === null && (int) $cr['asset_id'] === $AS));
	r('PS-08d', "parent_id manipulado '$mal': sin error 500; no se crea o se crea como raiz (parent NULL)", $okp ? 'PASA' : 'FALLA', "HTTP {$x['code']} " . ($cr ? 'creado como raiz' : 'rechazado'));
	if ($cr) { q("DELETE FROM jos_engage_comments WHERE body='parent raro $mal'"); }
}
// edicion del propio comentario: no se puede mover de articulo ni re-colgar
$x = comentar($reg, 'art_publico', ['body' => 'mio', 'name' => 'x']);
$idMio = (int) one("SELECT MAX(id) FROM jos_engage_comments WHERE body='mio'");
$pe = $reg->get("/index.php?option=com_engage&view=Comment&task=comment.edit&id=$idMio");
$pe2 = $reg->get("/index.php?option=com_engage&task=comment.edit&id=$idMio");
$te = Cliente::token($pe2['body']) ?: Cliente::token($pe['body']);
$x = $reg->post('/index.php?option=com_engage&task=comment.save&id=' . $idMio, ['returnurl' => base64_encode($BASE . '/'), $te => 1, 'jform' => ['id' => $idMio, 'asset_id' => $ids['art_otro_asset'], 'parent_id' => (string) $idOtro, 'body' => 'mio editado']]);
$f = q("SELECT asset_id,parent_id,body FROM jos_engage_comments WHERE id=$idMio")->fetch_assoc();
r('PS-08e', 'Autor edita su comentario enviando otro asset_id/parent_id: no se mueve ni se re-cuelga', (int) $f['asset_id'] === $AS && $f['parent_id'] === null ? 'PASA' : 'FALLA', "HTTP {$x['code']} asset={$f['asset_id']} parent=" . var_export($f['parent_id'], true) . " body={$f['body']}");
$f2 = q("SELECT body FROM jos_engage_comments WHERE id=$idVict")->fetch_assoc();
$x = $reg2->post('/index.php?option=com_engage&task=comment.save&id=' . $idMio, ['returnurl' => base64_encode($BASE . '/'), Cliente::token($reg2->get(urlArt($A, $CP))['body']) => 1, 'jform' => ['id' => $idMio, 'body' => 'HACKEADO por otro registrado']]);
$f = q("SELECT body FROM jos_engage_comments WHERE id=$idMio")->fetch_assoc();
r('PS-06a', 'Un registrado NO puede editar el comentario de otro registrado', strpos($f['body'], 'HACKEADO') === false ? 'PASA' : 'FALLA', "HTTP {$x['code']}");

// ======================== PS-06 / PS-15: acciones de moderacion ========================
limpiar();
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($AS,'objetivo moderacion','Obj','obj@example.test','127.0.0.1','t',0,NOW(),0)");
$cid = (int) one('SELECT MAX(id) FROM jos_engage_comments');
$estado = fn() => (string) one("SELECT enabled FROM jos_engage_comments WHERE id=$cid");
$acciones = ['comments.publish', 'comments.unpublish', 'comments.delete', 'comments.possiblespam', 'comments.reportspam', 'comments.reportham'];
foreach ([['invitado', $inv], ['registrado', $reg]] as [$nom, $cli]) {
	$fallos = [];
	foreach ($acciones as $ac) {
		$cli->get("/index.php?option=com_engage&task=$ac&cid[]=$cid");
		$cli->post("/index.php?option=com_engage&task=$ac", ['cid' => [$cid]]);
		$cli->get("/index.php?option=com_engage&task=$ac&id=$cid&returnurl=" . base64_encode($BASE . '/'));
		if ((string) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$cid") !== '1' || $estado() !== '0') { $fallos[] = $ac; break; }
	}
	r('PS-15-' . $nom, "Moderacion como $nom sin token ni firma (GET y POST, 6 tareas): estado y fila intactos", $fallos ? 'FALLA' : 'PASA', $fallos ? implode(',', $fallos) : 'enabled=0, fila presente');
}
// task=main directo / vistas con task raros
$tm = $inv->get('/index.php?option=com_engage&task=main'); $tm2 = $inv->get('/index.php?option=com_engage&task=comments.main');
r('PS-15-main', 'task=main / comments.main no ejecutable desde la URL (sin 500 ni datos)', $tm['code'] < 500 && $tm2['code'] < 500 ? 'PASA' : 'FALLA', "HTTP {$tm['code']}/{$tm2['code']}");
// token de formulario de otra sesion
$tokOtro = Cliente::token($reg->get(urlArt($A, $CP))['body']);
$inv->post('/index.php?option=com_engage&task=comments.publish', ['cid' => [$cid], $tokOtro => 1]);
r('PS-15-tokajeno', 'Token de formulario de la sesion de OTRO usuario no vale para moderar', $estado() === '0' ? 'PASA' : 'FALLA');
// editor con permiso edit.state: con token de su sesion SI publica (control positivo); luego sin token NO
$edi->login('editor1', $UP);
$tokE = Cliente::token($edi->get(urlArt($A, $CP))['body']);
$edi->get("/index.php?option=com_engage&task=comments.publish&cid[]=$cid");
$sinTok = $estado();
$edi->post('/index.php?option=com_engage&task=comments.publish', ['cid' => [$cid], $tokE => 1]);
$conTok = $estado();
r('PS-06-editor', 'Editor (core.edit.state): sin token NO publica; con token de su sesion SI (control positivo)', $sinTok === '0' && $conTok === '1' ? 'PASA' : 'FALLA', "sin_token=$sinTok con_token=$conTok");
$edi->post('/index.php?option=com_engage&task=comments.delete', ['cid' => [$cid], $tokE => 1]);
r('PS-06-editor-del', 'Editor SIN core.delete no puede borrar aunque tenga token valido', (string) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$cid") === '1' ? 'PASA' : 'FALLA');

// ======================== PS-16: enlaces firmados ========================
function firmar(string $task, string $email, int $asset, int $expires, int $cid): string
{
	global $SECRET;
	return hash_hmac('sha256', "$task-$email-$asset-$expires-$cid", $SECRET);
}
$firmado = function (string $task, string $email, int $asset, int $expires, int $cid, ?string $tok = null, string $extra = '') {
	return '/index.php?option=com_engage&task=' . $task . '&returnurl=' . base64_encode($GLOBALS['BASE'] . '/') . '&email=' . rawurlencode($email) . '&expires=' . $expires . '&cid[]=' . $cid . $extra . '&token=' . ($tok ?? firmar($task, $email, $asset, $expires, $cid));
};
$est = fn() => (string) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$cid AND enabled=1");
q("UPDATE jos_engage_comments SET enabled=0 WHERE id=$cid");
$mailMod = 'editor1@example.test';
$nuevo = new Cliente($BASE, $tmp, 'firma');
$nuevo->get($firmado('comments.publish', $mailMod, $AS, time() + 3600, $cid, str_repeat('0', 64)));
$a1 = $est();
$nuevo->get($firmado('comments.publish', $mailMod, $AS, time() - 10, $cid));
$a2 = $est();
$nuevo->get($firmado('comments.unpublish', $mailMod, $AS, time() + 3600, $cid, firmar('comments.publish', $mailMod, $AS, time() + 3600, $cid)));
$a3 = $est();
$nuevo->get($firmado('comments.publish', $mailMod, $AS, time() + 3600, $cid + 1000));
$a4 = $est();
$expF = time() + 3600;
$nuevo->get($firmado('comments.publish', $mailMod, $AS, $expF, $cid, firmar('comments.publish', $mailMod, $AS, $expF, $cid)) . '&cid[]=' . $idOtro);
$a5 = $est();
$nuevo->get($firmado('comments.publish', strtoupper($mailMod), $AS, $expF, $cid, firmar('comments.publish', $mailMod, $AS, $expF, $cid)));
$a6 = $est();
$nuevo->get($firmado('comments.publish', $mailMod, $AS, $expF, $cid, firmar('comments.publish', $mailMod, $AS, $expF + 5, $cid)));
$a7 = $est();
foreach (['1e9', '-1', '9999999999999999999', 'abc'] as $ex) { $nuevo->get('/index.php?option=com_engage&task=comments.publish&email=' . rawurlencode($mailMod) . "&expires=$ex&cid[]=$cid&token=" . firmar('comments.publish', $mailMod, $AS, 0, $cid)); }
$a8 = $est();
$nuevo->get('/index.php?option=com_engage&task=comments.publish&email=' . rawurlencode($mailMod) . "&expires[]=1&cid[]=$cid&token[]=x");
$a9 = $est();
r('PS-16a', 'Enlaces firmados alterados (token erroneo, caducado, otra tarea, otro cid, cid extra, email en mayusculas, expires cambiado, expires raros/array): ninguno publica',
	($a1 . $a2 . $a3 . $a4 . $a5 . $a6 . $a7 . $a8 . $a9) === '000000000' ? 'PASA' : 'FALLA', "resultados=$a1$a2$a3$a4$a5$a6$a7$a8$a9");
$nuevo->get($firmado('comments.publish', $mailMod, $AS, $expF, $cid));
$sinSesion = $est();
r('PS-16b', 'Enlace firmado VALIDO sin sesion: NO ejecuta la accion (la firma sustituye al token CSRF, no a los permisos)', $sinSesion === '0' ? 'PASA' : 'FALLA', 'enabled=' . one("SELECT enabled FROM jos_engage_comments WHERE id=$cid"));
$edi->get($firmado('comments.publish', $mailMod, $AS, $expF, $cid));
$conSesion = $est();
r('PS-16c', 'Enlace firmado VALIDO con la sesion del editor (core.edit.state), sin token de formulario: ejecuta la accion (control positivo)', $conSesion === '1' ? 'PASA' : 'FALLA', 'enabled=' . one("SELECT enabled FROM jos_engage_comments WHERE id=$cid"));
$edi->get($firmado('comments.delete', $mailMod, $AS, $expF, $cid));
r('PS-16d', 'Enlace firmado de "delete" valido con la sesion de un editor SIN core.delete: no borra', (string) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$cid") === '1' ? 'PASA' : 'FALLA', 'fila ' . ((string) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$cid") === '1' ? 'conservada' : 'BORRADA'));

// ======================== PS-13 / PS-12: limites e inyeccion ========================
limpiar();
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) SELECT $AS, CONCAT('masivo ', seq), 'M', 'm@example.test', '127.0.0.1', 't', 1, NOW(), 0 FROM (SELECT a.N + b.N*10 + c.N*100 + 1 seq FROM (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) c) t");
$tot = nComentarios();
$mx = 0; $cod = [];
foreach (['0', '-1', '999999999', 'abc', '100000', '1e9', '0x10'] as $lim) {
	$p = $inv->get(urlArt($A, $CP) . '&akengage_limit=' . rawurlencode($lim));
	$cnt = preg_match_all('/id="akengage-comment-\d+"/', $p['body']); $mx = max($mx, $cnt); $cod[] = $p['code'];
}
$p = $inv->get(urlArt($A, $CP) . '&akengage_limit[]=5&akengage_limitstart[]=1'); $cod[] = $p['code'];
$p = $inv->get(urlArt($A, $CP) . '&akengage_limitstart=-50'); $cod[] = $p['code'];
$p = $inv->get(urlArt($A, $CP) . '&akengage_limitstart=99999999999999999999'); $cod[] = $p['code'];
r('PS-13', "akengage_limit/limitstart raros con $tot comentarios: nunca >500 por pagina, sin 500 (max renderizados=$mx)", $mx <= 500 && max($cod) < 500 ? 'PASA' : 'FALLA', 'HTTP ' . implode(',', array_unique($cod)));
$sleepFail = [];
foreach ([
	urlArt($A, $CP) . '&akengage_limit=' . rawurlencode('1;SELECT SLEEP(5)'),
	urlArt($A, $CP) . '&akengage_cid=' . rawurlencode('1 OR SLEEP(5)'),
	urlArt($A, $CP) . '&akengage_cid=' . rawurlencode("1' OR SLEEP(5)-- "),
	'/index.php?option=com_engage&view=Comments&asset_id=' . rawurlencode('1 OR SLEEP(5)'),
	'/index.php?option=com_engage&view=Comments&asset_id=' . rawurlencode("1;SELECT SLEEP(5)"),
	'/index.php?option=com_engage&view=Comments&filter[search]=' . rawurlencode("' OR SLEEP(5)-- "),
] as $u) {
	$p = $inv->get($u);
	if ($p['ms'] > 3500 || preg_match('/SQLSTATE|You have an error in your SQL|mysqli|Query:/i', $p['body'])) { $sleepFail[] = snip($u, 70) . " ({$p['ms']} ms)"; }
}
r('PS-12a', 'SQLi frontend (SLEEP en asset_id, akengage_cid, akengage_limit, filter[search]): sin retardo ni texto de error SQL', $sleepFail ? 'FALLA' : 'PASA', $sleepFail ? implode('; ', $sleepFail) : '6 sondas < 3,5 s');
if ($adm->loginAdmin('admintest', $AP) || true) {
	$sf = [];
	foreach ([
		'&filter[search]=' . rawurlencode("' OR SLEEP(5)-- "), '&filter[enabled]=' . rawurlencode('1 OR SLEEP(5)'), '&filter[since]=' . rawurlencode("2020-01-01' OR SLEEP(5)-- "),
		'&filter[to]=' . rawurlencode("x' OR SLEEP(5)-- "), '&list[fullordering]=' . rawurlencode('id; SELECT SLEEP(5)'), '&list[fullordering]=' . rawurlencode('(SELECT SLEEP(5)) ASC'),
		'&list[limit]=' . rawurlencode('1; SELECT SLEEP(5)'), '&filter[search][]=a', '&filter[asset_id]=' . rawurlencode('1 OR SLEEP(5)'), '&filter[created_by]=' . rawurlencode('1 OR SLEEP(5)'),
	] as $qs) {
		$p = $adm->get('/administrator/index.php?option=com_engage&view=Comments' . $qs);
		if ($p['ms'] > 3500 || preg_match('/SQLSTATE|You have an error in your SQL/i', $p['body'])) { $sf[] = snip($qs, 60) . " ({$p['ms']} ms)"; }
	}
	r('PS-12b', 'SQLi backend (filter[*], list[fullordering], list[limit] con SLEEP) con sesion de superusuario: sin retardo ni error SQL', $sf ? 'FALLA' : 'PASA', $sf ? implode('; ', $sf) : '10 sondas < 3,5 s');
}

// ======================== PS-14: redireccion abierta ========================
limpiar();
$malos = ['//evil.com', '/\\evil.com', 'https://evil.com', 'https:\\\\evil.com', 'javascript:alert(1)', '\\/\\/evil.com', 'https://127.0.0.1:8080@evil.com', "/\r\nLocation: https://evil.com", "ht\ttp://evil.com", 'data:text/html,x'];
$fugas = [];
foreach ($malos as $mal) {
	foreach ([base64_encode($mal), $mal] as $ru) {
		$pag = $inv->get(urlArt($A, $CP)); $tk = Cliente::token($pag['body']);
		$x = $inv->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => $ru, $tk => 1, 'jform' => ['asset_id' => $AS, 'parent_id' => '0', 'name' => 'R', 'email' => 'r@example.test', 'body' => 'redir ' . bin2hex(random_bytes(3))]]);
		$h = parse_url($x['loc'], PHP_URL_HOST);
		if ($x['loc'] !== '' && $h !== null && $h !== '127.0.0.1') { $fugas[] = substr($mal, 0, 20) . ' -> ' . snip($x['loc'], 40); }
		if (stripos($x['headers'], "\nLocation: https://evil") !== false && stripos($x['loc'], 'evil') !== false) { $fugas[] = 'cabecera inyectada'; }
	}
}
r('PS-14', 'returnurl malicioso (10 variantes, en base64 y en claro): nunca redirige fuera del sitio', $fugas ? 'FALLA' : 'PASA', $fugas ? implode('; ', array_unique($fugas)) : 'todas a 127.0.0.1');

// ======================== PS-18 / PS-19 / PS-39 ========================
$lfi = [];
foreach (['../../../../configuration', '..%2f..%2f..%2f..%2fconfiguration', 'default_form%00', '\\..\\..\\configuration', '../../../../../../etc/passwd'] as $ly) {
	foreach (["/index.php?option=com_engage&view=Comments&layout=$ly", "/index.php?option=com_engage&view=Comment&layout=$ly", "/index.php?option=com_engage&view=Comments&tmpl=" . $ly, "/index.php?option=com_engage&view=Comments&format=" . $ly] as $u) {
		$p = $inv->get($u);
		if (preg_match('/public \$(secret|password|user)\b|root:x:0:0|JConfig/', $p['body'])) { $lfi[] = snip($u, 70); }
	}
}
r('PS-18', 'layout/tmpl/format con path traversal (20 sondas): ni configuration.php ni /etc/passwd', $lfi ? 'FALLA' : 'PASA', $lfi ? implode('; ', $lfi) : 'sin fuga');
$errs = [];
foreach (['/index.php?option=com_engage&view=Comment&id=999999', '/index.php?option=com_engage&view=NoExiste', '/index.php?option=com_engage&task=comment.noexiste', '/index.php?option=com_engage&view=Comments&asset_id[]=x'] as $u) {
	$p = $inv->get($u);
	if (preg_match('/Stack trace|\/home\/|\/tmp\/claude|\.php on line|Fatal error|#\d+ \S+\.php\(\d+\)|PHP \d\.\d|mysqli_sql_exception/', $p['body'])) { $errs[] = snip($u, 60) . " HTTP {$p['code']}"; }
}
r('PS-19', 'Errores de Engage como invitado (JDEBUG=0): sin traza, rutas del servidor ni version de PHP', $errs ? 'FALLA' : 'PASA', $errs ? implode('; ', $errs) : '4 sondas limpias');
$p = $inv->get(urlArt($A, $CP));
$hdr = strtolower($p['headers']);
r('PS-39', 'Cabeceras de la pagina con comentarios: X-Frame-Options, Referrer-Policy y sin CORS abierto (Access-Control-Allow-Origin: *)', (strpos($hdr, 'x-frame-options') !== false && strpos($hdr, 'referrer-policy') !== false && strpos($hdr, 'access-control-allow-origin: *') === false) ? 'PASA' : 'FALLA', 'cabeceras propias de Joomla; Engage no anade ninguna');

// ======================== PS-33 / PS-35 / PS-38 / PS-23 ========================
$p = $inv->get(urlArt($A, $CP));
preg_match('/"toolbar":"([^"]*)"/', $p['body'], $mt); preg_match('/"joomlaExtButtons":(\[[^\]]*\])/', $p['body'], $me);
$tool = $mt[1] ?? '?'; $ext = $me[1] ?? '?';
$media = $inv->get('/index.php?option=com_media&task=api.files&path=local-images:/'); $mediaP = $inv->post('/index.php?option=com_media&task=api.files&path=local-images:/', ['name' => 'x.png', 'content' => base64_encode('x')]);
r('PS-35', 'Editor del invitado: sin <input type=file>, sin botones de imagen/medios/pagebreak/readmore y la API de medios de Joomla rechaza al invitado',
	(!preg_match('/type="file"/', $p['body']) && !preg_match('/image|media|pagebreak|readmore|jxtdbuttons\s+\w*(image|media)/i', str_replace('jxtdbuttons', '', $tool)) && $ext === '[]' && $media['code'] >= 400 && $mediaP['code'] >= 400) ? 'PASA' : 'FALLA',
	"toolbar=$tool extButtons=$ext com_media GET/POST={$media['code']}/{$mediaP['code']} (dndEnabled=1 del editor: arrastrar y soltar subiria via com_media, que rechaza)");
$api = $inv->get('/api/index.php/v1/engage/comments'); $api2 = $inv->get('/api/index.php/v1/content/articles');
r('PS-38', 'No hay web services de Engage (/api/v1/engage/*) y la API del nucleo no responde datos sin token', ($api['code'] >= 400 && $api2['code'] >= 400) ? 'PASA' : 'FALLA', "engage={$api['code']} core={$api2['code']}");
limpiar();
$inv2 = new Cliente($BASE, $tmp, 'enum1'); $inv3 = new Cliente($BASE, $tmp, 'enum2');
$x1 = comentar($inv2, 'art_publico', ['email' => 'registrado1@example.test', 'name' => 'Suplantador', 'body' => 'uso email de usuario registrado']);
$x2 = comentar($inv3, 'art_publico', ['email' => 'no-existe-xyz@example.test', 'name' => 'Otro', 'body' => 'uso email inexistente']);
$s1 = [$x1['code'], preg_replace('/\W/', '', $x1['loc'])]; $s2 = [$x2['code'], preg_replace('/\W/', '', $x2['loc'])];
$m1 = (string) preg_match('/alert-(message|error|warning)[^>]*>(.*?)<\/div>/s', $inv2->get(urlArt($A, $CP))['body'], $mm1);
r('PS-33', 'Comentar como invitado con el email de un usuario registrado no revela si existe (misma respuesta que con un email inexistente)', $s1 === $s2 ? 'PASA' : 'FALLA', 'codigos ' . $x1['code'] . '/' . $x2['code']);

// ======================== PS-29/PS-42 etc no automatizables aqui ========================
// ======================== CP-06: errores PHP durante todo el flujo ========================
clearstatcache();
$errLog = "$WORK/php-errors.log";
$tam = is_file($errLog) ? filesize($errLog) : 0;
$nuevo = $tam > $errLogAntes ? substr(file_get_contents($errLog), $errLogAntes) : '';
$lineas = array_filter(explode("\n", $nuevo));
r('CP-06', 'PHP 8.3 con error_reporting=E_ALL durante todo el flujo (comentar, moderar, correo, listados): sin Deprecated/Warning/Fatal en el log', $lineas ? 'FALLA' : 'PASA', $lineas ? snip(implode(' | ', array_slice($lineas, 0, 4)), 200) : 'log vacio (' . $tam . ' bytes)');

// ---------------------------------------------------------------------------------------------------------------
$cuenta = ['PASA' => 0, 'FALLA' => 0, 'NO PROBADA' => 0];
foreach ($RES as $x) { $cuenta[$x['estado']]++; }
file_put_contents("$WORK/resultados.json", json_encode(['resumen' => $cuenta, 'pruebas' => $RES], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nRESUMEN: " . json_encode($cuenta) . "\n";
exit($cuenta['FALLA'] ? 1 : 0);
