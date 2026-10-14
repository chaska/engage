<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Field;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\CommentTypes;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Language\Text;

/**
 * 0.8.5: closed list of the item types that really have comments (articles and third parties). The option texts are escaped by Joomla's select.
 *
 * @since 0.8.5
 */
class ItemtypeField extends ListField
{
	protected $type = 'Itemtype';

	protected function getOptions()
	{
		$options = parent::getOptions();

		foreach (CommentTypes::counts() as $type => $n)
		{
			$options[] = (object) [
				'value' => $type,
				'text'  => CommentTypes::label($type, static fn(string $k): string => Text::_($k)) . ' (' . (int) $n . ')',
			];
		}

		return $options;
	}
}
