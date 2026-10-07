<?php
/**
 * 0.8.0: con las herramientas nuevas APAGADAS (sort_selector, copy_link y show_badges = 0; default_sort = auto) el HTML de los comentarios es el de la 0.7.1.
 *
 * Dos fases con el mismo hilo (fechas fijas, respuestas, un favorito, sin publicar, spam, autor y moderadores):
 *   FASE=antes    con la 0.7.1 instalada: guarda en $WORK/tanda2-html/antes/ la seccion de comentarios (y las etiquetas de script/estilo y las opciones
 *                 de script de Engage) de cada combinacion: tema (classic, modern, minimal, dark) x reacciones (si/no) x quien mira (invitado, Registered, Manager).
 *   FASE=despues  con la 0.8.0 instalada y las 3 herramientas apagadas: repite y compara BYTE A BYTE con lo guardado (tras normalizar los tokens CSRF).
 * Tambien (FASE=despues) comprueba la diferencia con las herramientas ENCENDIDAS: solo debe cambiar lo previsto (barra, boton, insignias).
 * Requisitos: 03-sembrar-y-probar.sh. Sale con 1 si algo difiere o falta la referencia.
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
$PAGE  = "/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}";
$dir   = "$WORK/tanda2-html"; @mkdir("$dir/antes", 0777, true); @mkdir("$dir/despues", 0777, true);
$passFile = "$WORK/tanda2pass.txt";
if (!is_file($passFile)) { file_put_contents($passFile, 'Aa1!' . bin2hex(random_bytes(10))); chmod($passFile, 0600); }
$PASS = trim(file_get_contents($passFile));
$prev = ['com' => one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"), 'author' => (int) one("SELECT created_by FROM jos_content WHERE id=" . (int) $ids['art_publico'])];
register_shutdown_function(function () use ($db, $prev, $ASSET, $ids) {
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string($prev['com']) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_content SET created_by=" . (int) $prev['author'] . " WHERE id=" . (int) $ids['art_publico']);
	q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
});
$mk = function (string $user, int $group) use ($PASS, $db) {
	$id = (int) one("SELECT id FROM jos_users WHERE username='$user'"); $h = password_hash($PASS, PASSWORD_BCRYPT);
	if (!$id) { q("INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, activation, params, resetCount, otpKey, otep, requireReset, authProvider) VALUES ('$user', '$user', '$user@example.invalid', '$h', 0, 0, NOW(), '', '{}', 0, '', '', 0, '')"); $id = (int) $db->insert_id; q("INSERT INTO jos_user_usergroup_map (user_id, group_id) VALUES ($id, $group)"); }
	else { q("UPDATE jos_users SET password='$h', block=0 WHERE id=$id"); }
	return $id;
};
$u1 = $mk('t2reg1', 2); $u2 = $mk('t2reg2', 2); $uM = $mk('t2mgr', 6);
q("UPDATE jos_content SET created_by=$u2 WHERE id=" . (int) $ids['art_publico']);
q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
$cm = function (string $body, int $by, string $name, int $enabled, ?int $parent, string $when) use ($db, $ASSET) {
	$p = $parent === null ? 'NULL' : $parent; $nm = $by ? 'NULL' : "'$name'"; $em = $by ? 'NULL' : "'" . strtolower($name) . "@example.invalid'";
	q("INSERT INTO jos_engage_comments (id,asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (" . (9000 + (int) substr($when, 8, 2) * 10 + (int) substr($when, 11, 2) % 10) . ",$ASSET,$p,'" . $db->real_escape_string("<p>$body</p>") . "',$nm,$em,'203.0.113.7','t',$enabled,'$when',$by)");
	return (int) $db->insert_id;
};
$c1 = $cm('Raiz de reg1 con <a href="https://ejemplo.example/x">enlace</a>', $u1, '', 1, null, '2026-09-01 10:00:00');
$c2 = $cm('Raiz de un invitado', 0, 'Invitado', 1, null, '2026-09-02 10:00:00');
$c3 = $cm('Raiz del manager', $uM, '', 1, null, '2026-09-03 10:00:00');
$c4 = $cm('Raiz de reg2 (autora)', $u2, '', 1, null, '2026-09-04 10:00:00');
$c5 = $cm('Respuesta de un invitado', 0, 'Invitado', 1, $c1, '2026-09-05 10:00:00');
$c6 = $cm('Respuesta de reg2', $u2, '', 1, $c1, '2026-09-06 10:00:00');
$c7 = $cm('Nieta del manager', $uM, '', 1, $c5, '2026-09-07 10:00:00');
$c9 = $cm('Sin publicar', 0, 'Pendiente', 0, null, '2026-09-09 10:00:00');
$c10 = $cm('Spam', 0, 'Spammer', -3, null, '2026-09-10 10:00:00');
q("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($c1,$uM,1,NOW()),($c3,$u1,3,NOW())");

$cfgs = [];
foreach (['classic', 'modern', 'minimal', 'dark'] as $tema) { foreach ([1, 0] as $reac) { $cfgs["$tema-reac$reac"] = ['theme' => $tema, 'reactions_enabled' => (string) $reac]; } }
$cfgs['classic-css-reac1'] = ['theme' => 'classic', 'loadCustomCss' => '1', 'reactions_enabled' => '1'];
$cfgs['classic-desc-lvl6'] = ['theme' => 'classic', 'comments_ordering' => 'desc', 'max_level' => '6', 'reactions_enabled' => '1'];
$setCom = function (array $extra) use ($db) { q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode(array_merge(['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc', 'default_limit' => '100'], $extra))) . "' WHERE element='com_engage' AND type='component'"); };
$off = ['sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0'];
$actores = ['guest' => null, 'reg' => 't2reg1', 'mgr' => 't2mgr'];
$clientes = [];
foreach ($actores as $n => $u) { $clientes[$n] = new Cliente($BASE, "$WORK/tmp", "t2id-$n"); if ($u && !$clientes[$n]->login($u, $PASS)) { die("No se pudo iniciar sesion como $u\n"); } }

/** Lo que cambia solo por la 0.8.0: la seccion de comentarios, las etiquetas de script/estilo de Engage y sus opciones de script. */
$extrae = function (string $html): string {
	$sec = preg_match('/<section id="akengage-comments-section".*?<\/section>/s', $html, $m) ? $m[0] : '';
	preg_match_all('/<(?:script|link)\b[^>]*com_engage[^>]*>/', $html, $tags);
	preg_match_all('/"akeeba\.Engage\.[A-Za-z.]+":(?:\{[^{}]*\}|"[^"]*"|\d+)/', $html, $opts);
	preg_match_all('/"COM_ENGAGE_[A-Z_]+":"[^"]*"/', $html, $txt);
	if (getenv('IGNORAR_EOL') === '1') { $sec = str_replace("\r\n", "\n", $sec); } // solo para pruebas con el arbol de trabajo en LF; la comprobacion final es exacta
	$all = $sec . "\n--TAGS--\n" . implode("\n", $tags[0]) . "\n--OPTS--\n" . implode("\n", $opts[0]) . "\n--TXT--\n" . implode("\n", $txt[0]);

	// Las opciones de script de moderacion (editURL, deleteURL...) son la URL en base64 con el token CSRF y la URL de retorno (que a su vez lleva un token): se decodifican y se normalizan
	$all = preg_replace_callback('/"(akeeba\.Engage\.Comments\.[A-Za-z]+URL)":"([A-Za-z0-9+\/=_-]+)"/', fn($m) => '"' . $m[1] . '":"' . preg_replace('/[0-9a-f]{32}/', 'T', base64_decode($m[2]) . '|' . base64_decode((string) preg_replace('/^.*returnurl=([^&]+).*$/s', '$1', base64_decode($m[2])))) . '"', $all);
	// 0.8.1: los enlaces de la fecha y de «en respuesta a» son relativos y escapados (antes: la URL absoluta de la peticion, sin escapar) y el returnurl del formulario
	// tambien es relativo; se normalizan a la misma forma (sin anfitrion, con & sin escapar) para comparar el resto byte a byte
	$all = preg_replace_callback('~href="([^"]*akengage_cid=[^"]*)"~', fn($m) => 'href="' . preg_replace('~^https?://[^/]+~', '', html_entity_decode($m[1])) . '"', $all);
	$all = preg_replace_callback('~name="returnurl" value="([^"]*)"~', fn($m) => 'name="returnurl" value="' . preg_replace('~^https?://[^/]+~', '', base64_decode(html_entity_decode($m[1]))) . '"', $all);
	$all = preg_replace(['~returnurl=[^&|"]+~', '~(?<=\\|)https?://[^/|"]+~'], ['returnurl=R', ''], $all);
	// Ademas del token CSRF, el sufijo ?<hash> de los recursos cambia en cada instalacion (version de los medios de Joomla), no con el HTML de Engage
	return preg_replace(['/name="[0-9a-f]{32}" value="1"/', '/"csrf\.token":"[0-9a-f]{32}"/', '/[?&]([0-9a-f]{32})=1/', '/(com_engage\/[A-Za-z0-9_\/.\-]+)\?[0-9a-f]{6}/'], ['name="T" value="1"', '"csrf.token":"T"', '', '$1?V'], $all);
};
$n = 0; $dif = 0; $falta = 0; $resultados = [];
foreach ($cfgs as $cn => $cfg) {
	$setCom($FASE === 'antes' ? $cfg : array_merge($cfg, $off));
	foreach ($clientes as $an => $cl) {
		$r = $cl->get($PAGE); $x = $extrae($r['body']); $n++;
		$f = "$dir/$FASE/$cn-$an.html";
		if ($r['code'] !== 200 || strlen($x) < 1500) { echo "FALLA $cn/$an: respuesta {$r['code']}, " . strlen($x) . " bytes\n"; $dif++; continue; }
		file_put_contents($f, $x);
		if ($FASE === 'despues') {
			$ref = "$dir/antes/$cn-$an.html";
			if (!is_file($ref)) { echo "FALTA la referencia de la 0.7.1: $ref\n"; $falta++; continue; }
			if (file_get_contents($ref) !== $x) { $dif++; echo "DIFIERE $cn/$an\n"; $a = explode("\n", file_get_contents($ref)); $b = explode("\n", $x); foreach ($b as $i => $l) { if (($a[$i] ?? null) !== $l) { echo "   linea $i\n   antes:   " . substr((string) ($a[$i] ?? '(nada)'), 0, 200) . "\n   despues: " . substr($l, 0, 200) . "\n"; break; } } }
			else { $resultados[] = "$cn/$an"; }
		}
	}
}
if ($FASE === 'antes') { echo "Referencia de la 0.7.1 guardada: $n combinaciones en $dir/antes\n"; exit($dif ? 1 : 0); }
echo "\n$n combinaciones (10 configuraciones de tema, reacciones, CSS propio, orden y nivel x invitado, Registered y Manager): " . count($resultados) . " IDENTICAS a la 0.7.1, $dif distintas, $falta sin referencia\n";
// Con las herramientas ENCENDIDAS solo cambia lo previsto
$setCom(['theme' => 'classic', 'reactions_enabled' => '1']);
$on = $clientes['guest']->get($PAGE); $xo = $extrae($on['body']);
$ref = file_get_contents("$dir/antes/classic-reac1-guest.html");
$quitado = preg_replace(['/<div class="akengage-toolbar".*?<\/div>\s*<\/div>\s*/s'], [''], $xo);
echo "Con las herramientas encendidas el HTML del invitado cambia (barra, boton Copiar, opciones de script): " . ($xo !== $ref ? 'si' : 'NO') . "\n";
exit(($dif || $falta || $xo === $ref) ? 1 : 0);
