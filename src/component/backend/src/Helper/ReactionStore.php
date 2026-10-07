<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.7.0): almacen de reacciones en #__engage_reactions. SOLO consultas del constructor de Joomla con
 * parametros enlazados (enteros): nada de lo que llega se concatena en el SQL. Las funciones estaticas de limpieza las usan el
 * borrado de comentarios, el borrado de usuarios y el plugin de privacidad.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

final class ReactionStore implements ReactionStoreInterface
{
	public const TABLE = '#__engage_reactions';

	/** @var DatabaseInterface */
	private $db;

	public function __construct(DatabaseInterface $db)
	{
		$this->db = $db;
	}

	private function q()
	{
		return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
	}

	/** @inheritDoc */
	public function comments(array $ids): array
	{
		$ids = self::ints($ids);

		if (!$ids) { return []; }

		$db = $this->db;
		$q  = $this->q()
			->select($db->quoteName(['id', 'asset_id', 'enabled', 'created_by', 'email']))
			->from($db->quoteName('#__engage_comments'))
			->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER);
		$out = [];

		foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
		{
			$out[(int) $r['id']] = ['id' => (int) $r['id'], 'asset_id' => (int) $r['asset_id'], 'enabled' => (int) $r['enabled'], 'created_by' => (int) $r['created_by'], 'email' => trim((string) ($r['email'] ?? ''))];
		}

		return $out;
	}

	/** @inheritDoc */
	public function userTypes(int $userId, array $commentIds): array
	{
		$commentIds = self::ints($commentIds);

		if (!$commentIds || $userId <= 0) { return []; }

		$db = $this->db;
		$q  = $this->q()
			->select($db->quoteName(['comment_id', 'type']))
			->from($db->quoteName(self::TABLE))
			->where($db->quoteName('user_id') . ' = :uid')
			->whereIn($db->quoteName('comment_id'), $commentIds, ParameterType::INTEGER)
			->bind(':uid', $userId, ParameterType::INTEGER);
		$out = [];

		foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
		{
			$out[(int) $r['comment_id']][] = (int) $r['type'];
		}

		return $out;
	}

	/** @inheritDoc */
	public function counts(array $commentIds): array
	{
		$commentIds = self::ints($commentIds);

		if (!$commentIds) { return []; }

		$db    = $this->db;
		$types = [Reactions::LIKE, Reactions::DISLIKE];
		// 0.7.1: the joins leave out the reactions that the author of the comment gave to his own comment: by created_by, or (guest
		// comment, created_by empty) by the email of the account that reacted, the same criterion as Reactions::isOwn()
		$q     = $this->q()
			->select([$db->quoteName('r.comment_id'), $db->quoteName('r.type'), 'COUNT(*) AS ' . $db->quoteName('n')])
			->from($db->quoteName(self::TABLE, 'r'))
			->join('INNER', $db->quoteName('#__engage_comments', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('r.comment_id'))
			->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('r.user_id'))
			->whereIn($db->quoteName('r.comment_id'), $commentIds, ParameterType::INTEGER)
			->whereIn($db->quoteName('r.type'), $types, ParameterType::INTEGER)
			->where($db->quoteName('r.user_id') . ' <> IFNULL(' . $db->quoteName('c.created_by') . ', 0)')
			->where('NOT (IFNULL(' . $db->quoteName('c.created_by') . ', 0) <= 0 AND IFNULL(' . $db->quoteName('c.email') . ", '') <> '' AND " . $db->quoteName('c.email') . ' = ' . $db->quoteName('u.email') . ')')
			->group([$db->quoteName('r.comment_id'), $db->quoteName('r.type')]);
		$out = [];

		foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
		{
			$out[(int) $r['comment_id']][(int) $r['type']] = (int) $r['n'];
		}

		return $out;
	}

	/** @inheritDoc */
	public function add(int $commentId, int $userId, int $type, string $created): void
	{
		$db = $this->db;
		$q  = $this->q()
			->insert($db->quoteName(self::TABLE))
			->columns($db->quoteName(['comment_id', 'user_id', 'type', 'created']))
			->values(':cid, :uid, :type, :created')
			->bind(':cid', $commentId, ParameterType::INTEGER)
			->bind(':uid', $userId, ParameterType::INTEGER)
			->bind(':type', $type, ParameterType::INTEGER)
			->bind(':created', $created, ParameterType::STRING);

		try
		{
			$db->setQuery($q)->execute();
		}
		catch (Throwable $e)
		{
			// Peticion simultanea que ya la creo (clave unica): el resultado es el mismo. Cualquier otro fallo se comprueba.
			if (!in_array($type, $this->userTypes($userId, [$commentId])[$commentId] ?? [], true)) { throw $e; }
		}
	}

	/** @inheritDoc */
	public function remove(int $commentId, int $userId, int $type): void
	{
		$db = $this->db;
		$q  = $this->q()
			->delete($db->quoteName(self::TABLE))
			->where($db->quoteName('comment_id') . ' = :cid')
			->where($db->quoteName('user_id') . ' = :uid')
			->where($db->quoteName('type') . ' = :type')
			->bind(':cid', $commentId, ParameterType::INTEGER)
			->bind(':uid', $userId, ParameterType::INTEGER)
			->bind(':type', $type, ParameterType::INTEGER);
		$db->setQuery($q)->execute();
	}

	/** @inheritDoc */
	public function transaction(callable $fn): void
	{
		$this->db->transactionStart();

		try
		{
			$fn();
			$this->db->transactionCommit();
		}
		catch (Throwable $e)
		{
			$this->db->transactionRollback();

			throw $e;
		}
	}

	/** @inheritDoc */
	public function lockComment(int $commentId): bool
	{
		$db = $this->db;

		// MySQL/MariaDB no tienen forUpdate() en el constructor de consultas de Joomla; el id es un entero ya validado
		$sql = 'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__engage_comments') . ' WHERE ' . $db->quoteName('id') . ' = ' . max(0, $commentId) . ' FOR UPDATE';

		return (int) $db->setQuery($sql)->loadResult() === $commentId;
	}

	/**
	 * 0.7.1: borra los me gusta y no me gusta que un usuario dio a comentarios que ahora son SUYOS (un comentario de invitado con su email pasa
	 * a ser suyo al iniciar sesion): no se puede valorar el propio comentario. El favorito se conserva. Nunca lanza.
	 *
	 * @param   int[]  $commentIds
	 */
	public static function deleteOwnLikes(DatabaseInterface $db, int $userId, array $commentIds): void
	{
		$commentIds = self::ints($commentIds);

		if ($userId <= 0 || !$commentIds) { return; }

		try
		{
			$types = [Reactions::LIKE, Reactions::DISLIKE];

			foreach (array_chunk($commentIds, 500) as $chunk)
			{
				$q = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
					->delete($db->quoteName(self::TABLE))
					->where($db->quoteName('user_id') . ' = :uid')
					->whereIn($db->quoteName('comment_id'), $chunk, ParameterType::INTEGER)
					->whereIn($db->quoteName('type'), $types, ParameterType::INTEGER)
					->bind(':uid', $userId, ParameterType::INTEGER);
				$db->setQuery($q)->execute();
			}
		}
		catch (Throwable $e)
		{
		}
	}

	/**
	 * Borra TODAS las reacciones de unos comentarios (al borrarlos). Nunca lanza: una limpieza que falla no debe impedir borrar.
	 *
	 * @param   int[]  $commentIds
	 */
	public static function deleteForComments(DatabaseInterface $db, array $commentIds): void
	{
		$commentIds = self::ints($commentIds);

		if (!$commentIds) { return; }

		try
		{
			foreach (array_chunk($commentIds, 500) as $chunk)
			{
				$q = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
					->delete($db->quoteName(self::TABLE))
					->whereIn($db->quoteName('comment_id'), $chunk, ParameterType::INTEGER);
				$db->setQuery($q)->execute();
			}
		}
		catch (Throwable $e)
		{
			// La tabla puede no existir todavia (actualizacion a medias): se ignora
		}
	}

	/** Borra las reacciones de los comentarios de un contenido (asset), antes de borrar los comentarios. */
	public static function deleteForAsset(DatabaseInterface $db, int $assetId): void
	{
		try
		{
			$sub = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select($db->quoteName('c.id'))
				->from($db->quoteName('#__engage_comments', 'c'))
				->where($db->quoteName('c.asset_id') . ' = :asset');
			$q = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->delete($db->quoteName(self::TABLE))
				->where($db->quoteName('comment_id') . ' IN (' . $sub . ')')
				->bind(':asset', $assetId, ParameterType::INTEGER);
			$db->setQuery($q)->execute();
		}
		catch (Throwable $e)
		{
		}
	}

	/** Borra las reacciones HECHAS por un usuario (al borrar al usuario o a su peticion RGPD). */
	public static function deleteForUser(DatabaseInterface $db, int $userId): void
	{
		if ($userId <= 0) { return; }

		try
		{
			$q = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->delete($db->quoteName(self::TABLE))
				->where($db->quoteName('user_id') . ' = :uid')
				->bind(':uid', $userId, ParameterType::INTEGER);
			$db->setQuery($q)->execute();
		}
		catch (Throwable $e)
		{
		}
	}

	/**
	 * Reacciones de un usuario para la exportacion RGPD: id de comentario, tipo (en texto) y fecha. Nada de otras personas.
	 *
	 * @return array<int,array{id:int,comment_id:int,type:string,created:string}>
	 */
	public static function exportForUser(DatabaseInterface $db, int $userId): array
	{
		if ($userId <= 0) { return []; }

		$q = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->select($db->quoteName(['id', 'comment_id', 'type', 'created']))
			->from($db->quoteName(self::TABLE))
			->where($db->quoteName('user_id') . ' = :uid')
			->order($db->quoteName('id') . ' ASC')
			->bind(':uid', $userId, ParameterType::INTEGER);
		$names = array_flip(Reactions::TYPES);
		$out   = [];

		foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
		{
			$out[] = ['id' => (int) $r['id'], 'comment_id' => (int) $r['comment_id'], 'type' => $names[(int) $r['type']] ?? 'unknown', 'created' => (string) $r['created']];
		}

		return $out;
	}

	/**
	 * Valores enteros positivos y unicos.
	 *
	 * @return int[]
	 */
	private static function ints(array $v): array
	{
		$out = [];

		foreach ($v as $x)
		{
			if (is_int($x) && $x > 0) { $out[$x] = $x; }
			elseif (is_string($x) && preg_match('/^[1-9][0-9]{0,17}$/D', $x)) { $out[(int) $x] = (int) $x; }
		}

		return array_values($out);
	}
}
