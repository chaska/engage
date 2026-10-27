<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\Database\ParameterType;

/**
 * Server-side values bound to a user account, kept in Joomla's `#__user_profiles` under keys of our own (0.9.13). No schema change. Only the server writes
 * them (the profile plugin of Joomla only handles keys `profile.%`; no form lets a user post arbitrary keys). Queries are bound.
 *
 * Keys in use: `engageadopt.pending` / `engageadopt.verified` (e-mail verified by activation, see the User plugin) and `engagegravatar.consent`
 * (consent to load Gravatar photos, see AvatarController).
 */
final class UserProfileKey
{
	public const ADOPT_PENDING   = 'engageadopt.pending';
	public const ADOPT_VERIFIED  = 'engageadopt.verified';
	public const GRAVATAR_CONSENT = 'engagegravatar.consent';

	/** The stored value (trimmed) or '' */
	public static function get($db, int $userId, string $key): string
	{
		if ($userId <= 0)
		{
			return '';
		}

		$q = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->select($db->quoteName('profile_value'))
			->from($db->quoteName('#__user_profiles'))
			->where($db->quoteName('user_id') . ' = :uid')
			->where($db->quoteName('profile_key') . ' = :k')
			->bind(':uid', $userId, ParameterType::INTEGER)
			->bind(':k', $key, ParameterType::STRING);

		return trim((string) $db->setQuery($q)->loadResult());
	}

	/** Stores the value (replacing the previous one); null deletes it. */
	public static function set($db, int $userId, string $key, ?string $value): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$d = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->delete($db->quoteName('#__user_profiles'))
			->where($db->quoteName('user_id') . ' = :uid')
			->where($db->quoteName('profile_key') . ' = :k')
			->bind(':uid', $userId, ParameterType::INTEGER)
			->bind(':k', $key, ParameterType::STRING);
		$db->setQuery($d)->execute();

		if ($value !== null)
		{
			$row = (object) ['user_id' => $userId, 'profile_key' => $key, 'profile_value' => $value, 'ordering' => 0];
			$db->insertObject('#__user_profiles', $row);
		}
	}
}
