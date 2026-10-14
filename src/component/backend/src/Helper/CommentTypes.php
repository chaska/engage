<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * 0.8.5: «Tipo de elemento» filter of the comments list of the administrator. The type of a comment is derived from the name of its asset
 * (`#__assets.name`: `com_content.article.12` -> `com_content.article`; a third party: `com_mapa.punto.5` -> `com_mapa.punto`). The filter only
 * accepts a value that is in the CLOSED list of the types that really exist in #__engage_comments; anything else is ignored. Pure functions
 * except for available().
 *
 * @since 0.8.5
 */
final class CommentTypes
{
	public const ARTICLE = 'com_content.article';

	/** @var array<string,string>|null */
	private static $cache = null;

	/** Test hook: replaces the list of asset names read from the database. */
	public static $namesOverride = null;

	/** `com_x.item.12` -> `com_x.item`; null when the name is not a plain `[A-Za-z0-9_.-]` name of at most 100 characters. */
	public static function fromAssetName($name): ?string
	{
		if (!is_string($name) || preg_match('/^[A-Za-z0-9_.\-]{1,100}$/D', $name) !== 1)
		{
			return null;
		}

		$type = preg_replace('/\.\d+$/', '', $name);

		return $type !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_\-]*(?:\.[A-Za-z0-9_\-]+)*$/D', $type) === 1 ? $type : null;
	}

	/**
	 * Closed list: type => type, sorted (articles first).
	 *
	 * @param   array<int,mixed>  $names  Asset names
	 *
	 * @return  array<string,string>
	 */
	public static function fromNames(array $names): array
	{
		$out = [];

		foreach ($names as $n)
		{
			$t = self::fromAssetName($n);

			if ($t !== null)
			{
				$out[$t] = $t;
			}
		}

		ksort($out);

		if (isset($out[self::ARTICLE]))
		{
			$out = [self::ARTICLE => self::ARTICLE] + $out;
		}

		return $out;
	}

	/** The value of the filter if it is one of the available types, else the empty string. */
	public static function validate($value, array $available): string
	{
		return is_string($value) && isset($available[$value]) ? $value : '';
	}

	/** Parameter of `a.name LIKE :x` for a validated type (LIKE wildcards escaped). */
	public static function likePattern(string $type): string
	{
		return addcslashes($type, '%_\\') . '.%';
	}

	/** Maximum number of different types that are discovered (a safety net: a site has a handful). */
	public const MAX_TYPES = 60;

	/** @var array<string,int>|null */
	private static $countCache = null;

	/**
	 * Types really used by the comments, with their number of comments, WITHOUT looking at every asset: each round asks for ONE asset name that does
	 * not belong to the types already found (and not to a name that cannot be a type), so a site with tens of thousands of assets costs as many
	 * small queries as it has types (0.9.3; before, a `SELECT DISTINCT` with a 5000 limit could lose types).
	 *
	 * @return  array<string,int>  type => number of comments (articles first, then by name)
	 */
	public static function counts($db = null): array
	{
		if (is_array(self::$namesOverride))
		{
			$out = [];

			foreach (self::$namesOverride as $n)
			{
				$t = self::fromAssetName($n);

				if ($t !== null)
				{
					$out[$t] = ($out[$t] ?? 0) + 1;
				}
			}

			return self::sortTypes($out);
		}

		if (self::$countCache !== null)
		{
			return self::$countCache;
		}

		try
		{
			$db  = $db ?? \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$mk  = static fn() => method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
			$out = [];
			$bad = [];

			for ($round = 0; $round < self::MAX_TYPES + 20; $round++)
			{
				$q = $mk()->select($db->quoteName('a.name'))
					->from($db->quoteName('#__engage_comments', 'c'))
					->join('INNER', $db->quoteName('#__assets', 'a'), $db->quoteName('a.id') . ' = ' . $db->quoteName('c.asset_id'));
				$binds = [];
				$i     = 0;

				foreach (array_keys($out) as $t)
				{
					$k = ':nt' . $i++;
					$binds[$k] = self::likePattern($t);
					$q->where($db->quoteName('a.name') . ' NOT LIKE ' . $k);
				}

				foreach ($bad as $n)
				{
					$k = ':nb' . $i++;
					$binds[$k] = $n;
					$q->where($db->quoteName('a.name') . ' <> ' . $k);
				}

				foreach ($binds as $k => &$v)
				{
					$q->bind($k, $v, \Joomla\Database\ParameterType::STRING);
				}

				unset($v);
				$q->setLimit(1);
				$name = $db->setQuery($q)->loadResult();

				if ($name === null || $name === false || $name === '')
				{
					break;
				}

				$type = self::fromAssetName((string) $name);

				if ($type === null)
				{
					$bad[] = (string) $name;

					continue;
				}

				$pattern = self::likePattern($type);
				$cq      = $mk()->select('COUNT(*)')
					->from($db->quoteName('#__engage_comments', 'c'))
					->join('INNER', $db->quoteName('#__assets', 'a'), $db->quoteName('a.id') . ' = ' . $db->quoteName('c.asset_id'))
					->where($db->quoteName('a.name') . ' LIKE :tp')
					->bind(':tp', $pattern, \Joomla\Database\ParameterType::STRING);
				$out[$type] = (int) $db->setQuery($cq)->loadResult();

				if (count($out) >= self::MAX_TYPES)
				{
					break;
				}
			}

			self::$countCache = self::sortTypes($out);
		}
		catch (\Throwable $e)
		{
			self::$countCache = [];
		}

		return self::$countCache;
	}

	/** Articles first, then by name. */
	public static function sortTypes(array $counts): array
	{
		ksort($counts);

		if (isset($counts[self::ARTICLE]))
		{
			$counts = [self::ARTICLE => $counts[self::ARTICLE]] + $counts;
		}

		return $counts;
	}

	/**
	 * Closed list: type => type.
	 *
	 * @return  array<string,string>
	 */
	public static function available($db = null): array
	{
		if (is_array(self::$namesOverride))
		{
			return self::fromNames(self::$namesOverride);
		}

		$out = [];

		foreach (array_keys(self::counts($db)) as $t)
		{
			$out[$t] = $t;
		}

		return $out;
	}

	/** Language key that names the COMPONENT of a type (`com_mapaviajes.trip.5` -> COM_ENGAGE_ITEMTYPE_COM_MAPAVIAJES); a site can translate it. */
	public static function componentLabelKey(string $type): string
	{
		$comp = strtoupper((string) strtok($type, '.'));

		return preg_match('/^[A-Z][A-Z0-9_]*$/', $comp) === 1 ? 'COM_ENGAGE_ITEMTYPE_' . $comp : '';
	}

	/**
	 * Friendly name of a type: «Articles» for com_content.article, the translation of the component when there is one, else the technical name.
	 *
	 * @param   callable  $tr  fn(string $key): string — returns the key itself when there is no translation (Joomla's Text::_ does)
	 */
	public static function label(string $type, callable $tr): string
	{
		if (self::isArticle($type))
		{
			return (string) $tr('COM_ENGAGE_COMMENTS_ITEMTYPE_ARTICLE');
		}

		$key = self::componentLabelKey($type);

		if ($key !== '')
		{
			$t = (string) $tr($key);

			if ($t !== '' && $t !== $key)
			{
				return $t;
			}
		}

		return sprintf((string) $tr('COM_ENGAGE_COMMENTS_ITEMTYPE_THIRDPARTY'), $type);
	}

	/** Language key of the label of a type. */
	public static function isArticle(string $type): bool
	{
		return $type === self::ARTICLE;
	}
}
