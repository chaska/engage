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
