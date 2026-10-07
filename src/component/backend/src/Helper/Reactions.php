<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.7.0): reacciones a los comentarios (me gusta, no me gusta y favorito). Esta clase es la LOGICA y no toca
 * Joomla ni la base de datos: todo lo externo (almacen, permisos, limite de frecuencia, reloj) se le inyecta, de modo que
 * las pruebas pueden recorrer cada rama con datos simulados. El controlador del sitio (ReactionsController) la cablea con las
 * piezas reales.
 *
 * Reglas (ver docs/REACCIONES.md):
 *  - Solo usuarios con sesion; invitados solo leen contadores. reactions_who: registered | commenters.
 *  - Me gusta y no me gusta se excluyen entre si (por usuario y comentario); no se puede valorar el propio comentario.
 *  - Favorito es personal y privado: nunca se devuelve a nadie mas, ni contador, ni quien lo marco.
 *  - Solo comentarios publicados (enabled = 1) de contenido que el usuario puede ver.
 *  - Los errores son 4xx genericos: nunca explican que parte de la peticion fallo.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Throwable;

final class Reactions
{
	public const LIKE     = 1;
	public const DISLIKE  = 2;
	public const FAVORITE = 3;

	/** Nombre en la API (JSON y atributo data-engage-react) de cada tipo. */
	public const TYPES = ['like' => self::LIKE, 'dislike' => self::DISLIKE, 'favorite' => self::FAVORITE];

	/** Identificadores que se aceptan en una consulta. */
	public const MAX_IDS = 100;

	/** Contenidos DISTINTOS (articulos) a los que pueden pertenecer los comentarios de una misma consulta (0.7.1). La pagina real pide los de UNO. */
	public const MAX_ASSETS = 10;

	/** Reacciones por usuario y ventana. */
	public const RATE_LIMIT  = 60;
	public const RATE_WINDOW = 60;

	/** Consultas (state) por persona (usuario con sesion, o IP si es invitado) y ventana (0.7.1): una visita de pagina hace una. */
	public const READ_LIMIT = 240;

	public const WHO = ['registered', 'commenters'];

	/** @var ReactionStoreInterface */
	private $store;

	public function __construct(ReactionStoreInterface $store)
	{
		$this->store = $store;
	}

	/**
	 * Opciones del componente, normalizadas (cualquier valor ajeno vuelve al valor por defecto).
	 *
	 * @param   callable|array  $get  array clave => valor, o funcion (clave, defecto) => valor
	 *
	 * @return array{enabled:bool,dislike:bool,favorites:bool,who:string}
	 */
	public static function options($get): array
	{
		$read = static function (string $k, $def) use ($get) {
			return is_callable($get) ? $get($k, $def) : ($get[$k] ?? $def);
		};
		$bool = static function ($v, bool $def): bool {
			if (is_bool($v)) { return $v; }
			if (is_int($v) || (is_string($v) && preg_match('/^[01]$/D', $v))) { return (int) $v === 1; }

			return $def;
		};
		$who = $read('reactions_who', 'registered');

		return [
			'enabled'   => $bool($read('reactions_enabled', 1), true),
			'dislike'   => $bool($read('reactions_dislike', 1), true),
			'favorites' => $bool($read('reactions_favorites', 1), true),
			'who'       => (is_string($who) && in_array($who, self::WHO, true)) ? $who : 'registered',
		];
	}

	/** Identificador de comentario: solo enteros positivos en decimal (int o cadena de cifras). 0 = no valido. */
	public static function parseId($v): int
	{
		if (is_int($v)) { return $v > 0 ? $v : 0; }
		if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,17}$/D', $v)) { return 0; }

		return (int) $v;
	}

	/**
	 * Lista de identificadores "1,2,3". Devuelve null si algo no es valido o hay mas de MAX_IDS (la peticion se rechaza entera).
	 *
	 * @return int[]|null
	 */
	public static function parseIds($v): ?array
	{
		if (!is_string($v) || $v === '' || strlen($v) > 2200) { return null; }

		$out = [];

		foreach (explode(',', $v) as $p)
		{
			$id = self::parseId($p);

			if ($id === 0) { return null; }

			$out[$id] = $id;
		}

		return (count($out) > self::MAX_IDS) ? null : array_values($out);
	}

	/**
	 * ¿Es «propio» el comentario del usuario de la sesion? (0.7.1) Lo es si lo escribio con su cuenta (created_by) O si es un comentario de
	 * INVITADO (created_by vacio) con el email de la cuenta con sesion: ese es el criterio con el que el plugin user/engage se lo asigna al
	 * iniciar sesion (ownComments), asi que el servidor lo aplica igual desde ya. El email se compara sin distinguir mayusculas.
	 *
	 * @param   array{created_by:int,email?:string}  $comment
	 */
	public static function isOwn(array $comment, int $userId, string $userEmail): bool
	{
		if ($userId <= 0) { return false; }

		if ((int) $comment['created_by'] === $userId) { return true; }

		$email = trim((string) ($comment['email'] ?? ''));
		$mine  = trim($userEmail);

		return (int) $comment['created_by'] <= 0 && $email !== '' && $mine !== '' && strcasecmp($email, $mine) === 0;
	}

	/** Tipo a partir de su nombre ("like", "dislike", "favorite"). 0 = no valido. */
	public static function parseType($v): int
	{
		return (is_string($v) && isset(self::TYPES[$v])) ? self::TYPES[$v] : 0;
	}

	/**
	 * Alterna una reaccion.
	 *
	 * @param   array  $ctx  userId:int, canReact:bool, opts:array (options()), canViewAsset:callable(int):bool,
	 *                       allowRate:callable(int):bool, now:string (Y-m-d H:i:s UTC)
	 *
	 * @return array{status:int,body:array}
	 */
	public function toggle(array $ctx, $commentId, $type): array
	{
		$opts = $ctx['opts'];
		$uid  = (int) ($ctx['userId'] ?? 0);

		if (!$opts['enabled']) { return self::fail(404, 'unavailable'); }
		if ($uid <= 0 || empty($ctx['canReact'])) { return self::fail(403, 'denied'); }

		$id = self::parseId($commentId);
		$t  = self::parseType($type);

		if ($id === 0 || $t === 0) { return self::fail(400, 'invalid'); }

		if (($t === self::DISLIKE && !$opts['dislike']) || ($t === self::FAVORITE && !$opts['favorites'])) { return self::fail(400, 'invalid'); }

		if (!($ctx['allowRate'])($uid)) { return self::fail(429, 'rate'); }

		try
		{
			$c = $this->store->comments([$id])[$id] ?? null;

			// Inexistente, sin publicar o de un contenido que no puede ver: la misma respuesta
			if ($c === null || (int) $c['enabled'] !== 1 || !($ctx['canViewAsset'])((int) $c['asset_id'])) { return self::fail(404, 'unavailable'); }

			if ($t !== self::FAVORITE && self::isOwn($c, $uid, (string) ($ctx['userEmail'] ?? ''))) { return self::fail(403, 'denied'); }

			$gone = false;

			$this->store->transaction(function () use ($id, $uid, $t, $ctx, &$gone): void {
				// 0.7.1: reacciones del mismo comentario, una detras de otra. Sin esto, con READ COMMITTED (sin bloqueos de hueco) un me gusta
				// y un no me gusta simultaneos del mismo usuario no se veian y quedaban los dos.
				if (!$this->store->lockComment($id)) { $gone = true; return; }

				$has = in_array($t, $this->store->userTypes($uid, [$id])[$id] ?? [], true);

				if ($has)
				{
					$this->store->remove($id, $uid, $t);

					return;
				}

				if ($t === self::LIKE) { $this->store->remove($id, $uid, self::DISLIKE); }
				if ($t === self::DISLIKE) { $this->store->remove($id, $uid, self::LIKE); }

				$this->store->add($id, $uid, $t, (string) $ctx['now']);
			});

			if ($gone) { return self::fail(404, 'unavailable'); }

			$mine   = $this->store->userTypes($uid, [$id])[$id] ?? [];
			$counts = $this->store->counts([$id])[$id] ?? [];

			// 0.7.1: en el comentario propio no se devuelve ningun me gusta / no me gusta activo
			if (self::isOwn($c, $uid, (string) ($ctx['userEmail'] ?? ''))) { $mine = array_values(array_diff($mine, [self::LIKE, self::DISLIKE])); }
		}
		catch (Throwable $e)
		{
			return self::fail(500, 'failed');
		}

		return ['status' => 200, 'body' => [
			'ok'     => true,
			'id'     => $id,
			'mine'   => self::mine($mine, $opts['favorites']),
			// 0.7.1: con «No me gusta» desactivado tampoco se revela su contador (igual que en state)
			'counts' => ['like' => (int) ($counts[self::LIKE] ?? 0), 'dislike' => $opts['dislike'] ? (int) ($counts[self::DISLIKE] ?? 0) : 0],
		]];
	}

	/**
	 * Contadores y estado propio de una lista acotada de comentarios. Solo lectura. Los comentarios que no existen, no estan
	 * publicados o no se pueden ver se OMITEN (sin pistas). Nunca incluye quien reacciono ni el favorito de otros.
	 *
	 * @param   array  $ctx  userId, canReact, opts, canViewAsset
	 *
	 * @return array{status:int,body:array}
	 */
	public function state(array $ctx, $idsRaw): array
	{
		$opts = $ctx['opts'];

		if (!$opts['enabled']) { return ['status' => 200, 'body' => ['ok' => true, 'enabled' => false]]; }

		$ids = self::parseIds($idsRaw);

		if ($ids === null) { return self::fail(400, 'invalid'); }

		// 0.7.1: limite de frecuencia de las consultas (por usuario o por IP). Una peticion invalida de arriba no gasta cupo.
		if (isset($ctx['allowRead']) && !($ctx['allowRead'])()) { return self::fail(429, 'rate'); }

		$uid   = (int) ($ctx['userId'] ?? 0);
		$email = (string) ($ctx['userEmail'] ?? '');

		try
		{
			$comments = $this->store->comments($ids);
			$visible  = [];
			$assets   = [];

			// 0.7.1: la pagina real pide comentarios de UN contenido. Una consulta que mezcla mas de MAX_ASSETS se rechaza entera y barata,
			// antes de comprobar el permiso de cada uno (cada comprobacion cuesta varias consultas a la base de datos).
			if (count(array_unique(array_map(static fn(array $c): int => (int) $c['asset_id'], $comments))) > self::MAX_ASSETS) { return self::fail(400, 'invalid'); }

			foreach ($comments as $cid => $c)
			{
				if ((int) $c['enabled'] !== 1) { continue; }

				$a = (int) $c['asset_id'];
				$assets[$a] = $assets[$a] ?? (bool) ($ctx['canViewAsset'])($a);

				if ($assets[$a]) { $visible[] = (int) $cid; }
			}

			$counts = $visible ? $this->store->counts($visible) : [];
			$mine   = ($uid > 0 && $visible) ? $this->store->userTypes($uid, $visible) : [];
		}
		catch (Throwable $e)
		{
			return self::fail(500, 'failed');
		}

		$items = [];

		foreach ($visible as $cid)
		{
			$item = ['like' => (int) ($counts[$cid][self::LIKE] ?? 0), 'dislike' => $opts['dislike'] ? (int) ($counts[$cid][self::DISLIKE] ?? 0) : 0];

			if ($uid > 0)
			{
				$own = self::isOwn($comments[$cid], $uid, $email);

				// 0.7.1: en el comentario propio no hay me gusta / no me gusta activos (un resto antiguo no se muestra); el favorito si
				$types        = $own ? array_values(array_diff($mine[$cid] ?? [], [self::LIKE, self::DISLIKE])) : ($mine[$cid] ?? []);
				$item['mine'] = self::mine($types, $opts['favorites']);
				$item['own']  = $own;
			}

			$items[(string) $cid] = $item;
		}

		return ['status' => 200, 'body' => [
			'ok'      => true,
			'enabled' => true,
			'auth'    => $uid > 0,
			'can'     => ($uid > 0) && !empty($ctx['canReact']),
			'who'     => $opts['who'],
			'items'   => (object) $items,
		]];
	}

	/** @return array{like:bool,dislike:bool,favorite:bool}  Con los favoritos desactivados nunca se devuelve ninguno (ni el guardado antes). */
	private static function mine(array $types, bool $favorites = true): array
	{
		return [
			'like'     => in_array(self::LIKE, $types, true),
			'dislike'  => in_array(self::DISLIKE, $types, true),
			'favorite' => $favorites && in_array(self::FAVORITE, $types, true),
		];
	}

	/** @return array{status:int,body:array} */
	private static function fail(int $status, string $error): array
	{
		return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
	}
}
