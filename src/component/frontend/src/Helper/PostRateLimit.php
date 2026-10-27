<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Helper;

defined('_JEXEC') or die();

use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Frequency limiter of comment.save (0.9.12). It counts the comments already stored (any state: published, unpublished, spam) by the same user or from the
 * same IP in the last WINDOW seconds, with ONE bound query. The values are generous (a real person never reaches them). Fail-closed: if the count cannot be
 * made, the answer is «limited».
 */
final class PostRateLimit
{
	/** Window, in seconds. */
	public const WINDOW = 600;

	/** Maximum comments per registered user in the window. */
	public const MAX_PER_USER = 15;

	/** Maximum comments per IP address in the window (higher: offices and mobile networks share addresses). */
	public const MAX_PER_IP = 30;

	/**
	 * Pure decision: are these counts over the limit?
	 */
	public static function exceeded(int $byUser, int $byIp, bool $guest): bool
	{
		return $byIp >= self::MAX_PER_IP || (!$guest && $byUser >= self::MAX_PER_USER);
	}

	/**
	 * May this visitor post a new comment now?
	 *
	 * @param   DatabaseInterface  $db
	 * @param   int                $userId  0 for guests
	 * @param   string|null        $ip
	 * @param   int|null           $now     Unix time (tests)
	 */
	public static function allowed(DatabaseInterface $db, int $userId, ?string $ip, ?int $now = null): bool
	{
		try
		{
			$since  = (new Date(($now ?? time()) - self::WINDOW))->toSql();
			$ip     = (string) $ip;
			$guest  = $userId <= 0;
			$byUser = 0;
			$byIp   = 0;

			if (!$guest)
			{
				$q = $db->getQuery(true)
					->select('COUNT(*)')
					->from($db->quoteName('#__engage_comments'))
					->where($db->quoteName('created_by') . ' = :uid')
					->where($db->quoteName('created') . ' >= :since')
					->bind(':uid', $userId, ParameterType::INTEGER)
					->bind(':since', $since, ParameterType::STRING);
				$byUser = (int) $db->setQuery($q)->loadResult();
			}

			if ($ip !== '')
			{
				$q = $db->getQuery(true)
					->select('COUNT(*)')
					->from($db->quoteName('#__engage_comments'))
					->where($db->quoteName('ip') . ' = :ip')
					->where($db->quoteName('created') . ' >= :since')
					->bind(':ip', $ip, ParameterType::STRING)
					->bind(':since', $since, ParameterType::STRING);
				$byIp = (int) $db->setQuery($q)->loadResult();
			}

			return !self::exceeded($byUser, $byIp, $guest);
		}
		catch (Throwable $e)
		{
			return false;
		}
	}
}
