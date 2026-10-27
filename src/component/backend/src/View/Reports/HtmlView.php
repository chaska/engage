<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\View\Reports;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\ReportRules;
use Akeeba\Component\Engage\Administrator\Helper\ReportStore;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;
use Throwable;

/** Queue of reports of comments (0.9.14). The personal data of the reporter are only printed here, for people with access to the component. */
class HtmlView extends BaseHtmlView
{
	public const PER_PAGE = 25;

	/** @var array[] */
	public $rows = [];
	public $total = 0;
	public $status = ReportRules::STATUS_OPEN;
	public $reason = 0;
	public $start = 0;
	public $canAct = false;
	public $failed = false;

	public function display($tpl = null)
	{
		ToolbarHelper::title(Text::_('COM_ENGAGE_TITLE_REPORTS'), 'icon-engage');
		ToolbarHelper::back('JTOOLBAR_BACK', Route::_('index.php?option=com_engage&view=controlpanel', false));

		$app = Factory::getApplication();
		$app->getDocument()->getWebAssetManager()->useStyle('com_engage.lists')->useScript('com_engage.panel.theme');

		$in           = $app->getInput();
		$st           = (string) $in->get('status', '0', 'raw');
		$this->status = in_array($st, ['0', '1', '2', 'all'], true) ? ($st === 'all' ? -1 : (int) $st) : ReportRules::STATUS_OPEN;
		$this->reason = ReportRules::reason($in->get('reason', '', 'raw'));
		$this->start  = max(0, min(100000, (int) $in->getInt('start', 0)));
		$user         = $app->getIdentity();
		$this->canAct = $user && $user->authorise('core.edit.state', 'com_engage');

		try
		{
			$r           = (new ReportStore(Factory::getContainer()->get(DatabaseInterface::class)))->queue($this->status, $this->reason, $this->start, self::PER_PAGE);
			$this->rows  = $r['rows'];
			$this->total = $r['total'];
		}
		catch (Throwable $e)
		{
			$this->failed = true;
		}

		parent::display($tpl);
	}
}
