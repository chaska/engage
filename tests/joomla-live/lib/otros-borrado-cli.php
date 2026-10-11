<?php
/**
 * 0.8.2: Meta::deleteAssetData() con el Joomla y MariaDB REALES (CLI): borra los comentarios, las reacciones y las bajas de aviso de UN activo
 * de otro componente, no toca los de otros activos ni los articulos, rechaza ids invalidos sin tocar nada y es repetible. Sale con 1 si algo FALLA.
 */
ob_start();
define('_JEXEC', 1);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
define('JPATH_BASE', "$WORK/" . (getenv('SITE_DIR') ?: 'site'));
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

$container = Factory::getContainer();
$container->alias('session.cli', 'session.web.site')->alias('session', 'session.web.site')->alias('JSession', 'session.web.site')
	->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(ConsoleApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$db = $container->get(DatabaseInterface::class);
$ko = 0;
function r(string $d, bool $ok, string $ev = ''): void { global $ko; if (!$ok) { $ko++; } fwrite(STDOUT, sprintf("%-6s %s%s\n", $ok ? 'PASA' : 'FALLA', $d, $ev !== '' ? "  [$ev]" : '')); }
function val(string $s) { global $db; return (int) $db->setQuery($s)->loadResult(); }
function sql(string $s) { global $db; $db->setQuery($s)->execute(); }

$A = 900091; $B = 900092; $ART = (int) json_decode(file_get_contents($WORK . '/' . (getenv('IDS_FILE') ?: 'ids.json')), true)['art_publico_asset'];
sql("DELETE FROM #__engage_unsubscribe WHERE asset_id IN ($A, $B)"); sql("DELETE FROM #__engage_comments WHERE asset_id IN ($A, $B)");
$ids = [];
foreach ([$A => 3, $B => 2] as $asset => $n) {
	for ($i = 0; $i < $n; $i++) {
		sql("INSERT INTO #__engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset,NULL,'<p>borrar $asset-$i</p>','X','x@example.invalid','203.0.113.7','t',1,NOW(),0)");
		$ids[$asset][] = (int) $db->insertid();
	}
	sql("INSERT INTO #__engage_reactions (comment_id,user_id,type,created) VALUES ({$ids[$asset][0]}, 1, 1, NOW()), ({$ids[$asset][1]}, 2, 3, NOW())");
	sql("INSERT INTO #__engage_unsubscribe (asset_id,email) VALUES ($asset, 'baja@example.invalid')");
}
$artAntes = val("SELECT COUNT(*) FROM #__engage_comments WHERE asset_id=$ART");
$meta = \Akeeba\Component\Engage\Site\Helper\Meta::class;
$cuentas = fn(int $a) => [val("SELECT COUNT(*) FROM #__engage_comments WHERE asset_id=$a"), val("SELECT COUNT(*) FROM #__engage_reactions WHERE comment_id IN (" . implode(',', $ids[$a] ?? [0]) . ")"), val("SELECT COUNT(*) FROM #__engage_unsubscribe WHERE asset_id=$a")];
r('antes: A tiene 3 comentarios, 2 reacciones y 1 baja', $cuentas($A) === [3, 2, 1], json_encode($cuentas($A)));
foreach ([0, -1, 4294967296] as $mal) { $x = $meta::deleteAssetData($mal); r("id invalido $mal: -1 y no se toca nada", $x === -1 && $cuentas($A) === [3, 2, 1] && $cuentas($B) === [2, 2, 1]); }
$n = $meta::deleteAssetData($A);
r('borrar A: devuelve 3', $n === 3, (string) $n);
r('A queda sin comentarios, sin reacciones y sin bajas', $cuentas($A) === [0, 0, 0], json_encode($cuentas($A)));
r('B intacto (2 comentarios, 2 reacciones, 1 baja)', $cuentas($B) === [2, 2, 1], json_encode($cuentas($B)));
r('los comentarios del articulo no cambian', val("SELECT COUNT(*) FROM #__engage_comments WHERE asset_id=$ART") === $artAntes);
r('repetirlo no falla (0 comentarios)', $meta::deleteAssetData($A) === 0);
r('un id sin nada devuelve 0', $meta::deleteAssetData(900099) === 0);
$meta::deleteAssetData($B);
r('B borrado tambien', $cuentas($B) === [0, 0, 0]);
echo $ko ? "\n$ko FALLA\n" : "\nTodo PASA\n";
exit($ko ? 1 : 0);
