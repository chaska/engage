<?php
/**
 * Utilidad de revision-082-navegador.js (0.8.3, H3 y H9): siembra comentarios con BBCode hostil y HTML con estilos hostiles en el articulo publico y cambia
 * el filter_mode de com_engage.   php revision-082-nav-datos.php sembrar|modo <joomla|htmlpurifier|strict>|borrar
 * Guarda los parametros originales de com_engage en $WORK/rev082-nav.json y los restaura con `borrar`. Requiere WORK, SITE_DIR, IDS_FILE, DB_NAME, DB_USER.
 */
require __DIR__ . '/revision-082-comun.php';
$estado = "$WORK/rev082-nav.json"; $cmd = $argv[1] ?? '';
$cuerpos = [
	'b1' => '[url=javascript:alert(document.domain)]clic-b1[/url]',
	'b2' => '[url]javascript:alert(document.domain)[/url]',
	'b3' => '[img]javascript:alert(document.domain)[/img] imagen-b3',
	'b4' => '[url=&#106;avascript:alert(document.domain)]clic-b4[/url]',
	'b5' => "[url=java\tscript:alert(document.domain)]clic-b5[/url]",
	'b6' => '[url=data:text/html,<script>alert(document.domain)</script>]clic-b6[/url]',
	'b7' => '[url=vbscript:msgbox(1)]clic-b7[/url] [url=JaVaScRiPt&colon;alert(document.domain)]clic-b7b[/url]',
	'b8' => '[url=https://example.com/ok?a=1&b=2]enlace-legitimo[/url] [url=mailto:ana@example.com]correo-legitimo[/url] [img]https://example.com/i.png[/img] [b]negrita-legitima[/b]',
	'h1' => '<p>pixel <span style="background:url(http://127.0.0.1:8099/pixel.gif)">pixel-h1</span></p>',
	'h2' => '<p>layout <span style="color:red;position:fixed;top:0;left:0;width:9999px;height:9999px;margin-left:-5000px;z-index:99999;font-size:900px">layout-h2</span> <span style="color:red;font-weight:bold">estilo-legitimo-h2</span></p>',
];
if ($cmd === 'sembrar') {
	if (!is_file($estado)) { file_put_contents($estado, json_encode(['com' => one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'")])); }
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-NAV-%'");
	$i = 0;
	foreach ($cuerpos as $k => $b) { $i++; q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'" . esc($b) . "','R3N-NAV-$k','nav$i@example.invalid','203.0.113.$i','t',1,'2026-09-0" . min($i, 9) . " 10:00:00',0)"); }
	$p = ['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100', 'reactions_enabled' => '0', 'sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0', 'filter_mode' => 'strict'];
	q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
} elseif ($cmd === 'modo') {
	$p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: [];
	$p['filter_mode'] = $argv[2] ?? 'strict';
	q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
	exec('rm -rf ' . escapeshellarg("$SITE/cache") . '/*/ 2>/dev/null');
} elseif ($cmd === 'borrar') {
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-NAV-%'");
	if (is_file($estado)) { $e = json_decode(file_get_contents($estado), true); q("UPDATE jos_extensions SET params='" . esc($e['com']) . "' WHERE element='com_engage' AND type='component'"); unlink($estado); }
} else { fwrite(STDERR, "Uso: sembrar|modo <m>|borrar\n"); exit(2); }
echo "ok $cmd\n";
