<?php
/**
 * Stubs MINIMOS para ejecutar el codigo REAL de Meta (src/component/frontend/src/Helper/Meta.php) y del plugin de contenido
 * (src/plugins/content/engage/src/Extension/Engage.php) sin Joomla (0.8.2). No reproducen Joomla: solo lo que cada prueba necesita.
 * Los plugins de terceros se simulan con $GLOBALS['T_PLUGINS'] (callable(int $assetId, bool $cargarParametros): ?array).
 */
namespace Joomla\Registry {
	class Registry
	{
		private array $d = [];
		public function __construct($data = null)
		{
			if (is_array($data)) { $this->d = $data; }
			elseif (is_string($data)) { $x = json_decode($data, true); $this->d = is_array($x) ? $x : []; }
		}
		public function get($k, $def = null)
		{
			$v = $this->d;
			foreach (explode('.', (string) $k) as $p) { if (is_array($v) && array_key_exists($p, $v)) { $v = $v[$p]; } else { $v = null; break; } }

			return ($v !== null && $v !== '') ? $v : $def;
		}
		public function set($k, $v) { $this->d[$k] = $v; return $v; }
		public function exists($k) { return array_key_exists($k, $this->d); }
		public function toArray() { return $this->d; }
	}
}
namespace Joomla\CMS\Date {
	class Date extends \DateTime { public function toUnix() { return $this->getTimestamp(); } }
}
namespace Joomla\CMS {
	class Factory
	{
		public static function getDate($s = 'now') { return new \Joomla\CMS\Date\Date($s); }
		public static function getApplication() { return $GLOBALS['T_APP']; }
		public static function getContainer() { return new class { public function get($x) { return $GLOBALS['T_DB']; } }; }
	}
}
namespace Joomla\CMS\Uri { class Uri { public static function root($p = false) { return 'https://sitio.test/'; } } }
namespace Joomla\CMS\Language { class Text { public static function _($k) { return $k; } public static function sprintf($k, ...$a) { return $k; } } }
namespace Joomla\CMS\Plugin {
	class PluginHelper { public static function importPlugin($g) {} public static function isEnabled($a, $b) { return false; } }
	class CMSPlugin { public function __construct(&$subject = null, $config = []) {} public function getApplication() { return $GLOBALS['T_APP']; } public function loadLanguage() {} }
}
namespace Joomla\CMS\User { class User { public $levels = [1]; public $guest = false; public $id = 5; public $name = 'Ana'; public $email = 'ana@example.test'; public function getAuthorisedViewLevels() { return $this->levels; } public function authorise($a, $b = null) { return false; } } }
namespace Joomla\CMS\Component { class ComponentHelper { public static function isEnabled($c) { return true; } public static function getParams($n) { return new \Joomla\Registry\Registry($GLOBALS['T_PARAMS'] ?? []); } } }
namespace Joomla\CMS\MVC\Factory {
	interface MVCFactoryInterface {}
	interface MVCFactoryServiceInterface {}
	trait MVCFactoryAwareTrait { public function setMVCFactory($f) {} public function getMVCFactory() { return null; } }
}
namespace Joomla\Database {
	interface DatabaseInterface {}
	class ParameterType { const STRING = 'string'; const INTEGER = 'int'; }
	trait DatabaseAwareTrait { public function getDatabase() { $GLOBALS['T_DB_TOCADA'] = ($GLOBALS['T_DB_TOCADA'] ?? 0) + 1; throw new \RuntimeException('la base de datos no se debe tocar'); } }
	class DatabaseQuery {}
}
namespace Joomla\Event { interface SubscriberInterface {} interface DispatcherInterface {}
	class Event
	{
		public function __construct(private array $args = []) {}
		public function getArguments() { return $this->args; }
		public function getArgument($k, $d = null) { return $this->args[$k] ?? $d; }
		public function setArgument($k, $v) { $this->args[$k] = $v; }
	}
}
namespace Joomla\Input {
	class Input
	{
		public array $d;
		public function __construct(array $d = []) { $this->d = $d; }
		public function set($k, $v) { $this->d[$k] = $v; }
		public function get($k, $def = null) { return $this->d[$k] ?? $def; }
		public function getArray() { return $this->d; }
		public function exists($k) { return array_key_exists($k, $this->d); }
	}
}
namespace Joomla\CMS\Dispatcher { interface ComponentDispatcherFactory {} }
namespace Akeeba\Component\Engage\Administrator\Mixin {
	trait RunPluginsTrait
	{
		public static function triggerPluginEventStatic(string $evento, array $args): array
		{
			$GLOBALS['T_PLUGIN_LLAMADAS'][] = $args;
			$f = $GLOBALS['T_PLUGINS'] ?? null;

			return $f ? [$f((int) $args[0], (bool) $args[1])] : [];
		}
	}
}
namespace Akeeba\Component\Engage\Administrator\Helper {
	class UserFetcher { public static function getUser($id = null) { return $GLOBALS['T_USER']; } }
}
namespace {
	if (!defined('_JEXEC')) { define('_JEXEC', 1); }
}
