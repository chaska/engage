<?php
/**
 * Stubs MÍNIMOS de clases de Joomla, solo para ejecutar lógica del fork sin tener Joomla.
 * No reproducen el comportamiento real de Joomla: solo lo imprescindible para cada prueba.
 */
namespace Joomla\CMS\MVC\Model {
	class ListModel
	{
		public $state;
		public $db;
		public function __construct($config = [], $factory = null) {}
		public function getState($p = null, $d = null) { return $this->state->get($p, $d); }
		public function setState($p, $v) { return $this->state->set($p, $v); }
		public function getDatabase() { return $this->db; }
	}
}
namespace Joomla\CMS\MVC\Factory { interface MVCFactoryInterface {} }
namespace Joomla\Database {
	class ParameterType { const STRING = 'string'; const INTEGER = 'int'; }
	class DatabaseQuery {}
}
namespace Joomla\CMS\User { class User {} }
namespace {
	if (!defined('_JEXEC')) { define('_JEXEC', 1); }

	/** Estado tipo Registry mínimo. */
	class FakeState
	{
		public $d = [];
		public function get($k, $def = null) { return $this->d[$k] ?? $def; }
		public function set($k, $v) { $this->d[$k] = $v; return $v; }
	}

	/** Consulta falsa que registra las cláusulas que se le piden. */
	class FakeQuery extends \Joomla\Database\DatabaseQuery
	{
		public $order = [];
		public $where = [];
		public function select($x) { return $this; }
		public function from($x) { return $this; }
		public function join($t, $a, $b = null) { return $this; }
		public function where($x) { $this->where[] = $x; return $this; }
		public function whereIn($a, $b, $c = null) { return $this; }
		public function bind($a, &$b, $c = null) { return $this; }
		public function order($x) { $this->order[] = $x; return $this; }
	}

	/** Base de datos falsa: quoteName entrecomilla como MySQL, escape escapa comillas. */
	class FakeDb
	{
		public function createQuery() { return new FakeQuery(); }
		public function quoteName($n, $as = null)
		{
			if (is_array($n)) { return array_map([$this, 'quoteName'], $n); }
			$q = implode('.', array_map(fn($p) => '`' . str_replace('`', '``', $p) . '`', explode('.', $n)));
			return $as ? "$q AS `$as`" : $q;
		}
		public function escape($t, $extra = false) { return addslashes($t); }
	}
}

namespace Joomla\CMS\Component {
	/** getParams(): lee $GLOBALS['T_PARAMS'][nombre]; filterText(): simula un filtro de Joomla simple. */
	class ComponentHelper
	{
		public static function getParams($name)
		{
			return new class($GLOBALS['T_PARAMS'][$name] ?? []) {
				public function __construct(private array $d) {}
				public function get($k, $def = null) { return $this->d[$k] ?? $def; }
			};
		}
		public static function filterText($text) { return 'JOOMLA_FILTER[' . strip_tags((string) $text) . ']'; }
	}
}
namespace Joomla\CMS {
	class Factory
	{
		public static function getApplication()
		{
			return new class { public function get($k, $d = null) { return $k === 'secret' ? ($GLOBALS['T_SECRET'] ?? 'secreto-de-prueba') : $d; } };
		}
		public static function getUser()
		{
			return new class { public function get($k) { return 42; } };
		}
	}
}
namespace Joomla\CMS\Access {
	class Access { public static function getGroupsByUser($id) { return $GLOBALS['T_GROUPS'] ?? [1]; } }
}
namespace Joomla\CMS\Filter {
	class InputFilter
	{
		// Como joomla/filter 4.x (Joomla 5 y 6): propiedades públicas blockedTags / blockedAttributes.
		public $blockedTags = ['applet', 'body', 'embed', 'frame', 'iframe', 'object', 'script', 'style'];
		public $blockedAttributes = ['action', 'background', 'codebase', 'dynsrc', 'formaction', 'lowsrc'];
		public static function getInstance($t = [], $a = [], $tm = 0, $am = 0, $xss = 1) { return new self(); }
	}
}
namespace Joomla\Database {
	trait DatabaseAwareTrait {}
}

namespace Joomla\CMS\Crypt {
	class Crypt { public static function timingSafeCompare($a, $b) { return hash_equals((string) $a, (string) $b); } }
}
namespace Joomla\CMS\Router {
	class Route
	{
		const TLS_IGNORE = 0;
		public static function _($url, $xhtml = true, $tls = 0, $absolute = false) { return 'https://example.com/' . $url; }
	}
}
namespace Joomla\CMS\Uri {
	class Uri extends \Joomla\Uri\Uri {}
}
