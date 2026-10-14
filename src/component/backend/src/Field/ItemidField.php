<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Field;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\CommentItems;
use Akeeba\Component\Engage\Administrator\Helper\CommentTypes;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Language\Text;

/**
 * 0.9.3: closed list of the content items that have comments (title and number of comments), restricted to the type chosen in the other filter.
 *
 * @since 0.9.3
 */
class ItemidField extends ListField
{
	protected $type = 'Itemid';

	protected function getOptions()
	{
		$options = parent::getOptions();
		$type    = '';

		try
		{
			$filter = Factory::getApplication()->getUserState('com_engage.comments.filter');
			$type   = CommentTypes::validate(is_array($filter) ? ($filter['itemtype'] ?? '') : '', CommentTypes::available());
		}
		catch (\Throwable $e)
		{
		}

		$list = CommentItems::available(null, $type);

		foreach ($list['items'] as $i)
		{
			$options[] = (object) ['value' => (string) $i['id'], 'text' => $i['title'] . ' (' . (int) $i['n'] . ')'];
		}

		if ($list['others'] > 0)
		{
			$options[] = (object) ['value' => CommentItems::OTHERS, 'text' => Text::_('COM_ENGAGE_COMMENTS_FILTER_ITEMID_OTHERS') . ' (' . (int) $list['others'] . ')'];
		}

		return $options;
	}
}
