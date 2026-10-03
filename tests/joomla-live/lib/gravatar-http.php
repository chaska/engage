<?php
/**
 * 0.6.17: Gravatar con consentimiento previo, contra un Joomla REAL por HTTP (curl). Cambia los parametros del plugin
 * engage/gravatar en la BD y comprueba el HTML de la pagina del articulo en cada modo (invitado y usuario registrado).
 * Requisitos: 03-sembrar-y-probar.sh ya ejecutado (usa $WORK/ids.json). Escribe $WORK/resultados-gravatar-http.json.
 * Entorno: WORK, BASE_URL, SITE_DIR, IDS_FILE, DB_NAME, DB_USER. Sale con 1 si algo FALLA.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$SITE = "$WORK/" . (getenv('SITE_DIR') ?: 'site');
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$UP   = trim(file_get_contents("$WORK/userpass.txt"));
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$tmp = "$WORK/tmp"; @mkdir($tmp, 0700, true);
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-10s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [$ev]" : '');
}
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { fwrite(STDERR, "SQL: {$db->error}\n"); } return $x; }
function pluginParams(?array $p): void { global $db; $j = $db->real_escape_string($p === null ? '{}' : json_encode($p)); q("UPDATE jos_extensions SET params='$j' WHERE type='plugin' AND folder='engage' AND element='gravatar'"); }
function pluginEnabled(int $e): void { q("UPDATE jos_extensions SET enabled=$e WHERE type='plugin' AND folder='engage' AND element='gravatar'"); }
function urlArt(int $id, int $cat): string { return "/index.php?option=com_content&view=article&id=$id&catid=$cat"; }
/** Valores de atributos que el navegador carga solo y contienen gravatar.com (src, srcset, href, poster, style, data-src...) y etiquetas link/picture/source. */
function cargables(string $html): array
{
	$d = new DOMDocument(); libxml_use_internal_errors(true);
	$d->loadHTML('<?xml encoding="utf-8"?>' . $html);
	$mal = [];
	foreach ($d->getElementsByTagName('*') as $e) {
		foreach ($e->attributes as $at) {
			if ($at->nodeName === 'data-engage-gravatar') { continue; }
			if (stripos($at->nodeValue, 'gravatar.com') !== false) { $mal[] = $e->nodeName . '@' . $at->nodeName; }
		}
		if (in_array($e->nodeName, ['link', 'picture', 'source'], true) && stripos($e->getAttribute('href') . $e->getAttribute('srcset'), 'gravatar') !== false) { $mal[] = '<' . $e->nodeName . '>'; }
	}
	return $mal;
}
/** URLs (http/https/protocolo relativo) a gravatar.com fuera del atributo data-engage-gravatar, tambien dentro de JSON con barras escapadas. El texto "...gravatar.com)" de un boton no es una URL. */
function fueraDeData(string $html): int { return preg_match_all('#(https?:)?//[^\s"\'<>]*gravatar\.com#i', str_replace('\\/', '/', preg_replace('#data-engage-gravatar="[^"]*"#', '', $html))); }

// ---- preparacion: comentarios publicados de un invitado y de un registrado ----
$A = $ids['art_publico']; $CP = $ids['cat_publica']; $AS = $ids['art_publico_asset'];
$ext = "UPDATE jos_extensions SET params=JSON_SET(COALESCE(NULLIF(params,''),'{}'),'$.default_publish','1') WHERE element='com_engage' AND type='component'";
q($ext);
q("UPDATE jos_assets SET rules='" . $db->real_escape_string(json_encode(['core.create' => ['1' => 1], 'core.edit.own' => ['2' => 1], 'core.edit.state' => ['4' => 1], 'core.delete' => ['6' => 1]])) . "' WHERE name='com_engage'");
q('DELETE FROM jos_engage_comments'); q('ALTER TABLE jos_engage_comments AUTO_INCREMENT=1');
function comentar(Cliente $c, string $email, string $nombre): int
{
	global $A, $CP, $AS;
	$pag = $c->get(urlArt($A, $CP));
	$tok = Cliente::token($pag['body']);
	$ru  = preg_match('/name="returnurl" value="([^"]*)"/', $pag['body'], $m) ? html_entity_decode($m[1]) : base64_encode($c->base . urlArt($A, $CP));
	return $c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => $ru, 'jform' => ['asset_id' => $AS, 'parent_id' => '0', 'name' => $nombre, 'email' => $email, 'body' => "Comentario de $nombre"], $tok => 1])['code'];
}
$inv = new Cliente($BASE, $tmp, 'invana');
$c1 = comentar($inv, 'ana.invitada@example.test', 'Ana Invitada');
$cli = 'cd ' . escapeshellarg($SITE) . ' && php cli/joomla.php';
shell_exec("$cli user:delete --username=lusreg -n 2>&1");
shell_exec("$cli user:add --username=lusreg --name=Registrado --email=luis.registrado@example.test --password=" . escapeshellarg($UP) . " --usergroup=Registered 2>&1");
$reg = new Cliente($BASE, $tmp, 'lusreg');
$okReg = $reg->login('lusreg', $UP);
$c2 = $okReg ? comentar($reg, 'ignorado@example.test', 'Registrado') : 0;
$n = (int) q('SELECT COUNT(*) FROM jos_engage_comments')->fetch_row()[0];
r('G-00', 'Preparacion: un comentario de invitado y otro de usuario registrado', $n === 2, "HTTP $c1/$c2, comentarios=$n");

$hashInv = hash('sha256', 'ana.invitada@example.test');
$hashReg = hash('sha256', 'luis.registrado@example.test');
$antiguos = ['profile_link' => '1', 'rating' => 'G', 'default_image' => 'identicon', 'custom_default' => '', 'force_default' => '0'];

$ver = function (string $nombre, ?array $params) use ($BASE, $tmp, $A, $CP, $hashInv, $hashReg, $antiguos, $reg) {
	pluginEnabled(1);
	pluginParams($params);
	$g = new Cliente($BASE, $tmp, 'g' . md5($nombre));
	return ['inv' => $g->get(urlArt($A, $CP))['body'], 'reg' => $reg->get(urlArt($A, $CP))['body']];
};

// ---- ask y mode ausente (instalacion existente con ajustes antiguos) ----
foreach (['G-01 mode ausente (ajustes antiguos)' => $antiguos, 'G-02 mode=ask' => ['mode' => 'ask'] + $antiguos, 'G-03 sin ningun parametro guardado' => null, 'G-04 mode desconocido' => ['mode' => 'quizas'] + $antiguos] as $k => $params) {
	[$id, $nombre] = explode(' ', $k, 2);
	$p = $ver($nombre, $params);
	foreach (['inv' => $hashInv, 'reg' => $hashReg] as $quien => $hash) {
		$h = $p[$quien];
		$data = preg_match_all('#<img[^>]*data-engage-gravatar="(https://www\.gravatar\.com/avatar/' . $hash . '\?[^"]*)"#', $h);
		r("$id-$quien", "$nombre ($quien): ningun src/srcset/href/link/picture con gravatar.com y la URL solo en data-engage-gravatar", cargables($h) === [] && fueraDeData($h) === 0 && $data === 1, 'cargables=' . json_encode(cargables($h)) . ", fuera de data=" . fueraDeData($h) . ", data=$data");
		r("$id-$quien-b", "$nombre ($quien): el avatar servido es el SVG local, sin perfil de Gravatar y el JS de consentimiento esta registrado", (bool) preg_match('#<img src="[^"]*/media/com_engage/images/avatar-generico\.svg"#', $h) && strpos($h, 'gravatar.com/' . $hash . '"') === false && strpos($h, 'com_engage/js/gravatar') !== false && strpos($h, 'COM_ENGAGE_GRAVATAR_BTN_ACCEPT') !== false);
	}
}
// show_notice
$p = $ver('sin aviso', ['mode' => 'ask', 'show_notice' => '0'] + $antiguos);
r('G-05', 'show_notice=0: la imagen lleva data-engage-gravatar-notice="0" (aviso propio oculto, API activa)', strpos($p['inv'], 'data-engage-gravatar-notice="0"') !== false && cargables($p['inv']) === []);

// ---- off ----
$p = $ver('off', ['mode' => 'off'] + $antiguos);
foreach (['inv', 'reg'] as $quien) {
	r("G-06-$quien", "off ($quien): el HTML de la pagina no contiene la palabra gravatar ni el JS de consentimiento", stripos($p[$quien], 'gravatar') === false && strpos($p[$quien], 'avatar-generico.svg') !== false, 'menciones=' . preg_match_all('/gravatar/i', $p[$quien]));
}
// ---- always ----
$p = $ver('always', ['mode' => 'always'] + $antiguos);
foreach (['inv' => $hashInv, 'reg' => $hashReg] as $quien => $hash) {
	$h = $p[$quien];
	r("G-07-$quien", "always ($quien): src directo https://www.gravatar.com/avatar/<sha256> (comportamiento anterior), con enlace de perfil, sin data-engage-gravatar ni JS de consentimiento", (bool) preg_match('#<img src="https://www\.gravatar\.com/avatar/' . $hash . '\?s=48&amp;r=g&amp;d=identicon"#', $h) && strpos($h, '<a href="https://www.gravatar.com/' . $hash . '"') !== false && strpos($h, 'data-engage-gravatar') === false && strpos($h, 'com_engage/js/gravatar') === false);
}
// ---- plugin desactivado ----
pluginEnabled(0);
$g = new Cliente($BASE, $tmp, 'gdis');
$h = $g->get(urlArt($A, $CP))['body'];
r('G-08', 'Plugin Gravatar desactivado: no hay avatar, ni mencion de gravatar, ni JS extra (comportamiento sin el plugin intacto)', stripos($h, 'gravatar') === false && strpos($h, 'akengage-commenter-avatar') === false && strpos($h, 'akengage-comment-item') !== false);
pluginEnabled(1);
pluginParams(['mode' => 'ask'] + $antiguos);
// ---- el JS se sirve y es el esperado ----
$js = (new Cliente($BASE, $tmp, 'gjs'))->get('/media/com_engage/js/gravatar.js');
r('G-09', 'media/com_engage/js/gravatar.js se sirve (200) y es el de la fuente', $js['code'] === 200 && $js['body'] === file_get_contents(dirname(__DIR__, 3) . '/src/component/media/js/gravatar.js'), 'HTTP ' . $js['code']);
foreach (['avatar-generico.svg', 'avatar-blanco.svg'] as $f) {
	$s = (new Cliente($BASE, $tmp, 'gsvg'))->get("/media/com_engage/images/$f");
	r("G-10-$f", "media/com_engage/images/$f se sirve (200) como imagen", $s['code'] === 200 && stripos($s['headers'], 'image/svg+xml') !== false, 'HTTP ' . $s['code']);
}
// ---- el modulo engage_latest no usa avatares (comprobacion estatica) ----
$mod = shell_exec('grep -rli "avatar" ' . escapeshellarg(dirname(__DIR__, 3) . '/src/modules') . ' 2>/dev/null');
r('G-11', 'El modulo engage_latest no pinta avatares (no hay otra via de carga de Gravatar)', trim((string) $mod) === '');
$c = ['PASA' => 0, 'FALLA' => 0]; foreach ($RES as $x) { $c[$x['estado']]++; }
file_put_contents("$WORK/resultados-gravatar-http.json", json_encode(['resumen' => $c, 'pruebas' => $RES], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nRESUMEN: " . json_encode($c) . "\n";
exit($c['FALLA'] ? 1 : 0);
