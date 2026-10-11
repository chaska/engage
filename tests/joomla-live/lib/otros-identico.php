<?php
/**
 * 0.8.2: el HTML del ARTICULO es identico antes (0.8.1) y despues (0.8.2). Dos fases con el mismo hilo (fechas fijas, respuestas, reacciones, sin publicar, spam):
 *   FASE=antes    con la 0.8.1 instalada: guarda en $WORK/otros-html/antes/ la PAGINA ENTERA del articulo (y, aparte, la seccion de comentarios) de cada
 *                 combinacion tema (classic, modern, minimal, dark) x reacciones (si/no) x orden (asc/desc) x quien mira (invitado, Registered, Manager)
 *   FASE=despues  con la 0.8.2 instalada: repite y compara BYTE A BYTE (tras normalizar los tokens CSRF y el sufijo ?hash de los recursos).
 * Sale con 1 si algo difiere o falta la referencia.
 */
require __DIR__ . '/Cliente.php';
$FASE = getenv('FASE') ?: 'despues';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
$ASSET = (int) $ids['art_publico_asset'];
$PAGES = ['publico' => "/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}", 'restringido' => "/index.php?option=com_content&view=article&id={$ids['art_restringido']}&catid={$ids['cat_restringida']}"];
$dir   = "$WORK/otros-html"; @mkdir("$dir/antes", 0777, true); @mkdir("$dir/despues", 0777, true);
$PASS  = trim(file_get_contents("$WORK/otrospass.txt"));
$prev  = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
register_shutdown_function(function () use ($db, $prev, $ASSET) {
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string($prev) . "' WHERE element='com_engage' AND type='component'");
	q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
});
$uReg = (int) one("SELECT id FROM jos_users WHERE username='otrosreg'"); $uMgr = (int) one("SELECT id FROM jos_users WHERE username='otrosmgr'");
q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET OR asset_id=" . (int) $ids['art_restringido_asset']);
$n = 0;
$cm = function (int $asset, string $body, int $by, string $name, int $enabled, $parent, string $when) use ($db, &$n) {
	$p = $parent === null ? 'NULL' : (int) $parent; $nm = $by ? 'NULL' : "'$name'"; $em = $by ? 'NULL' : "'" . strtolower($name) . "@example.invalid'";
	q("INSERT INTO jos_engage_comments (id,asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (" . (9500 + ++$n) . ",$asset,$p,'" . $db->real_escape_string("<p>$body</p>") . "',$nm,$em,'203.0.113.7','t',$enabled,'$when',$by)");
	return 9500 + $n;
};
$c1 = $cm($ASSET, 'Raiz de registrado con <a href="https://ejemplo.example/x">enlace</a>', $uReg, '', 1, null, '2026-09-01 10:00:00');
$c2 = $cm($ASSET, 'Raiz de un invitado', 0, 'Invitado', 1, null, '2026-09-02 10:00:00');
$c3 = $cm($ASSET, 'Raiz del manager', $uMgr, '', 1, null, '2026-09-03 10:00:00');
$c5 = $cm($ASSET, 'Respuesta de un invitado', 0, 'Invitado', 1, $c1, '2026-09-05 10:00:00');
$c7 = $cm($ASSET, 'Nieta del manager', $uMgr, '', 1, $c5, '2026-09-07 10:00:00');
$c9 = $cm($ASSET, 'Sin publicar', 0, 'Pendiente', 0, null, '2026-09-09 10:00:00');
$c10 = $cm($ASSET, 'Spam', 0, 'Spammer', -3, null, '2026-09-10 10:00:00');
q("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($c1,$uMgr,1,NOW()),($c3,$uReg,3,NOW())");
$cm((int) $ids['art_restringido_asset'], 'Hilo de un articulo restringido', 0, 'Invitado', 1, null, '2026-09-11 10:00:00');

$cfgs = [];
foreach (['classic', 'modern', 'minimal', 'dark'] as $tema) { foreach ([1, 0] as $reac) { foreach (['asc', 'desc'] as $ord) { $cfgs["$tema-r$reac-$ord"] = ['theme' => $tema, 'reactions_enabled' => (string) $reac, 'comments_ordering' => $ord]; } } }
$setCom = function (array $extra) use ($db) { q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode(array_merge(['default_publish' => '1', 'max_level' => '3', 'default_limit' => '100'], $extra))) . "' WHERE element='com_engage' AND type='component'"); };
$cl = ['invitado' => new Cliente($BASE, "$WORK/tmp", 'oid-g'), 'registered' => new Cliente($BASE, "$WORK/tmp", 'oid-r'), 'manager' => new Cliente($BASE, "$WORK/tmp", 'oid-m')];
if (!$cl['registered']->login('otrosreg', $PASS) || !$cl['manager']->login('otrosmgr', $PASS)) { die("No se pudo iniciar sesion\n"); }
$norm = function (string $h): string {
	$h = preg_replace(['/"userInteractionCount":\d+/', '/UserPageVisits:\d+/', '/Hits: \d+/'], ['"userInteractionCount":N', 'UserPageVisits:N', 'Hits: N'], $h); // contador de visitas del articulo
	return preg_replace(['/name="[0-9a-f]{32}" value="1"/', '/"csrf\.token":"[0-9a-f]{32}"/', '/[?&]([0-9a-f]{32})=1/', '/([A-Za-z0-9_\/.\-]+\.(?:css|js))\?[0-9a-f]{6,}/', '/(returnurl=)[A-Za-z0-9+\/=_%-]+/', '/("akeeba\.Engage\.Comments\.[A-Za-z]+URL":")[A-Za-z0-9+\/=_-]+/'], ['name="T" value="1"', '"csrf.token":"T"', '', '$1?V', '$1R', '$1U'], $h);
};
$total = 0; $dif = 0; $falta = 0;
foreach ($cfgs as $cn => $cfg) {
	$setCom($cfg);
	foreach ($PAGES as $pn => $page) {
		foreach ($cl as $an => $c) {
			$r = $c->get($page); $x = $norm($r['body']); $total++;
			$f = "$dir/$FASE/$cn-$pn-$an.html";
			if (!in_array($r['code'], [200, 403, 404], true) || strlen($x) < 800) { echo "FALLA $cn/$pn/$an: respuesta {$r['code']}, " . strlen($x) . " bytes\n"; $dif++; continue; }
			file_put_contents($f, $x);
			if ($FASE === 'despues') {
				$ref = "$dir/antes/$cn-$pn-$an.html";
				if (!is_file($ref)) { echo "FALTA la referencia: $ref\n"; $falta++; continue; }
				if (file_get_contents($ref) !== $x) { $dif++; echo "DIFIERE $cn/$pn/$an\n"; $a = explode("\n", file_get_contents($ref)); $b = explode("\n", $x); foreach ($b as $i => $l) { if (($a[$i] ?? null) !== $l) { echo "   linea $i\n   antes:   " . substr((string) ($a[$i] ?? '(nada)'), 0, 200) . "\n   despues: " . substr($l, 0, 200) . "\n"; break; } } }
			}
		}
	}
}
if ($FASE === 'antes') { echo "Referencia de la 0.8.1 guardada: $total paginas en $dir/antes\n"; exit($dif ? 1 : 0); }
echo "\n$total paginas (16 configuraciones de tema, reacciones y orden x articulo publico y restringido x invitado, Registered y Manager): " . ($total - $dif - $falta) . " IDENTICAS byte a byte a la 0.8.1, $dif distintas, $falta sin referencia\n";
exit(($dif || $falta) ? 1 : 0);
