<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\EmailPreview;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/**
 * Preview of the notification emails and test email (0.9.0). Everything needs core.admin on com_engage; sending needs POST + token and is limited
 * to one email per minute per session; the only recipient is the logged-in administrator.
 *
 * @since 0.9.0
 */
class EmailpreviewController extends BaseController
{
	use ControllerEventsTrait;

	/** Closed list of tasks. */
	protected function engageAllowedTasks(): ?array
	{
		return ['main', 'display', 'sendtest'];
	}

	/** @inheritdoc */
	protected $default_view = 'emailpreview';

	public function sendtest(): void
	{
		$app  = Factory::getApplication();
		$user = $app->getIdentity();
		$kind = EmailPreview::kind($this->input->post->getString('kind', 'moderator'));
		$lang = EmailPreview::lang($this->input->post->getString('lang', 'en-GB'));
		$back = Route::_('index.php?option=com_engage&view=emailpreview&kind=' . $kind . '&lang=' . $lang, false);

		if (strtoupper((string) $this->input->getMethod()) !== 'POST' || !Session::checkToken('post') || !$user || $user->guest || !$user->authorise('core.admin', 'com_engage'))
		{
			$this->setRedirect(Route::_('index.php?option=com_engage&view=controlpanel', false), Text::_('JINVALID_TOKEN_NOTICE'), 'error');

			return;
		}

		$session = $app->getSession();
		$wait    = EmailPreview::waitSeconds((int) $session->get('com_engage.mailtest.last', 0));

		if ($wait > 0)
		{
			$this->setRedirect($back, Text::_('COM_ENGAGE_MAILPREVIEW_TOOFAST'), 'warning');

			return;
		}

		// The time is recorded before sending: a failing mail server does not allow retrying in a loop either
		$session->set('com_engage.mailtest.last', time());

		$ok = false;

		try
		{
			$ok = EmailPreview::sendTest($kind, $lang, $user);
		}
		catch (\Throwable $e)
		{
			$ok = false;
		}

		$this->setRedirect($back, $ok ? Text::sprintf('COM_ENGAGE_MAILPREVIEW_SENT', htmlspecialchars((string) $user->email, ENT_QUOTES, 'UTF-8')) : Text::_('COM_ENGAGE_MAILPREVIEW_SEND_FAILED'), $ok ? 'message' : 'error');
	}
}
