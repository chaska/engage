<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\ReportRules;
use Akeeba\Component\Engage\Administrator\Helper\ReportStore;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Queue of reports of comments (0.9.14). The list needs the access to the component (`core.manage`, checked by the dispatcher); the actions (dismiss,
 * mark as handled, unpublish the comment) need POST + CSRF token + `core.edit.state` on the component, checked HERE on the server. Nothing is done
 * automatically: a report only appears in this queue.
 */
class ReportsController extends BaseController
{
	use ControllerEventsTrait;

	/** @inheritdoc */
	protected $default_view = 'reports';

	/** Closed list of tasks. */
	protected function engageAllowedTasks(): ?array
	{
		return ['main', 'display', 'dismiss', 'handle', 'unpublish'];
	}

	private function guardAction(): int
	{
		$user = UserFetcher::getUser();

		if ($user === null || $user->guest || !$user->authorise('core.edit.state', 'com_engage'))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		if (strtoupper((string) $this->input->getMethod()) !== 'POST' || !Session::checkToken('post'))
		{
			throw new RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
		}

		return (int) $user->id;
	}

	public function dismiss(): void
	{
		$this->resolveReport(ReportRules::STATUS_DISMISSED, false);
	}

	public function handle(): void
	{
		$this->resolveReport(ReportRules::STATUS_HANDLED, false);
	}

	public function unpublish(): void
	{
		$this->resolveReport(ReportRules::STATUS_HANDLED, true);
	}

	private function resolveReport(int $status, bool $unpublish): void
	{
		$by  = $this->guardAction();
		$id  = (int) $this->input->post->getInt('report_id', 0);
		$cid = (int) $this->input->post->getInt('comment_id', 0);
		$msg = 'COM_ENGAGE_REPORTS_MSG_DONE';
		$typ = 'success';

		try
		{
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$store = new ReportStore($db);

			if ($unpublish && $cid > 0)
			{
				$model = $this->app->bootComponent('com_engage')->getMVCFactory()->createModel('Comment', 'Administrator', ['ignore_request' => true]);
				$pks   = [$cid];

				if (!$model->publish($pks, 0))
				{
					throw new RuntimeException('publish');
				}

				$store->resolve(0, $cid, $status, $by, gmdate('Y-m-d H:i:s'));
			}
			else
			{
				$store->resolve($id, $id > 0 ? 0 : $cid, $status, $by, gmdate('Y-m-d H:i:s'));
			}
		}
		catch (Throwable $e)
		{
			$msg = 'COM_ENGAGE_REPORTS_ERR_FAILED';
			$typ = 'error';
		}

		$this->setRedirect(Route::_('index.php?option=com_engage&view=reports', false), Text::_($msg), $typ);
	}
}
