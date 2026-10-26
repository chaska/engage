<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Field;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\AvatarFieldList;
use Joomla\CMS\Form\Field\ListField;

/**
 * 0.9.8: closed list «None» + the user custom fields that can carry a photo (option customfield_avatar). Titles are escaped by Joomla's select.
 *
 * @since 0.9.8
 */
class UserfieldavatarField extends ListField
{
	protected $type = 'Userfieldavatar';

	protected function getOptions()
	{
		$options = parent::getOptions();

		foreach (AvatarFieldList::available() as $id => $title)
		{
			$options[] = (object) ['value' => (string) $id, 'text' => $title];
		}

		return $options;
	}
}
