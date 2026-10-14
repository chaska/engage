<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Rule;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\AdminOnlyFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;
use SimpleXMLElement;

/**
 * Validation rule (`validate="adminonly"`) of the options that need core.admin (0.9.1). Joomla's form filter skips the fields that arrive blank or
 * do not arrive, and the options form saves EXACTLY what it receives (the parameters that are missing are deleted), so the filter alone cannot stop
 * a user without core.admin from clearing a sensitive option. This rule runs for every field, present or not: a value different from the stored one
 * (blank or missing included) from someone who may not change it rejects the whole save (the form validation fails).
 *
 * @since 0.9.1
 */
class AdminonlyRule extends FormRule
{
	public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
	{
		return AdminOnlyFilter::mayChange((string) $element['name'], $value, (string) $element['default']);
	}
}
