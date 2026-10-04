<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\View\Settings;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\SettingsLayout;
use Akeeba\Component\Engage\Administrator\Helper\SettingsSchema;
use Akeeba\Component\Engage\Administrator\Helper\SettingsStorage;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

/**
 * Pantalla de opciones modernas (0.6.25): categorias a la izquierda, filas "etiqueta + control", guardado instantaneo.
 * La pantalla clasica de Joomla sigue disponible como respaldo.
 *
 * @since 0.6.25
 */
class HtmlView extends BaseHtmlView
{
	/** @var array */
	protected $sections = [];
	/** @var string */
	protected $active = 'design';
	/** @var array */
	protected $values = [];
	/** @var string */
	protected $classicUrl = '';
	/** @var string */
	protected $permissionsUrl = '';
	/** @var array */
	protected $captcha = [];

	public function display($tpl = null)
	{
		$user = UserFetcher::getUser();

		// Misma regla que la pantalla clasica de opciones del componente
		if (!($user->authorise('core.admin', 'com_engage') || $user->authorise('core.options', 'com_engage')))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$app  = Factory::getApplication();
		$lang = $app->getLanguage();
		$lang->load('com_engage.sys', JPATH_ADMINISTRATOR);

		foreach (['gravatar', 'akismet', 'email'] as $p)
		{
			$lang->load('plg_engage_' . $p, JPATH_ADMINISTRATOR);
			$lang->load('plg_engage_' . $p . '.sys', JPATH_ADMINISTRATOR);
		}

		$db      = Factory::getContainer()->get(DatabaseInterface::class);
		$storage = new SettingsStorage($db);
		$schema  = SettingsStorage::schema();

		$this->values = ['com_engage' => ComponentHelper::getParams('com_engage')->toArray()];
		$plugState    = [];

		foreach (array_keys($schema) as $scope)
		{
			if ($scope === 'com_engage')
			{
				continue;
			}

			$plugState[$scope] = $storage->pluginState($scope);

			if ($plugState[$scope]['exists'])
			{
				try
				{
					$this->values[$scope] = $storage->load($scope);
				}
				catch (\Throwable $e)
				{
					$this->values[$scope] = [];
				}
			}
		}

		$canPlugins = SettingsStorage::authorise($user, 'plg_engage_gravatar');
		$this->captcha = $storage->captchaPlugins();

		foreach ($this->captcha as $el)
		{
			$lang->load('plg_captcha_' . $el . '.sys', JPATH_ADMINISTRATOR);
		}

		$sections = [];

		foreach (SettingsLayout::sections() as $sec)
		{
			$groups = [];

			foreach ($sec['items'] as $item)
			{
				[$scope, $key] = $item;
				$def = $schema[$scope][$key] ?? null;

				if ($def === null || ($scope !== 'com_engage' && (!$canPlugins || !($plugState[$scope]['exists'] ?? false))))
				{
					continue;
				}

				if (!empty($item[2]))
				{
					$def['control'] = $item[2];
				}

				$gid = $item['group'] ?? ($item[3] ?? '');
				$groups[$gid]['key']   = $gid;
				$groups[$gid]['scope'] = $scope;
				$groups[$gid]['state'] = $scope === 'com_engage' ? null : $plugState[$scope];
				$groups[$gid]['rows'][] = $def;
			}

			$sec['groups'] = array_values($groups);
			$sections[]    = $sec;
		}

		$this->sections = $sections;
		$want           = $app->getInput()->getCmd('section', 'design');
		$this->active   = in_array($want, SettingsLayout::ids(), true) ? $want : 'design';

		$panel                = 'index.php?option=com_engage&view=settings';
		$this->classicUrl     = 'index.php?option=com_config&view=component&component=com_engage&return=' . base64_encode($panel);
		$this->permissionsUrl = $this->classicUrl . '#permissions';

		$doc = $app->getDocument();
		$doc->getWebAssetManager()
			->useStyle('com_engage.panel')
			->useStyle('com_engage.settings')
			->useScript('com_engage.panel.theme')
			->useScript('com_engage.panel')
			->useScript('com_engage.settings');

		$doc->addScriptOptions('com_engage.settings', [
			'url'   => Route::_('index.php?option=com_engage&task=settings.save&format=json', false),
			'token' => Session::getFormToken(),
			'msg'   => [
				'saving'   => Text::_('COM_ENGAGE_SET_SAVING'),
				'saved'    => Text::_('COM_ENGAGE_SET_SAVED'),
				'denied'   => Text::_('COM_ENGAGE_SET_ERR_DENIED'),
				'invalid'  => Text::_('COM_ENGAGE_SET_ERR_INVALID'),
				'failed'   => Text::_('COM_ENGAGE_SET_ERR_FAILED'),
				'network'  => Text::_('COM_ENGAGE_SET_ERR_NETWORK'),
				'range'    => Text::_('COM_ENGAGE_SET_ERR_RANGE'),
				'reverted' => Text::_('COM_ENGAGE_SET_REVERTED'),
			],
		]);
		$doc->addScriptOptions('com_engage.panel', [
			'themes'     => ['light' => Text::_('COM_ENGAGE_PANEL_THEME_LIGHT'), 'mid' => Text::_('COM_ENGAGE_PANEL_THEME_MID'), 'dark' => Text::_('COM_ENGAGE_PANEL_THEME_DARK'), 'auto' => Text::_('COM_ENGAGE_PANEL_THEME_AUTO')],
			'themeSaved' => Text::_('COM_ENGAGE_PANEL_THEME_ANNOUNCE'),
		]);

		ToolbarHelper::title(sprintf('%s: <span class="fw-bold">%s</span>', Text::_('COM_ENGAGE'), Text::_('COM_ENGAGE_SET_TITLE')), 'icon-engage');
		Toolbar::getInstance('toolbar')->link('COM_ENGAGE_SET_CLASSIC', Route::_($this->classicUrl, false))->icon('fa fa-cog');
		ToolbarHelper::back('JTOOLBAR_BACK', Route::_('index.php?option=com_engage&view=controlpanel', false));

		parent::display($tpl);
	}
}
