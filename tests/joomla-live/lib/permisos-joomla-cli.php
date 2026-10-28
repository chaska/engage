<?php
/**
 * 0.9.16: lo que decide JOOMLA (framework real, CLI). `matrix` imprime {grupo: {accion: true|false|super}} con Access::checkGroup sobre com_engage;
 * `authorise <usuario> <accion>` imprime true|false con User::authorise sobre com_engage (usuario real). Solo lectura.
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

use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

$container = Factory::getContainer();
$container->alias('session.cli', 'session.web.site')->alias('session', 'session.web.site')->alias('JSession', 'session.web.site')
	->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(ConsoleApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$db  = $container->get(DatabaseInterface::class);
$out = '';

if (($argv[1] ?? '') === 'authorise') {
	$u   = $container->get(UserFactoryInterface::class)->loadUserByUsername((string) $argv[2]);
	$out = $u->id > 0 && $u->authorise((string) $argv[3], 'com_engage') ? 'true' : 'false';
} else {
	$m = [];
	foreach ($db->setQuery('SELECT id FROM #__usergroups')->loadColumn() as $g) {
		$super = Access::checkGroup((int) $g, 'core.admin') === true;
		foreach (['core.create', 'core.edit.own', 'core.edit', 'core.edit.state', 'core.delete', 'core.manage'] as $a) {
			$m[(int) $g][$a] = $super ? 'super' : (Access::checkGroup((int) $g, $a, 'com_engage') === true);
		}
	}
	$out = json_encode($m);
}
ob_end_clean();
echo $out, "\n";
