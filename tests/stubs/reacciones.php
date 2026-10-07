<?php
/**
 * Piezas simuladas para tests/30-reacciones.php: una base de datos que REGISTRA las consultas y los parametros enlazados, y un
 * almacen de reacciones en memoria con el mismo contrato que ReactionStoreInterface.
 */
namespace Joomla\Database {
	if (!interface_exists(DatabaseInterface::class)) { interface DatabaseInterface {} }
}
namespace {
	use Akeeba\Component\Engage\Administrator\Helper\ReactionStoreInterface;

	/** Consulta que registra tipo, partes, parametros enlazados y listas whereIn. */
	class RecQuery
	{
		public $type = '';
		public $parts = [];
		public $binds = [];
		public $lists = [];
		public function __call($m, $a)
		{
			$m = strtolower($m);
			if ($m === 'bind') { $this->binds[$a[0]] = $a[1]; return $this; }
			if ($m === 'wherein') { $this->lists[] = [$a[0], $a[1], $a[2] ?? null]; return $this; }
			if (in_array($m, ['select', 'insert', 'delete', 'update'], true)) { $this->type = $m; }
			$this->parts[] = $m . ':' . json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
			return $this;
		}
		public function __toString(): string { return $this->text(); }
		public function text(): string { return $this->type . ' ' . implode(' | ', $this->parts); }
	}

	class RecDb implements \Joomla\Database\DatabaseInterface
	{
		/** @var RecQuery[] */
		public $log = [];
		public $tx = [];
		public $rows = [];
		public $failExecute = false;
		private $last;
		public function createQuery() { return new RecQuery(); }
		public function getQuery($new = false) { return new RecQuery(); }
		public function quoteName($n, $as = null)
		{
			if (is_array($n)) { return array_map([$this, 'quoteName'], $n); }
			$q = implode('.', array_map(fn($p) => '`' . str_replace('`', '``', $p) . '`', explode('.', (string) $n)));
			return $as ? "$q AS `$as`" : $q;
		}
		public function setQuery($q) { $this->log[] = $q; $this->last = $q; return $this; }
		public function loadAssocList() { $r = array_shift($this->rows); return $r ?? []; }
		public function loadAssoc() { return ($this->loadAssocList()[0] ?? null); }
		public function execute() { if ($this->failExecute) { throw new \RuntimeException('fallo'); } return true; }
		public function transactionStart() { $this->tx[] = 'start'; }
		public function transactionCommit() { $this->tx[] = 'commit'; }
		public function transactionRollback() { $this->tx[] = 'rollback'; }
	}

	/** Almacen en memoria: comentarios y reacciones. */
	class MemStore implements ReactionStoreInterface
	{
		public $comments = [];   // id => [id, asset_id, enabled, created_by, email]
		public $emails = [];     // 0.7.1: usuario => email de su cuenta (para contar sin las reacciones propias por email)
		public $log = [];        // 0.7.1: orden de las operaciones: begin, lock:N, add:..., remove:..., commit / rollback
		public $goneOnLock = false;
		public $rows = [];       // "c:u:t" => created
		public $writes = 0;
		public $failAdd = false;
		public function comment(int $id, int $asset = 1, int $enabled = 1, int $author = 99, string $email = ''): void { $this->comments[$id] = ['id' => $id, 'asset_id' => $asset, 'enabled' => $enabled, 'created_by' => $author, 'email' => $email]; }
		public function comments(array $ids): array { $o = []; foreach ($ids as $i) { if (isset($this->comments[$i])) { $o[$i] = $this->comments[$i]; } } return $o; }
		public function userTypes(int $userId, array $commentIds): array
		{
			$o = [];
			foreach ($this->rows as $k => $_) { [$c, $u, $t] = array_map('intval', explode(':', $k)); if ($u === $userId && in_array($c, $commentIds, true)) { $o[$c][] = $t; } }
			return $o;
		}
		public function counts(array $commentIds): array
		{
			$o = [];
			foreach ($this->rows as $k => $_)
			{
				[$c, $u, $t] = array_map('intval', explode(':', $k));
				if (!in_array($c, $commentIds, true) || !in_array($t, [1, 2], true) || !isset($this->comments[$c])) { continue; }
				$cm = $this->comments[$c];
				// 0.7.1: sin las reacciones que el autor se dio a su propio comentario (created_by, o email de la cuenta en un comentario de invitado)
				if ($u === (int) $cm['created_by'] || ((int) $cm['created_by'] <= 0 && $cm['email'] !== '' && isset($this->emails[$u]) && strcasecmp($cm['email'], $this->emails[$u]) === 0)) { continue; }
				$o[$c][$t] = ($o[$c][$t] ?? 0) + 1;
			}
			return $o;
		}
		public function add(int $commentId, int $userId, int $type, string $created): void { if ($this->failAdd) { throw new \RuntimeException('x'); } $this->writes++; $this->log[] = "add:$commentId:$userId:$type"; $this->rows["$commentId:$userId:$type"] = $created; }
		public function remove(int $commentId, int $userId, int $type): void { $this->writes++; $this->log[] = "remove:$commentId:$userId:$type"; unset($this->rows["$commentId:$userId:$type"]); }
		public function transaction(callable $fn): void { $snap = $this->rows; $this->log[] = 'begin'; try { $fn(); $this->log[] = 'commit'; } catch (\Throwable $e) { $this->rows = $snap; $this->log[] = 'rollback'; throw $e; } }
		public function lockComment(int $commentId): bool { $this->log[] = 'lock:' . $commentId; return !$this->goneOnLock && isset($this->comments[$commentId]); }
		public function has(int $c, int $u, int $t): bool { return isset($this->rows["$c:$u:$t"]); }
	}
}
