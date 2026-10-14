<?php
/** 0.9.4: pone las opciones del componente para la prueba del rastreo (selector de orden, reacciones, favoritos y copiar enlace activos) o las restaura. `php rastreo-opciones.php on|restore` */
require __DIR__ . '/revision-082-comun.php';
$f = "$WORK/rastreo-params-orig.json";
$cf = "$WORK/rastreo-config-orig.php"; $cfgPath = "$SITE/configuration.php";
if (($argv[1] ?? '') === 'sef') { // SEF con reescritura y SIN live_site (como hueva.net)
	if (!is_file($cf)) { copy($cfgPath, $cf); }
	$c = preg_replace("/public \\\$live_site = '[^']*';/", "public \$live_site = '';", (string) file_get_contents($cf));
	$c = preg_replace('/public \$sef = (true|false);/', "public \$sef = true;", (string) $c); $c = preg_replace('/public \$sef_rewrite = (true|false);/', "public \$sef_rewrite = true;", (string) $c);
	file_put_contents($cfgPath, $c); usleep(4500000); echo "ok\n"; exit(0);
}
if (($argv[1] ?? '') === 'cfg-restore') { if (is_file($cf)) { copy($cf, $cfgPath); unlink($cf); } echo "ok\n"; exit(0);
}
if (($argv[1] ?? '') === 'on') {
	if (!is_file($f)) { file_put_contents($f, (string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'")); }
	$p = array_merge(json_decode((string) file_get_contents($f), true) ?: [], ['sort_selector' => '1', 'reactions_enabled' => '1', 'reactions_favorites' => '1', 'reactions_dislike' => '1', 'copy_link' => '1', 'crawl_reduce' => '1']);
	q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
} elseif (is_file($f)) {
	q("UPDATE jos_extensions SET params='" . esc((string) file_get_contents($f)) . "' WHERE element='com_engage' AND type='component'"); unlink($f);
}
echo "ok\n";
