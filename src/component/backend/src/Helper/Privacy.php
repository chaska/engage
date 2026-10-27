<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\Database\ParameterType;
use Throwable;

/**
 * Privacy helpers (0.9.13): the retention of the IP address and the browser data of the comments, and the link to the privacy policy shown under the form.
 *
 * Retention: `retention_months` (1 to 12, default 12; there is no «forever»). After that time the IP address and the browser data of a comment are emptied
 * (the comment stays). The purge runs in batches, from the same periodic hook as the spam cleaning of the content plugin, with bound queries.
 */
final class Privacy
{
	public const MIN_MONTHS     = 1;
	public const MAX_MONTHS     = 12;
	public const DEFAULT_MONTHS = 12;

	/** Rows per batch and batches per run (a big backlog is finished by the following runs). */
	public const BATCH       = 500;
	public const MAX_BATCHES = 20;

	/** Valid number of months; anything else (not an integer, out of range) is the default (12): never longer than the maximum. */
	public static function months($raw): int
	{
		if (is_int($raw))
		{
			$v = $raw;
		}
		elseif (is_string($raw) && preg_match('/^\s*[0-9]{1,4}\s*$/', $raw) === 1)
		{
			$v = (int) trim($raw);
		}
		else
		{
			return self::DEFAULT_MONTHS;
		}

		return ($v >= self::MIN_MONTHS && $v <= self::MAX_MONTHS) ? $v : self::DEFAULT_MONTHS;
	}

	/** UTC date (SQL) before which the IP and the browser of a comment are emptied. */
	public static function cutoff(int $months, ?int $now = null): string
	{
		$d = new \DateTimeImmutable('@' . ($now ?? time()));

		return $d->modify('-' . self::months($months) . ' months')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/**
	 * Empties the IP address and the browser of the comments older than `$months` months. Returns how many comments were changed.
	 *
	 * @param   \Joomla\Database\DatabaseInterface  $db
	 */
	public static function purge($db, int $months, ?int $now = null, int $batch = self::BATCH, int $maxBatches = self::MAX_BATCHES): int
	{
		$cutoff = self::cutoff($months, $now);
		$total  = 0;

		for ($i = 0; $i < $maxBatches; $i++)
		{
			$sel = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select($db->quoteName('id'))
				->from($db->quoteName('#__engage_comments'))
				->where($db->quoteName('created') . ' < :cutoff')
				->where('(' . $db->quoteName('ip') . ' IS NOT NULL OR ' . $db->quoteName('user_agent') . " <> '')")
				->order($db->quoteName('id') . ' ASC')
				->bind(':cutoff', $cutoff, ParameterType::STRING);
			$ids = array_map('intval', $db->setQuery($sel, 0, $batch)->loadColumn() ?: []);

			if ($ids === [])
			{
				break;
			}

			$upd = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->update($db->quoteName('#__engage_comments'))
				->set($db->quoteName('ip') . ' = NULL')
				->set($db->quoteName('user_agent') . " = ''")
				->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER);
			$db->setQuery($upd)->execute();
			$total += count($ids);

			if (count($ids) < $batch)
			{
				break;
			}
		}

		return $total;
	}

	/**
	 * The link to the privacy policy, validated: '' (none), a path of this site that starts with ONE «/», or an https address. No spaces, quotes, angle
	 * brackets, backticks, backslashes or control characters; user information (user:pass@) is not allowed; at most 500 characters. Anything else is ''.
	 */
	public static function policyUrl($raw): string
	{
		if (!is_string($raw))
		{
			return '';
		}

		$u = trim($raw);

		if ($u === '' || strlen($u) > 500 || preg_match('#[\x00-\x20\x7f"\'<>`\\\\]#', $u) === 1 || !mb_check_encoding($u, 'UTF-8'))
		{
			return '';
		}

		if ($u[0] === '/')
		{
			return (isset($u[1]) && $u[1] === '/') ? '' : $u;
		}

		if (preg_match('#^https://([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*(:[0-9]{1,5})?([/?\#].*)?$#D', $u) !== 1)
		{
			return '';
		}

		return $u;
	}
}
