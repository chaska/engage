<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * 0.9.3: «Elemento» filter of the comments list of the administrator: the content items (an article, the sheet of a third party) that have comments,
 * most commented first, at most MAX_ITEMS of them plus «Others…». The titles come from `#__content.title` (articles) or `#__assets.title`; no third
 * party plugin is run to build the list. A selected value is only accepted if it is in that closed list.
 *
 * @since 0.9.3
 */
final class CommentItems
{
	public const MAX_ITEMS = 100;

	/** Value of the option that stands for «every item that is not in the list». */
	public const OTHERS = 'other';

	/** @var array<string,array>|null */
	private static $cache = [];

	/** Test hook: replaces the database rows [asset_id, n, name, atitle, ctitle]. */
	public static $rowsOverride = null;

	/**
	 * @param   string|null  $type  Restrict the list to a type (a value already validated against CommentTypes), or null/'' for all
	 *
	 * @return  array{items:array<int,array{id:int,n:int,title:string}>, others:int}  items sorted by number of comments; others = comments of the items that did not fit
	 */
	public static function available($db = null, ?string $type = null): array
	{
		$type = $type === null ? '' : $type;
		$key  = $type;

		if (!is_array(self::$rowsOverride) && isset(self::$cache[$key]))
		{
			return self::$cache[$key];
		}

		$rows  = [];
		$total = 0;

		try
		{
			if (is_array(self::$rowsOverride))
			{
				$rows = self::$rowsOverride;
			}
			else
			{
				$db = $db ?? \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
				$mk = static fn() => method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
				$q  = $mk()
					->select([
						$db->quoteName('c.asset_id'), 'COUNT(*) AS ' . $db->quoteName('n'), 'MAX(' . $db->quoteName('ast.name') . ') AS ' . $db->quoteName('name'),
						'MAX(' . $db->quoteName('ast.title') . ') AS ' . $db->quoteName('atitle'), 'MAX(' . $db->quoteName('ct.title') . ') AS ' . $db->quoteName('ctitle'),
					])
					->from($db->quoteName('#__engage_comments', 'c'))
					->join('LEFT', $db->quoteName('#__assets', 'ast'), $db->quoteName('ast.id') . ' = ' . $db->quoteName('c.asset_id'))
					->join('LEFT', $db->quoteName('#__content', 'ct'), $db->quoteName('ct.asset_id') . ' = ' . $db->quoteName('c.asset_id'))
					->group($db->quoteName('c.asset_id'))
					->order('COUNT(*) DESC, ' . $db->quoteName('c.asset_id') . ' ASC')
					->setLimit(self::MAX_ITEMS + 1);

				if ($type !== '')
				{
					$pattern = CommentTypes::likePattern($type);
					$q->where($db->quoteName('ast.name') . ' LIKE :tp')->bind(':tp', $pattern, \Joomla\Database\ParameterType::STRING);
				}

				$rows = $db->setQuery($q)->loadAssocList() ?: [];

				$tq = $mk()->select('COUNT(*)')->from($db->quoteName('#__engage_comments', 'c'))
					->join('LEFT', $db->quoteName('#__assets', 'ast'), $db->quoteName('ast.id') . ' = ' . $db->quoteName('c.asset_id'));

				if ($type !== '')
				{
					$tq->where($db->quoteName('ast.name') . ' LIKE :tp')->bind(':tp', $pattern, \Joomla\Database\ParameterType::STRING);
				}

				$total = (int) $db->setQuery($tq)->loadResult();
			}
		}
		catch (\Throwable $e)
		{
			$rows = [];
		}

		$out = self::build($rows, $total);

		if (!is_array(self::$rowsOverride))
		{
			self::$cache[$key] = $out;
		}

		return $out;
	}

	/**
	 * Pure part: the closed list from the rows of the query.
	 *
	 * @param   array  $rows   Rows [asset_id, n, name, atitle, ctitle], already ordered by n desc; at most MAX_ITEMS + 1 are read
	 * @param   int    $total  Total number of comments in the scope (0 = unknown: «others» is then the sum of the extra row)
	 */
	public static function build(array $rows, int $total = 0): array
	{
		$items = [];
		$sum   = 0;

		foreach (array_slice($rows, 0, self::MAX_ITEMS) as $r)
		{
			$id = (int) ($r['asset_id'] ?? 0);

			if ($id <= 0)
			{
				continue;
			}

			$n       = (int) ($r['n'] ?? 0);
			$sum    += $n;
			$items[] = ['id' => $id, 'n' => $n, 'title' => self::title($r, $id)];
		}

		$others = count($rows) > self::MAX_ITEMS ? max(1, ($total > 0 ? $total - $sum : (int) ($rows[self::MAX_ITEMS]['n'] ?? 1))) : 0;

		return ['items' => $items, 'others' => $others];
	}

	/** Title of an item: article title, else the title of the asset, else its name, else «asset N»; plain text, at most 80 characters. */
	public static function title(array $row, int $id): string
	{
		foreach (['ctitle', 'atitle', 'name'] as $k)
		{
			$t = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags((string) ($row[$k] ?? ''))));

			if ($t !== '')
			{
				return mb_strlen($t) > 80 ? mb_substr($t, 0, 79) . '…' : $t;
			}
		}

		return 'asset ' . $id;
	}

	/** The value of the filter if it is in the closed list (an asset id as a string, or OTHERS), else ''. */
	public static function validate($value, array $list): string
	{
		if (!is_string($value) && !is_int($value))
		{
			return '';
		}

		$value = (string) $value;

		if ($value === self::OTHERS)
		{
			return $list['others'] > 0 ? self::OTHERS : '';
		}

		if (preg_match('/^[1-9][0-9]{0,11}$/', $value) !== 1)
		{
			return '';
		}

		foreach ($list['items'] as $i)
		{
			if ((string) $i['id'] === $value)
			{
				return $value;
			}
		}

		return '';
	}
}
