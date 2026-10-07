<?php
/**
 * Utilidad de tests/joomla-live/lib/xss-navegador.js: cambia la configuracion de cache del Joomla de pruebas y siembra o borra los comentarios.
 *   php xss-config.php set <caching 0|1> <cache de paginas 0|1> <engagecache 0|1> <live_site 0|1>
 *   php xss-config.php seed | unseed | restore
 * Guarda la configuracion original en $WORK/xss-config.orig (y la restaura con `restore`). Requiere WORK, SITE_DIR, IDS_FILE, DB_NAME, DB_USER.
 */
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$SITE = $WORK . '/' . (getenv('SITE_DIR') ?: 'site');
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$cfgPath = "$SITE/configuration.php"; $bak = "$WORK/xss-config.orig"; $estado = "$WORK/xss-config.estado.json";
$cmd = $argv[1] ?? '';
$limpia = function () use ($SITE) { foreach (['cache', 'administrator/cache'] as $d) { foreach (glob("$SITE/$d/*", GLOB_ONLYDIR) ?: [] as $g) { exec('rm -rf ' . escapeshellarg($g)); } } };
$ASSET = (int) $ids['art_publico_asset'];
if ($cmd === 'set') {
	if (!is_file($bak)) { copy($cfgPath, $bak); $db_prev = [$db->query("SELECT enabled, IFNULL(params,'') FROM jos_extensions WHERE element='cache' AND folder='system'")->fetch_row(), $db->query("SELECT enabled FROM jos_extensions WHERE element='engagecache' AND folder='system'")->fetch_row()]; file_put_contents($estado, json_encode($db_prev)); }
	[$caching, $page, $ecache, $live] = array_map('intval', array_slice($argv, 2, 4));
	$cfg = str_replace('public $caching = 0;', 'public $caching = ' . $caching . ';', file_get_contents($bak));
	if (!$live) { $cfg = preg_replace("/public \\\$live_site = '[^']*';/", "public \$live_site = '';", $cfg); }
	file_put_contents($cfgPath, $cfg);
	$db->query("UPDATE jos_extensions SET enabled=$page, params='{\"browsercache\":\"0\",\"cachetime\":\"15\"}' WHERE element='cache' AND folder='system'");
	$db->query("UPDATE jos_extensions SET enabled=$ecache WHERE element='engagecache' AND folder='system'");
	$limpia(); usleep(2500000);
} elseif ($cmd === 'seed') {
	$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
	$id = [];
	foreach ([[0, 'Raiz uno', 1], [1, 'Respuesta uno', 1], [0, 'Raiz dos', 1], [3, 'Respuesta dos', 1], [0, 'Sin publicar', 0]] as $i => [$padre, $txt, $en]) {
		$p = $padre ? (int) $id[$padre] : 'NULL';
		$db->query("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET,$p,'<p>$txt</p>','Invitado','inv@example.invalid','203.0.113.7','t',$en,'2026-09-0" . ($i + 1) . " 10:00:00',0)");
		$id[$i + 1] = $db->insert_id;
	}
} elseif ($cmd === 'unseed') {
	$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
} elseif ($cmd === 'restore') {
	if (is_file($bak)) { copy($bak, $cfgPath); unlink($bak); }
	if (is_file($estado)) { [$c, $e] = json_decode(file_get_contents($estado), true); $db->query("UPDATE jos_extensions SET enabled=" . (int) $c[0] . ", params='" . $db->real_escape_string((string) $c[1]) . "' WHERE element='cache' AND folder='system'"); $db->query("UPDATE jos_extensions SET enabled=" . (int) $e[0] . " WHERE element='engagecache' AND folder='system'"); unlink($estado); }
	$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET");
	$limpia();
} else { fwrite(STDERR, "Uso: set|seed|unseed|restore\n"); exit(2); }
echo "ok $cmd\n";
