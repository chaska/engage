<?php
/**
 * 0.5.3: mínimos de Joomla (5.0.0) y PHP (8.1.0) del instalador, y que preflight() los APLICA (antes la clase
 * sobrescribía preflight() sin llamar al padre y los mínimos no se comprobaban).
 *
 * El stub de InstallerScript reproduce la parte de preflight() de libraries/src/Installer/InstallerScript.php de
 * Joomla 5.4 y 6.1 (comprobaciones de PHP y Joomla), que es lo que Engage hereda. No es Joomla real.
 */
namespace Joomla\CMS\Installer {
	class InstallerScript
	{
		protected $minimumPhp;
		protected $minimumJoomla;
		protected $allowDowngrades = false;
		public function preflight($type, $parent)
		{
			if (!empty($this->minimumPhp) && version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
				\Joomla\CMS\Log\Log::add('JLIB_INSTALLER_MINIMUM_PHP ' . $this->minimumPhp, 0, 'jerror');
				return false;
			}
			if (!empty($this->minimumJoomla) && version_compare(JVERSION, $this->minimumJoomla, '<')) {
				\Joomla\CMS\Log\Log::add('JLIB_INSTALLER_MINIMUM_JOOMLA ' . $this->minimumJoomla, 0, 'jerror');
				return false;
			}
			return true;
		}
	}
	class InstallerAdapter {}
}
namespace Joomla\CMS\Installer\Adapter { class PackageAdapter extends \Joomla\CMS\Installer\InstallerAdapter {} }
namespace Joomla\CMS\Log { class Log { const WARNING = 4; public static $mensajes = []; public static function add($m, $p = 0, $c = '') { self::$mensajes[] = $m; } } }
namespace Joomla\CMS { class Factory { public static function getContainer() { return new class { public function get($id) { return new class { public function setQuery($q) { return $this; } public function execute() { return true; } }; } }; } } }
namespace Joomla\Database { class DatabaseDriver {} interface DatabaseInterface {} }
namespace Akeeba\Component\Engage\Administrator\Helper { class TemplateEmails {} }
namespace Akeeba\Component\Engage\Administrator\Model { class UpgradeModel {} }

namespace {
	require __DIR__ . '/aserciones.php';
	define('_JEXEC', 1);
	$script = dirname(__DIR__) . '/src/package/script.engage.php';

	if (($argv[1] ?? '') === 'hijo') {
		define('JVERSION', $argv[2]);
		require $script;
		$s = new Pkg_EngageInstallerScript();
		$r = $s->preflight($argv[3], new \Joomla\CMS\Installer\Adapter\PackageAdapter());
		echo json_encode(['r' => $r, 'm' => \Joomla\CMS\Log\Log::$mensajes]), "\n";
		exit(0);
	}
	function hijo(string $jver, string $tipo): array
	{
		return json_decode(trim(shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " hijo $jver $tipo 2>&1")), true) ?? ['r' => 'ERROR', 'm' => []];
	}

	$fuente = file_get_contents($script);
	t_ok(str_contains($fuente, "protected \$minimumPhp = '8.1.0';"), "script.engage.php: minimumPhp = 8.1.0");
	t_ok(str_contains($fuente, "protected \$minimumJoomla = '5.0.0';"), "script.engage.php: minimumJoomla = 5.0.0");
	t_ok(str_contains($fuente, 'parent::preflight($type, $parent)'), 'preflight() llama a parent::preflight()');
	$disp = file_get_contents(dirname(__DIR__) . '/src/component/backend/src/Dispatcher/Dispatcher.php');
	t_ok(str_contains($disp, "\$minPHPVersion = '8.1.0';"), 'Dispatcher: PHP mínimo 8.1.0');

	foreach (['3.10.12' => false, '4.3.0' => false, '4.4.14' => false, '4.9.9' => false, '5.0.0' => true, '5.4.2' => true, '6.0.0' => true, '6.1.5' => true] as $v => $esp) {
		foreach (['install', 'update'] as $tipo) {
			$o = hijo($v, $tipo);
			t_ok($o['r'] === $esp, "Joomla $v ($tipo): preflight " . var_export($o['r'], true) . ($esp ? ' (acepta)' : ' (rechaza: ' . implode(',', $o['m']) . ')'));
		}
	}
	$o = hijo('3.10.12', 'uninstall');
	t_ok($o['r'] === true, 'desinstalar nunca se bloquea (Joomla 3.10.12, uninstall)');

	// PHP: la propiedad se aplica de verdad (no se puede cambiar PHP_VERSION; se sube el mínimo por reflexión)
	define('JVERSION', '6.1.5');
	require $script;
	$s  = new Pkg_EngageInstallerScript();
	$rp = new ReflectionProperty($s, 'minimumPhp'); $rp->setAccessible(true); $rp->setValue($s, '99.0.0');
	t_ok($s->preflight('install', new \Joomla\CMS\Installer\Adapter\PackageAdapter()) === false, 'con un minimumPhp superior al PHP actual, preflight rechaza');
	t_ok(version_compare(PHP_VERSION, '8.1.0', '>='), 'el PHP de pruebas (' . PHP_VERSION . ') cumple el mínimo');
	t_fin();
}
