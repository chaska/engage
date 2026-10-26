<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * 0.9.8: the user custom fields (context com_users.user, published, of a type that can carry a photo) that the option «User field with the photo» offers.
 * One bound query; any error = no fields (the option then only offers «None»).
 */
final class AvatarFieldList
{
	/** @return array<string,string>  id => title */
	public static function available(?DatabaseInterface $db = null): array
	{
		try
		{
			$db  ??= Factory::getContainer()->get(DatabaseInterface::class);
			$ctx   = 'com_users.user';
			$types = AvatarField::TYPES;
			$q     = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
			$q->select([$db->quoteName('id'), $db->quoteName('title')])
				->from($db->quoteName('#__fields'))
				->where($db->quoteName('context') . ' = :ctx')
				->where($db->quoteName('state') . ' = 1')
				->whereIn($db->quoteName('type'), $types, ParameterType::STRING)
				->order($db->quoteName('title'))
				->bind(':ctx', $ctx, ParameterType::STRING);
			$out = [];

			foreach ($db->setQuery($q, 0, 200)->loadAssocList() ?: [] as $r)
			{
				$id = AvatarField::validId($r['id'] ?? '');

				if ($id !== '')
				{
					$out[$id] = trim(strip_tags((string) ($r['title'] ?? ''))) ?: ('#' . $id);
				}
			}

			return $out;
		}
		catch (Throwable $e)
		{
			return [];
		}
	}
}
