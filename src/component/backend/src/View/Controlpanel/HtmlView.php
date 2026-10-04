<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\View\Controlpanel;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\PanelChart;
use Akeeba\Component\Engage\Administrator\Helper\PanelData;
use Akeeba\Component\Engage\Administrator\Helper\PanelHealth;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

/**
 * Panel de control del componente (0.6.24): accesos rapidos, numeros, grafico, ultimos comentarios, estado y version.
 * Sin llamadas a servidores externos.
 *
 * @since 0.6.24
 */
class HtmlView extends BaseHtmlView
{
	/** @var array */
	protected $stats = [];
	/** @var array */
	protected $chart = [];
	/** @var array */
	protected $latest = [];
	/** @var array */
	protected $health = [];
	/** @var string */
	protected $installedVersion = '';
	/** @var array<string,bool> */
	protected $can = [];

	public function display($tpl = null)
	{
		$db   = Factory::getContainer()->get(DatabaseInterface::class);
		$user = UserFetcher::getUser();

		$this->can = [
			'state'    => $user->authorise('core.edit.state', 'com_engage'),
			'delete'   => $user->authorise('core.delete', 'com_engage'),
			'admin'    => $user->authorise('core.admin', 'com_engage') || $user->authorise('core.options', 'com_engage'),
			'comments' => $user->authorise('core.manage', 'com_engage'),
		];

		$this->stats            = PanelData::stats($db);
		$this->chart            = PanelChart::geometry($this->stats['series']);
		$this->latest           = PanelData::latest($db, 6);
		$this->health           = PanelHealth::evaluate(PanelData::facts($db, $user));
		$this->installedVersion = PanelData::installedVersion($db);

		$app = Factory::getApplication();
		$doc = $app->getDocument();
		$wa  = $doc->getWebAssetManager();
		$wa->useStyle('com_engage.panel')
			->useScript('com_engage.panel.theme')
			->useScript('com_engage.panel');

		// Textos de la ventana de confirmacion (los usa panel.js; nunca confirm() del navegador)
		$doc->addScriptOptions('com_engage.panel', [
			'dialog' => [
				'publish' => ['title' => Text::_('COM_ENGAGE_PANEL_DLG_PUBLISH_TITLE'), 'text' => Text::_('COM_ENGAGE_PANEL_DLG_PUBLISH_TEXT'), 'ok' => Text::_('COM_ENGAGE_PANEL_ACT_PUBLISH'), 'task' => 'comments.publish', 'tone' => 'ok'],
				'spam'    => ['title' => Text::_('COM_ENGAGE_PANEL_DLG_SPAM_TITLE'), 'text' => Text::_('COM_ENGAGE_PANEL_DLG_SPAM_TEXT'), 'ok' => Text::_('COM_ENGAGE_PANEL_ACT_SPAM'), 'task' => 'comments.possiblespam', 'tone' => 'warn'],
				'delete'  => ['title' => Text::_('COM_ENGAGE_PANEL_DLG_DELETE_TITLE'), 'text' => Text::_('COM_ENGAGE_PANEL_DLG_DELETE_TEXT'), 'ok' => Text::_('COM_ENGAGE_PANEL_ACT_DELETE'), 'task' => 'comments.delete', 'tone' => 'bad'],
			],
			'cancel' => Text::_('JCANCEL'),
			'themes' => [
				'light' => Text::_('COM_ENGAGE_PANEL_THEME_LIGHT'),
				'mid'   => Text::_('COM_ENGAGE_PANEL_THEME_MID'),
				'dark'  => Text::_('COM_ENGAGE_PANEL_THEME_DARK'),
				'auto'  => Text::_('COM_ENGAGE_PANEL_THEME_AUTO'),
			],
			'themeSaved' => Text::_('COM_ENGAGE_PANEL_THEME_ANNOUNCE'),
			'tipOne'     => Text::_('COM_ENGAGE_PANEL_CHART_TIP_ONE'),
			'tipMany'    => Text::_('COM_ENGAGE_PANEL_CHART_TIP_MANY'),
		]);

		ToolbarHelper::title(
			sprintf('%s: <span class="fw-bold">%s</span>', Text::_('COM_ENGAGE'), Text::_('COM_ENGAGE_TITLE_CONTROLPANEL')),
			'icon-engage'
		);

		if ($this->can['admin'])
		{
			ToolbarHelper::preferences('com_engage');
		}

		parent::display($tpl);
	}
}
