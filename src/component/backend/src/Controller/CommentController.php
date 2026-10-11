<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\MVC\Controller\FormController;

class CommentController extends FormController
{
	use ControllerEventsTrait;

	/**
	 * 0.8.3: closed list of the tasks of the edit screen (the toolbar and the list use these); anything else (batch, loadhistory, runTransition, getModel...) answers 403 instead of a 500.
	 */
	protected function engageAllowedTasks(): ?array
	{
		return ['main', 'display', 'add', 'edit', 'cancel', 'save', 'apply', 'save2new', 'save2copy', 'reload'];
	}

	/** @inheritdoc */
	protected $text_prefix = 'COM_ENGAGE_COMMENT';

}