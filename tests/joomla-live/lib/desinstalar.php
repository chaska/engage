<?php
/**
 * 0.7.0: desinstala el paquete pkg_engage con el instalador REAL de Joomla (Installer::uninstall) y comprueba que no queda nada:
 * las tres tablas (comentarios, bajas de correo y reacciones), las 13 extensiones, sus ficheros, los sitios de actualizacion, el
 * esquema y las plantillas de correo. Uso: WORK=... SITE_DIR=site2 DB_NAME=joomla_upg php desinstalar.php. Sale con 1 si queda algo.
 */
ob_start();
define('_JEXEC', 1);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8081';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
define('JPATH_BASE', "$WORK/" . (getenv('SITE_DIR') ?: 'site2'));
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

$c = Factory::getContainer();
$c->alias('session.cli', 'session.web.site')->alias('session', 'session.web.site')->alias('JSession', 'session.web.site')
	->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $c->get(ConsoleApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$app->getLanguage()->load('lib_joomla', JPATH_ADMINISTRATOR);
$db = $c->get(DatabaseInterface::class);
$app->loadIdentity($c->get(UserFactoryInterface::class)->loadUserByUsername('admintest'));
$RES = [];
function r(string $d, bool $ok, string $ev = ''): void { global $RES; $RES[] = $ok; printf("%-6s %s%s\n", $ok ? 'PASA' : 'FALLA', $d, $ev !== '' ? "  [$ev]" : ''); }
$val = fn(string $s) => $db->setQuery($s)->loadResult();

$pkg = (int) $val("SELECT extension_id FROM #__extensions WHERE type='package' AND element='pkg_engage'");
$n0 = (int) $val("SELECT COUNT(*) FROM #__extensions WHERE element LIKE '%engage%' OR folder='engage'");
try { $rx0 = (int) $val('SELECT COUNT(*) FROM #__engage_reactions'); } catch (Throwable $e) { $rx0 = 0; } // la tabla puede no existir (sitio a medias)
r("antes: paquete instalado (id $pkg), $n0 extensiones de Engage y $rx0 reacciones guardadas", $pkg > 0 && $n0 === 13 && $rx0 >= 0);
$inst = Installer::getInstance();
$ok = $inst->uninstall('package', $pkg);
$msgs = array_map(fn($m) => (is_array($m) ? ($m['message'] ?? '') : (string) $m), $app->getMessageQueue());
printf("INFO   Installer::uninstall(paquete) devolvio %s (valor informativo: el retorno del instalador no es fiable en CLI, tambien con el 3.4.2 original; lo que cuenta es lo que queda)  [%s]\n", var_export($ok, true), substr(strip_tags(implode(' | ', $msgs)), 0, 200));
$tablas = $db->setQuery("SHOW TABLES LIKE " . $db->quote($db->getPrefix() . 'engage%'))->loadColumn();
r('no queda NINGUNA tabla engage_* (comentarios, bajas y reacciones)', !$tablas, implode(',', $tablas));
r('no queda ninguna extension de Engage', (int) $val("SELECT COUNT(*) FROM #__extensions WHERE element LIKE '%engage%' OR folder='engage'") === 0);
r('no queda ningun sitio de actualizacion de Engage', (int) $val("SELECT COUNT(*) FROM #__update_sites WHERE name LIKE '%ngage%'") === 0);
r('no queda esquema (#__schemas) de com_engage', (int) $val("SELECT COUNT(*) FROM #__schemas s LEFT JOIN #__extensions e ON e.extension_id=s.extension_id WHERE e.extension_id IS NULL") === 0);
r('no quedan plantillas de correo de com_engage', (int) $val("SELECT COUNT(*) FROM #__mail_templates WHERE extension='com_engage'") === 0);
$ficheros = [];
foreach (['components/com_engage', 'administrator/components/com_engage', 'media/com_engage', 'modules/mod_engage_latest', 'plugins/content/engage', 'plugins/user/engage', 'plugins/privacy/engage', 'plugins/engage'] as $d) { if (is_dir(JPATH_BASE . "/$d") && (count(scandir(JPATH_BASE . "/$d")) > 2)) { $ficheros[] = $d; } } // una carpeta de grupo de plugins VACIA (plugins/engage) la deja Joomla
r('no quedan carpetas de Engage en el sitio', !$ficheros, implode(',', $ficheros));
$f = count(array_filter($RES, fn($x) => !$x));
printf("\n%d comprobaciones, %d FALLAN\n", count($RES), $f);
exit($f ? 1 : 0);
