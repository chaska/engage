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

	/** Maximum number of distinct assets read to build the list of types (the list is a convenience, not a security boundary). */
	public const MAX_ASSETS = 5000;

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

	/**
	 * Types really used by the comments (read once per request).
	 *
	 * @return  array<string,string>
	 */
	public static function available($db = null): array
	{
		if (is_array(self::$namesOverride))
		{
			return self::fromNames(self::$namesOverride);
		}

		if (self::$cache !== null)
		{
			return self::$cache;
		}

		try
		{
			$db    = $db ?? \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select('DISTINCT ' . $db->quoteName('a.name'))
				->from($db->quoteName('#__engage_comments', 'c'))
				->join('INNER', $db->quoteName('#__assets', 'a'), $db->quoteName('a.id') . ' = ' . $db->quoteName('c.asset_id'))
				->setLimit(self::MAX_ASSETS);

			self::$cache = self::fromNames($db->setQuery($query)->loadColumn() ?: []);
		}
		catch (\Throwable $e)
		{
			self::$cache = [];
		}

		return self::$cache;
	}

	/** Language key of the label of a type. */
	public static function isArticle(string $type): bool
	{
		return $type === self::ARTICLE;
	}
}
