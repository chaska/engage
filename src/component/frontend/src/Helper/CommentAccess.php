<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Who may open the edit screen of a comment (0.8.3).
 *
 * Until 0.8.2 the screen `view=comment&id=N` (and every task that fell into the default one) showed the comment N to ANY visitor: text, name
 * and EMAIL ADDRESS of the author, also of unpublished, spam and restricted-content comments. Heredado de Akeeba Engage 3.4.2.
 *
 * The rule is one function and every answer is the same 403 whether the comment exists or not, so nothing tells an attacker which IDs exist.
 *
 * @since 0.8.3
 */
final class CommentAccess
{
	/**
	 * The comment ID of the request as a canonical integer. Arrays (`id[]=1`: Joomla's INT filter keeps them as arrays and `(int)` turns
	 * them into 1), signs, decimals, hexadecimal, spaces, a leading zero and absurd lengths are refused, not "interpreted".
	 *
	 * @param   object  $input  Anything with get($name, $default, $filter) (Joomla's Input)
	 *
	 * @throws  RuntimeException  403
	 */
	public static function requestedId(object $input): int
	{
		$raw = $input->get('id', 0, 'raw');

		if ($raw === null || $raw === '' || $raw === 0 || $raw === '0')
		{
			return 0;
		}

		if ((!is_string($raw) && !is_int($raw)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $raw))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		return (int) $raw;
	}

	/**
	 * May this user open the edit screen of the comment? ID 0 (a new comment) only when the session holds the data of a form that has just
	 * failed to save (that is the only way a page legitimately shows it: Joomla redirects there after a validation error); otherwise
	 * `core.edit`, or `core.edit.own` on their own comment. Fail-closed: any problem is "no".
	 *
	 * @param   bool           $hasFormData  the session holds the data of a form that failed to save
	 * @param   callable|null  $ownerOf      fn(int $id): ?int, the author of the comment (tests); by default one bound query
	 */
	public static function mayOpen(int $id, ?User $user, bool $hasFormData = false, ?callable $ownerOf = null): bool
	{
		if ($id === 0)
		{
			return $hasFormData;
		}

		if ($id < 0 || $user === null)
		{
			return false;
		}

		try
		{
			if ($user->authorise('core.edit', 'com_engage'))
			{
				return true;
			}

			if ($user->guest || (int) $user->id <= 0 || !$user->authorise('core.edit.own', 'com_engage'))
			{
				return false;
			}

			$owner = ($ownerOf ?? [self::class, 'ownerOf'])($id);

			return $owner !== null && (int) $owner > 0 && (int) $owner === (int) $user->id;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/** @internal */
	public static function ownerOf(int $id): ?int
	{
		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('created_by'))
			->from($db->quoteName('#__engage_comments'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);
		$owner = $db->setQuery($query)->loadResult();

		return ($owner === null || $owner === false) ? null : (int) $owner;
	}
}
