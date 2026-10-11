<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Controller\Mixin\CustomACL;
use Akeeba\Component\Engage\Administrator\Helper\TemplateEmails;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use RuntimeException;

class EmailtemplatesController extends BaseController
{
	use ControllerEventsTrait;

	/** 0.8.3: closed list of tasks. */
	protected function engageAllowedTasks(): ?array
	{
		return ['main', 'display', 'updateemails', 'resetemails'];
	}

	/**
	 * 0.8.3: these tasks rewrite the system's mail templates (#__mail_templates). The CSRF token is not authority: the user also needs
	 * `core.admin` on com_engage and `core.edit` on com_mails (a Manager who only has `core.manage` can open the screen but not do this).
	 *
	 * @throws  RuntimeException  403
	 */
	private function assertMayChangeTemplates(): void
	{
		$user = UserFetcher::getUser();

		if ($user === null || $user->guest || !$user->authorise('core.admin', 'com_engage') || !$user->authorise('core.edit', 'com_mails'))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}

	public function updateEmails($cachable = false, $urlparams = [])
	{
		$this->assertMayChangeTemplates();
		$this->checkToken('get');

		$returnURL = Route::_('index.php?option=com_engage&view=Emailtemplates', false);
		$this->setRedirect($returnURL);

		$affected = TemplateEmails::updateAllTemplates();

		$message = ($affected > 0) ?
			Text::plural('COM_ENGAGE_EMAILTEMPLATES_LBL_N_UPDATED', $affected) :
			Text::_('COM_ENGAGE_EMAILTEMPLATES_ERR_NOUPDATE');

		$this->setMessage($message, ($affected > 0) ? 'success' : 'warning');
	}

	public function resetEmails($cachable = false, $urlparams = [])
	{
		$this->assertMayChangeTemplates();
		$this->checkToken('get');

		$returnURL = Route::_('index.php?option=com_engage&view=Emailtemplates', false);
		$this->setRedirect($returnURL);

		$affected = TemplateEmails::resetAllTemplates();

		$message = ($affected > 0) ?
			Text::plural('COM_ENGAGE_EMAILTEMPLATES_LBL_N_RESET', $affected) :
			Text::_('COM_ADMINTOOLS_EMAILTEMPLATES_ERR_RESET');

		$this->setMessage($message, ($affected > 0) ? 'success' : 'error');
	}

}