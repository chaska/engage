<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\View\Emailpreview;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\EmailPreview;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Preview of the notification emails (0.9.0). Needs core.admin on com_engage.
 *
 * @since 0.9.0
 */
class HtmlView extends BaseHtmlView
{
	/** @var string */
	public $kind = 'moderator';
	/** @var string */
	public $lang = 'en-GB';
	/** @var string */
	public $html = '';
	/** @var string */
	public $plain = '';
	/** @var string */
	public $userEmail = '';

	public function display($tpl = null)
	{
		$app  = Factory::getApplication();
		$user = $app->getIdentity();

		if (!$user || $user->guest || !$user->authorise('core.admin', 'com_engage'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$this->kind      = EmailPreview::kind($app->getInput()->getString('kind', 'moderator'));
		$this->lang      = EmailPreview::lang($app->getInput()->getString('lang', in_array(substr((string) $app->getLanguage()->getTag(), 0, 2), ['es'], true) ? 'es-ES' : 'en-GB'));
		$this->userEmail = (string) $user->email;
		[$this->html, $this->plain] = EmailPreview::render($this->kind, $this->lang);

		$wa = $app->getDocument()->getWebAssetManager();
		$wa->useStyle('com_engage.panel');
		ToolbarHelper::title(Text::_('COM_ENGAGE_MAILPREVIEW_TITLE'), 'envelope');
		ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_engage&view=controlpanel');

		parent::display($tpl);
	}
}
