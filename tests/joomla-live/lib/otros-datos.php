<?php
/**
 * 0.8.2: datos de la prueba de comentarios en otros componentes (tests/joomla-live/21-otros-componentes.sh).
 *   php otros-datos.php crear   tabla #__tercero_prueba con 14 elementos del plugin «tercero» de prueba, un comentario visible en cada activo
 *                               (los 14 + los 12 del plugin hostil) con una marca unica `MARCA-<asset_id>`, usuarios `otrosreg` (Registered) y
 *                               `otrosmgr` (Manager) y los dos plugins de prueba activados; ajustes de com_engage de pruebas
 *   php otros-datos.php borrar  lo deja como estaba (tabla, comentarios, usuarios, ajustes). Requiere WORK, SITE_DIR, DB_NAME, DB_USER.
 * Los asset_id 900001-900014 y 910001-910012 se insertan en #__assets (0.8.3: Engage exige que el activo exista, con nombre com_tercero.item.N o com_hostil.item.N).
 */
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
$estadoF = "$WORK/otros-estado.json";
$passF   = "$WORK/otrospass.txt";
$cmd = $argv[1] ?? '';

/** [id, titulo, published, publish_up (SQL), access, parent_access, category_published, comments_enabled, close_after, comments_show] */
$xss = '<script>window.__xss=1;alert(document.domain)</script><img src=x onerror="window.__xss=1;alert(1)">"\'>';
$filas = [
	[1,  'Elemento publico',                    1, 'NOW()',                                  1, 1,    1,    1, 0,  null],
	[2,  'Sin publicar',                        0, 'NOW()',                                  1, 1,    1,    1, 0,  null],
	[3,  'Solo Registered',                     1, 'NOW()',                                  2, 1,    1,    1, 0,  null],
	[4,  'Padre Special',                       1, 'NOW()',                                  1, 3,    1,    1, 0,  null],
	[5,  'Categoria sin publicar',              1, 'NOW()',                                  1, 1,    0,    1, 0,  null],
	[6,  'Categoria en la papelera',            1, 'NOW()',                                  1, 1,    -2,   1, 0,  null],
	[7,  'Categoria archivada',                 1, 'NOW()',                                  1, 1,    2,    1, 0,  null],
	[8,  'Comentarios cerrados',                1, 'NOW()',                                  1, 1,    1,    0, 0,  null],
	[9,  'Cerrado por antiguedad',              1, 'DATE_SUB(NOW(), INTERVAL 40 DAY)',       1, 1,    1,    1, 30, null],
	[10, 'Abierto, publicado hace poco',        1, 'DATE_SUB(NOW(), INTERVAL 10 DAY)',       1, 1,    1,    1, 30, null],
	[11, 'Comentarios ocultos (comments_show)', 1, 'NOW()',                                  1, 1,    1,    1, 0,  0],
	[12, 'Publicacion futura',                  1, 'DATE_ADD(NOW(), INTERVAL 10 DAY)',       1, 1,    1,    1, 0,  null],
	[13, $xss,                                  1, 'NOW()',                                  1, 1,    1,    1, 0,  null],
	[14, 'Sin padre ni categoria',              1, 'NOW()',                                  1, null, null, 1, 0,  null],
];
$nulo = fn($v) => $v === null ? 'NULL' : (int) $v;

if ($cmd === 'crear') {
	if (!is_file($estadoF)) {
		file_put_contents($estadoF, json_encode([
			'com'  => one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"),
			'plug' => q("SELECT element, enabled FROM jos_extensions WHERE type='plugin' AND element IN ('terceroprueba','tercerohostil','tercerovista')")->fetch_all(MYSQLI_ASSOC),
		]));
	}
	if (!is_file($passF)) { file_put_contents($passF, 'Aa1!' . bin2hex(random_bytes(10))); chmod($passF, 0600); }
	$PASS = trim(file_get_contents($passF));
	q("DROP TABLE IF EXISTS jos_tercero_prueba");
	q("CREATE TABLE jos_tercero_prueba (id INT UNSIGNED NOT NULL PRIMARY KEY, asset_id INT UNSIGNED NOT NULL, title VARCHAR(500) NOT NULL, category VARCHAR(255) NOT NULL DEFAULT 'Viajes', author_name VARCHAR(255) NOT NULL DEFAULT 'Ana', author_email VARCHAR(255) NOT NULL DEFAULT 'ana@example.invalid', url VARCHAR(500) NOT NULL DEFAULT '', public_url VARCHAR(500) NOT NULL DEFAULT '', published TINYINT NOT NULL, publish_up DATETIME NULL, access INT NOT NULL, parent_access INT NULL, category_published INT NULL, comments_enabled TINYINT NOT NULL, close_after INT NOT NULL, comments_show INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	foreach ($filas as [$id, $t, $pub, $up, $acc, $par, $cat, $en, $cl, $show]) {
		$asset = 900000 + $id;
		$url = $id === 13 ? 'javascript:window.__xss=1;alert(1)' : "index.php?tp=$id";
		$pu  = $id === 13 ? 'https://evil.test/x' : "/index.php?tp=$id";
		$an  = $id === 13 ? $xss : 'Ana';
		$ae  = $id === 13 ? "no es un correo\r\nBcc: x@y.z" : 'ana@example.invalid';
		q("INSERT INTO jos_tercero_prueba VALUES ($id, $asset, '" . $db->real_escape_string($t) . "', '" . $db->real_escape_string($id === 13 ? $xss : 'Viajes') . "', '" . $db->real_escape_string($an) . "', '" . $db->real_escape_string($ae) . "', '" . $db->real_escape_string($url) . "', '" . $db->real_escape_string($pu) . "', $pub, $up, $acc, " . $nulo($par) . ", " . $nulo($cat) . ", $en, $cl, " . $nulo($show) . ")");
	}
	// 0.8.3 (H7): Engage exige que el activo exista en #__assets con un nombre `com_<componente>.…` coherente con el tipo de la ficha
	q("DELETE FROM jos_assets WHERE id BETWEEN 900001 AND 900014 OR id BETWEEN 910001 AND 910012");
	foreach (array_merge(range(900001, 900014), range(910001, 910012)) as $a) {
		$comp = $a >= 910001 ? 'hostil' : 'tercero';
		q("INSERT INTO jos_assets (id, parent_id, lft, rgt, level, name, title, rules) VALUES ($a, 1, " . (2 * $a) . ", " . (2 * $a + 1) . ", 1, 'com_$comp.item.$a', 'Activo de prueba $a', '{}')");
	}
	// Una marca unica en un comentario publicado de cada activo: si aparece en la pagina, el hilo se ha mostrado
	q("DELETE FROM jos_engage_comments WHERE asset_id BETWEEN 900001 AND 900014 OR asset_id BETWEEN 910001 AND 910012");
	foreach (array_merge(range(900001, 900014), range(910001, 910012)) as $a) {
		q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($a,NULL,'<p>MARCA-$a</p>','Invitado','inv@example.invalid','203.0.113.7','t',1,'2026-09-01 10:00:00',0)");
	}
	$mk = function (string $user, int $group) use ($PASS, $db) {
		$id = (int) one("SELECT id FROM jos_users WHERE username='$user'"); $h = password_hash($PASS, PASSWORD_BCRYPT);
		if (!$id) {
			q("INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, activation, params, resetCount, otpKey, otep, requireReset, authProvider) VALUES ('$user', '$user', '$user@example.invalid', '$h', 0, 0, NOW(), '', '{}', 0, '', '', 0, '')");
			$id = (int) $db->insert_id; q("INSERT INTO jos_user_usergroup_map (user_id, group_id) VALUES ($id, $group)");
		} else { q("UPDATE jos_users SET password='$h', block=0 WHERE id=$id"); }
	};
	$mk('otrosreg', 2); $mk('otrosmgr', 6);
	q("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND element IN ('terceroprueba','tercerohostil','tercerovista')");
	$p = ['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100', 'reactions_enabled' => '0', 'sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0'];
	q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
	echo "ok crear\n";
} elseif ($cmd === 'borrar') {
	q("DROP TABLE IF EXISTS jos_tercero_prueba");
	q("DELETE FROM jos_assets WHERE id BETWEEN 900001 AND 900014 OR id BETWEEN 910001 AND 910012");
	q("DELETE FROM jos_engage_comments WHERE asset_id BETWEEN 900001 AND 900014 OR asset_id BETWEEN 910001 AND 910012 OR asset_id=999999");
	q("DELETE FROM jos_engage_unsubscribe WHERE asset_id BETWEEN 900001 AND 900014");
	foreach (['otrosreg', 'otrosmgr'] as $u) { $id = (int) one("SELECT id FROM jos_users WHERE username='$u'"); if ($id) { q("DELETE FROM jos_user_usergroup_map WHERE user_id=$id"); q("DELETE FROM jos_users WHERE id=$id"); } }
	if (is_file($estadoF)) {
		$e = json_decode(file_get_contents($estadoF), true);
		q("UPDATE jos_extensions SET params='" . $db->real_escape_string($e['com']) . "' WHERE element='com_engage' AND type='component'");
		unlink($estadoF);
	}
	echo "ok borrar\n";
} else { fwrite(STDERR, "Uso: crear|borrar\n"); exit(2); }
