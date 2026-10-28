<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\View\Permissions;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\PermissionsMatrix;
use Akeeba\Component\Engage\Administrator\Helper\PermissionsNav;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

/** Permissions matrix (0.9.16). Only for core.admin (the screen itself and its save are checked on the server). */
class HtmlView extends BaseHtmlView
{
	public $groups = [];
	public $cells = [];
	public $broad = [];
	public $saveUrl = '';
	public $token = '';
	public $advancedUrl = '';

	public function display($tpl = null)
	{
		$app  = Factory::getApplication();
		$user = $app->getIdentity();

		if (!$user || $user->guest || !$user->authorise('core.admin', 'com_engage'))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		ToolbarHelper::title(Text::_('COM_ENGAGE_TITLE_PERMISSIONS'), 'icon-engage');
		ToolbarHelper::back('JTOOLBAR_BACK', Route::_('index.php?option=com_engage&view=settings', false));

		$wa = $app->getDocument()->getWebAssetManager();
		$wa->useStyle('com_engage.lists')->useScript('com_engage.panel.theme')->useScript('com_engage.permissions');

		$db            = Factory::getContainer()->get(DatabaseInterface::class);
		$this->groups  = PermissionsMatrix::loadGroups($db);
		$this->cells   = PermissionsMatrix::joomlaCells($this->groups, PermissionsMatrix::loadExplicit($db));
		$this->broad   = PermissionsMatrix::broadGroups($this->groups);
		$this->saveUrl = Route::_('index.php?option=com_engage&task=permissions.save', false);
		$this->token   = Session::getFormToken();
		$this->advancedUrl = PermissionsNav::advancedUrl();

		parent::display($tpl);
	}
}
