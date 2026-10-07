<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.24): recoge de la base de datos y de Joomla los datos del panel de control. Solo LECTURA, con el
 * constructor de consultas de Joomla y parametros enlazados. Cero conexiones externas. Si una consulta falla devuelve
 * valores vacios: el panel nunca debe romper la pantalla de entrada del componente.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use DateTimeImmutable;
use DateTimeZone;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

final class PanelData
{
	/** Version del fork segun el CHANGELOG (la prueba tests/28 comprueba que coincide con su primera entrada). */
	public const FORK_VERSION = '0.7.1';

	public const REPO_URL      = 'https://github.com/chaska/engage';
	public const CHANGELOG_URL = 'https://github.com/chaska/engage/blob/main/CHANGELOG.md';
	public const DOCS_URL      = 'https://github.com/chaska/engage/blob/main/docs/PANEL.md';
	public const UPSTREAM_URL  = 'https://www.akeeba.com';

	private static function query(DatabaseInterface $db)
	{
		return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
	}

	/**
	 * Numeros, serie de 30 dias y articulos mas comentados.
	 *
	 * @return array{total:int,published:int,pending:int,spam:int,last7:int,last30:int,series:array,top:array}
	 */
	public static function stats(DatabaseInterface $db, ?DateTimeImmutable $now = null): array
	{
		$now   = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
		$out   = ['total' => 0, 'published' => 0, 'pending' => 0, 'spam' => 0, 'last7' => 0, 'last30' => 0, 'series' => [], 'top' => []];
		$d7    = $now->modify('-7 days')->format('Y-m-d H:i:s');
		$d30   = $now->modify('-30 days')->format('Y-m-d H:i:s');
		$since = $now->setTime(0, 0, 0)->modify('-29 days')->format('Y-m-d H:i:s');

		try
		{
			$q = self::query($db);
			$q->select([
				'COUNT(*) AS ' . $db->quoteName('total'),
				'COALESCE(SUM(CASE WHEN ' . $db->quoteName('c.enabled') . ' = 1 THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('published'),
				'COALESCE(SUM(CASE WHEN ' . $db->quoteName('c.enabled') . ' = 0 THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('pending'),
				'COALESCE(SUM(CASE WHEN ' . $db->quoteName('c.enabled') . ' = -3 THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('spam'),
				'COALESCE(SUM(CASE WHEN ' . $db->quoteName('c.created') . ' >= :d7 THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('last7'),
				'COALESCE(SUM(CASE WHEN ' . $db->quoteName('c.created') . ' >= :d30 THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('last30'),
			])
				->from($db->quoteName('#__engage_comments', 'c'))
				->bind(':d7', $d7, ParameterType::STRING)
				->bind(':d30', $d30, ParameterType::STRING);
			$row = $db->setQuery($q)->loadAssoc() ?: [];

			foreach (['total', 'published', 'pending', 'spam', 'last7', 'last30'] as $k)
			{
				$out[$k] = (int) ($row[$k] ?? 0);
			}
		}
		catch (Throwable $e)
		{
			// Panel sin numeros, pero vivo
		}

		$rows = [];

		try
		{
			$q = self::query($db);
			$q->select([
				'DATE(' . $db->quoteName('c.created') . ') AS ' . $db->quoteName('d'),
				'COUNT(*) AS ' . $db->quoteName('n'),
			])
				->from($db->quoteName('#__engage_comments', 'c'))
				->where($db->quoteName('c.created') . ' >= :since')
				->group('DATE(' . $db->quoteName('c.created') . ')')
				->bind(':since', $since, ParameterType::STRING);
			$rows = $db->setQuery($q)->loadAssocList() ?: [];
		}
		catch (Throwable $e)
		{
			$rows = [];
		}

		$out['series'] = PanelChart::buildSeries($rows, $now, 30);

		try
		{
			$q = self::query($db);
			$q->select([
				$db->quoteName('c.asset_id', 'asset_id'),
				'COUNT(' . $db->quoteName('c.id') . ') AS ' . $db->quoteName('n'),
				$db->quoteName('a.id', 'article_id'),
				$db->quoteName('a.title', 'title'),
			])
				->from($db->quoteName('#__engage_comments', 'c'))
				->join('LEFT', $db->quoteName('#__content', 'a'), $db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id'))
				->where($db->quoteName('c.enabled') . ' = 1')
				->group([$db->quoteName('c.asset_id'), $db->quoteName('a.id'), $db->quoteName('a.title')])
				->order($db->quoteName('n') . ' DESC')
				->order($db->quoteName('c.asset_id') . ' ASC')
				->setLimit(5, 0);
			$out['top'] = array_map(static fn(array $r): array => [
				'asset_id'   => (int) $r['asset_id'],
				'n'          => (int) $r['n'],
				'article_id' => (int) ($r['article_id'] ?? 0),
				'title'      => (string) ($r['title'] ?? ''),
			], $db->setQuery($q)->loadAssocList() ?: []);
		}
		catch (Throwable $e)
		{
			$out['top'] = [];
		}

		return $out;
	}

	/**
	 * Reacciones (0.7.0): totales de «me gusta» y «no me gusta» de los comentarios PUBLICADOS y los 5 mejor valorados (me gusta menos
	 * no me gusta, solo con puntuacion positiva). Solo lectura, parametros enlazados, sin datos de quien reacciono ni favoritos (privados).
	 *
	 * @return array{likes:int,dislikes:int,top:array<int,array<string,mixed>>}
	 */
	public static function reactions(DatabaseInterface $db, int $limit = 5): array
	{
		$out   = ['likes' => 0, 'dislikes' => 0, 'top' => []];
		$limit = max(1, min(20, $limit));
		$tLike = Reactions::LIKE;
		$tDis  = Reactions::DISLIKE;

		try
		{
			$q = self::query($db);
			$q->select([
				$db->quoteName('r.type', 'type'),
				'COUNT(*) AS ' . $db->quoteName('n'),
			])
				->from($db->quoteName(ReactionStore::TABLE, 'r'))
				->join('INNER', $db->quoteName('#__engage_comments', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('r.comment_id'))
				->where($db->quoteName('c.enabled') . ' = 1')
				->whereIn($db->quoteName('r.type'), [$tLike, $tDis], ParameterType::INTEGER)
				->group($db->quoteName('r.type'));

			foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
			{
				$out[((int) $r['type'] === $tLike) ? 'likes' : 'dislikes'] = (int) $r['n'];
			}
		}
		catch (Throwable $e)
		{
			// Panel sin esos numeros, pero vivo
		}

		try
		{
			$a1 = $tLike;
			$a2 = $tDis;
			$b1 = $tLike;
			$b2 = $tDis;
			$q  = self::query($db);
			$q->select([
				$db->quoteName('r.comment_id', 'comment_id'),
				'SUM(CASE WHEN ' . $db->quoteName('r.type') . ' = :a1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('likes'),
				'SUM(CASE WHEN ' . $db->quoteName('r.type') . ' = :a2 THEN 1 ELSE 0 END) AS ' . $db->quoteName('dislikes'),
				'SUM(CASE WHEN ' . $db->quoteName('r.type') . ' = :b1 THEN 1 WHEN ' . $db->quoteName('r.type') . ' = :b2 THEN -1 ELSE 0 END) AS ' . $db->quoteName('score'),
			])
				->from($db->quoteName(ReactionStore::TABLE, 'r'))
				->join('INNER', $db->quoteName('#__engage_comments', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('r.comment_id'))
				->where($db->quoteName('c.enabled') . ' = 1')
				->whereIn($db->quoteName('r.type'), [$tLike, $tDis], ParameterType::INTEGER)
				->group($db->quoteName('r.comment_id'))
				->having($db->quoteName('score') . ' > 0')
				->order($db->quoteName('score') . ' DESC')
				->order($db->quoteName('likes') . ' DESC')
				->order($db->quoteName('r.comment_id') . ' ASC')
				->bind(':a1', $a1, ParameterType::INTEGER)
				->bind(':a2', $a2, ParameterType::INTEGER)
				->bind(':b1', $b1, ParameterType::INTEGER)
				->bind(':b2', $b2, ParameterType::INTEGER)
				->setLimit($limit, 0);
			$ranked = $db->setQuery($q)->loadAssocList() ?: [];
		}
		catch (Throwable $e)
		{
			$ranked = [];
		}

		if (!$ranked)
		{
			return $out;
		}

		try
		{
			$ids = array_map(static fn(array $r): int => (int) $r['comment_id'], $ranked);
			$q   = self::query($db);
			$q->select([
				$db->quoteName('c.id', 'id'),
				$db->quoteName('c.name', 'name'),
				$db->quoteName('c.body', 'body'),
				$db->quoteName('c.asset_id', 'asset_id'),
				$db->quoteName('u.name', 'user_name'),
				$db->quoteName('a.title', 'title'),
			])
				->from($db->quoteName('#__engage_comments', 'c'))
				->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('c.created_by'))
				->join('LEFT', $db->quoteName('#__content', 'a'), $db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id'))
				->whereIn($db->quoteName('c.id'), $ids, ParameterType::INTEGER);
			$by = [];

			foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
			{
				$by[(int) $r['id']] = $r;
			}

			foreach ($ranked as $r)
			{
				$c = $by[(int) $r['comment_id']] ?? null;

				if ($c === null)
				{
					continue;
				}

				$author        = trim((string) ($c['user_name'] ?? '')) !== '' ? (string) $c['user_name'] : (string) ($c['name'] ?? '');
				$out['top'][] = [
					'id'       => (int) $c['id'],
					'author'   => $author,
					'excerpt'  => self::excerpt((string) ($c['body'] ?? ''), 90),
					'asset_id' => (int) $c['asset_id'],
					'title'    => (string) ($c['title'] ?? ''),
					'likes'    => (int) $r['likes'],
					'dislikes' => (int) $r['dislikes'],
					'score'    => (int) $r['score'],
				];
			}
		}
		catch (Throwable $e)
		{
			$out['top'] = [];
		}

		return $out;
	}

	/**
	 * Ultimos comentarios (los mas recientes, de cualquier estado) con su articulo.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function latest(DatabaseInterface $db, int $limit = 6): array
	{
		$limit = max(1, min(20, $limit));

		try
		{
			$q = self::query($db);
			$q->select([
				$db->quoteName('c.id', 'id'),
				$db->quoteName('c.name', 'name'),
				$db->quoteName('c.body', 'body'),
				$db->quoteName('c.enabled', 'enabled'),
				$db->quoteName('c.created', 'created'),
				$db->quoteName('c.asset_id', 'asset_id'),
				$db->quoteName('u.name', 'user_name'),
				$db->quoteName('a.title', 'title'),
			])
				->from($db->quoteName('#__engage_comments', 'c'))
				->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('c.created_by'))
				->join('LEFT', $db->quoteName('#__content', 'a'), $db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id'))
				->order($db->quoteName('c.created') . ' DESC')
				->order($db->quoteName('c.id') . ' DESC')
				->setLimit($limit, 0);

			$rows = $db->setQuery($q)->loadAssocList() ?: [];
		}
		catch (Throwable $e)
		{
			return [];
		}

		return array_map(static function (array $r): array {
			$author = trim((string) ($r['user_name'] ?? '')) !== '' ? (string) $r['user_name'] : (string) ($r['name'] ?? '');

			return [
				'id'       => (int) $r['id'],
				'author'   => $author,
				'excerpt'  => self::excerpt((string) ($r['body'] ?? ''), 140),
				'enabled'  => (int) $r['enabled'],
				'created'  => (string) ($r['created'] ?? ''),
				'asset_id' => (int) $r['asset_id'],
				'title'    => (string) ($r['title'] ?? ''),
			];
		}, $rows);
	}

	/** Texto plano y corto de un comentario (sin etiquetas; el escapado lo hace la plantilla). */
	public static function excerpt(string $body, int $max = 140): string
	{
		$text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string) preg_replace('/\s+/u', ' ', $text));
		$text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

		if (mb_strlen($text) > $max)
		{
			$text = rtrim(mb_substr($text, 0, $max - 1)) . '…';
		}

		return $text;
	}

	/** Hechos para PanelHealth::evaluate(). Solo lecturas. */
	public static function facts(DatabaseInterface $db, User $user): array
	{
		$params = ComponentHelper::getParams('com_engage')->toArray();
		$facts  = [
			'php'              => PHP_VERSION,
			'db_version'       => '',
			'plugins'          => [],
			'params'           => $params,
			'gravatar'         => [],
			'akismet'          => [],
			'captcha_effective' => '',
			'captcha_enabled'  => false,
			'purifier_cache'   => null,
			'update_sites'     => [],
			'can_edit_plugins' => $user->authorise('core.manage', 'com_plugins') && $user->authorise('core.edit', 'com_plugins'),
			'can_options'      => $user->authorise('core.admin', 'com_engage') || $user->authorise('core.options', 'com_engage'),
		];

		try
		{
			$facts['db_version'] = (string) $db->getVersion() . (method_exists($db, 'getServerType') ? ' ' . (string) $db->getServerType() : '');
		}
		catch (Throwable $e)
		{
		}

		// Plugins que importan: content/engage, engage/*, system/engagecache y system/cache
		try
		{
			$type = 'plugin';
			$fE   = 'engage';
			$fC   = 'content';
			$eC   = 'engage';
			$fS   = 'system';
			$eS1  = 'engagecache';
			$eS2  = 'cache';
			$q    = self::query($db);
			$q->select([$db->quoteName('extension_id'), $db->quoteName('folder'), $db->quoteName('element'), $db->quoteName('enabled'), $db->quoteName('params')])
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = :type')
				->where('(' . $db->quoteName('folder') . ' = :fe OR (' . $db->quoteName('folder') . ' = :fc AND ' . $db->quoteName('element') . ' = :ec) OR ('
					. $db->quoteName('folder') . ' = :fs AND ' . $db->quoteName('element') . ' IN (:es1, :es2)))')
				->bind(':type', $type, ParameterType::STRING)
				->bind(':fe', $fE, ParameterType::STRING)
				->bind(':fc', $fC, ParameterType::STRING)
				->bind(':ec', $eC, ParameterType::STRING)
				->bind(':fs', $fS, ParameterType::STRING)
				->bind(':es1', $eS1, ParameterType::STRING)
				->bind(':es2', $eS2, ParameterType::STRING);

			foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
			{
				$key                     = $r['folder'] . '/' . $r['element'];
				$facts['plugins'][$key] = ['exists' => true, 'enabled' => (int) $r['enabled'] === 1, 'id' => (int) $r['extension_id']];

				if ($key === 'engage/gravatar' || $key === 'engage/akismet')
				{
					$decoded = json_decode((string) $r['params'], true);
					$facts[$r['element']] = is_array($decoded) ? $decoded : [];
				}
			}
		}
		catch (Throwable $e)
		{
		}

		// Captcha efectivo: -1 = ninguno; vacio/0 = el global de Joomla; otro valor = ese plugin de captcha
		$captcha = (string) ($params['captcha'] ?? '0');

		if ($captcha === '' || $captcha === '0')
		{
			$captcha = (string) Factory::getApplication()->get('captcha', '0');
		}

		if ($captcha === '-1' || $captcha === '0' || $captcha === '')
		{
			$captcha = '';
		}

		$facts['captcha_effective'] = $captcha;

		if ($captcha !== '')
		{
			try
			{
				$type = 'plugin';
				$grp  = 'captcha';
				$q    = self::query($db);
				$q->select('COUNT(*)')
					->from($db->quoteName('#__extensions'))
					->where($db->quoteName('type') . ' = :type')
					->where($db->quoteName('folder') . ' = :grp')
					->where($db->quoteName('element') . ' = :el')
					->where($db->quoteName('enabled') . ' = 1')
					->bind(':type', $type, ParameterType::STRING)
					->bind(':grp', $grp, ParameterType::STRING)
					->bind(':el', $captcha, ParameterType::STRING);
				$facts['captcha_enabled'] = (int) $db->setQuery($q)->loadResult() > 0;
			}
			catch (Throwable $e)
			{
			}
		}

		// Carpeta de cache de HTML Purifier (la misma que usa HtmlFilter, sin crear nada mas que lo que ya crea el propio filtro)
		try
		{
			$facts['purifier_cache'] = HtmlFilter::getCachePath() !== null;
		}
		catch (Throwable $e)
		{
			$facts['purifier_cache'] = null;
		}

		// Sitios de actualizacion del paquete
		try
		{
			$el   = 'pkg_engage';
			$q    = self::query($db);
			$q->select([$db->quoteName('s.location'), $db->quoteName('s.enabled')])
				->from($db->quoteName('#__update_sites', 's'))
				->join('INNER', $db->quoteName('#__update_sites_extensions', 'x'), $db->quoteName('x.update_site_id') . ' = ' . $db->quoteName('s.update_site_id'))
				->join('INNER', $db->quoteName('#__extensions', 'e'), $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('x.extension_id'))
				->where($db->quoteName('e.element') . ' = :el')
				->bind(':el', $el, ParameterType::STRING);
			$facts['update_sites'] = $db->setQuery($q)->loadAssocList() ?: [];
		}
		catch (Throwable $e)
		{
		}

		return $facts;
	}

	/** Version instalada del paquete (manifest_cache), sin red. */
	public static function installedVersion(DatabaseInterface $db): string
	{
		try
		{
			$type = 'component';
			$el   = 'com_engage';
			$q    = self::query($db);
			$q->select($db->quoteName('manifest_cache'))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = :type')
				->where($db->quoteName('element') . ' = :el')
				->bind(':type', $type, ParameterType::STRING)
				->bind(':el', $el, ParameterType::STRING);
			$m = json_decode((string) $db->setQuery($q)->loadResult(), true);

			return is_array($m) ? (string) ($m['version'] ?? '') : '';
		}
		catch (Throwable $e)
		{
			return '';
		}
	}
}
