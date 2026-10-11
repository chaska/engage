<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Akeeba\Component\Engage\Administrator\Helper\TrustedBase;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

/**
 * Panel de control (pantalla de entrada del componente desde 0.6.24). Solo muestra datos: las acciones rapidas de la
 * lista de ultimos comentarios usan las tareas ya existentes de CommentsController (con su token y sus permisos).
 *
 * @since 0.6.24
 */
class ControlpanelController extends BaseController
{
	use ControllerEventsTrait;

	/** 0.8.3: closed list of tasks. */
	protected function engageAllowedTasks(): ?array
	{
		return ['main', 'display', 'forgetbase'];
	}

	/** @inheritdoc */
	protected $default_view = 'controlpanel';

	/**
	 * 0.8.4: forgets the base URL of the email links that Engage learned (POST + token + core.admin). It does not go back to the control panel: the
	 * next visit to it learns the address again, and learning from the very request that forgets would defeat the purpose.
	 */
	public function forgetbase(): void
	{
		$user = Factory::getApplication()->getIdentity();

		if (!Session::checkToken('post') || !$user || $user->guest || !$user->authorise('core.admin', 'com_engage'))
		{
			$this->setRedirect('index.php?option=com_engage&view=controlpanel', Text::_('JINVALID_TOKEN_NOTICE'), 'error');

			return;
		}

		try
		{
			TrustedBase::forgetLearned();
			$this->setRedirect('index.php', Text::_('COM_ENGAGE_PANEL_BASE_FORGOTTEN'), 'message');
		}
		catch (\Throwable $e)
		{
			$this->setRedirect('index.php?option=com_engage&view=controlpanel', Text::_('COM_ENGAGE_PANEL_BASE_FORGET_FAILED'), 'error');
		}
	}
}
