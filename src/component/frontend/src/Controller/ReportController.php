<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\ReactionStore;
use Akeeba\Component\Engage\Administrator\Helper\Reactions;
use Akeeba\Component\Engage\Administrator\Helper\ReportRules;
use Akeeba\Component\Engage\Administrator\Helper\ReportStore;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerRedirectionTrait;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerReturnURLTrait;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Reports of comments made by registered people (0.9.14): ONE task, `report.submit` (POST + CSRF token, logged in people only). The rules are in
 * ReportRules (tested with fake data); this controller reads the facts, asks, and answers with a message. A report never changes a comment.
 *
 * @since 0.9.14
 */
class ReportController extends BaseController
{
	use ControllerRedirectionTrait;
	use ControllerReturnURLTrait;

	/** @inheritdoc */
	protected $default_view = 'comments';

	/** Closed list of tasks: everything else (display, getModel, execute…) answers 403. */
	public function execute($task)
	{
		if (strtolower((string) $task) !== 'submit')
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		return parent::execute($task);
	}

	public function submit(): void
	{
		// Only a form of this site (a browser marks the requests that ANOTHER site starts)
		if (strtolower((string) $this->input->server->get('HTTP_SEC_FETCH_SITE', '', 'cmd')) === 'cross-site')
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		if (strtoupper((string) $this->input->getMethod()) !== 'POST')
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		if (!Session::checkToken('post'))
		{
			throw new RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
		}

		$user = UserFetcher::getUser();
		$uid  = ($user === null || $user->guest) ? 0 : (int) $user->id;

		// Guests never report: no form is printed for them and the task answers the same 403 as any other forbidden thing
		if ($uid <= 0)
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$msg = 'COM_ENGAGE_REPORT_ERR_GENERIC';
		$ok  = false;

		try
		{
			$msg = $this->process($user, $uid, $ok);
		}
		catch (Throwable $e)
		{
			$msg = 'COM_ENGAGE_REPORT_ERR_GENERIC';
		}

		$this->setMessage(Text::_($msg), $ok ? 'success' : 'error');
		$this->applyReturnUrl();

		if ($this->redirect === null)
		{
			$this->setRedirect(\Joomla\CMS\Router\Route::_('index.php', false));
		}
	}

	/** @return string  the language key of the message; $ok tells whether the report was stored */
	private function process($user, int $uid, bool &$ok): string
	{
		$db     = Factory::getContainer()->get(DatabaseInterface::class);
		$p      = ComponentHelper::getParams('com_engage');
		$store  = new ReportStore($db);
		$cid    = (int) $this->input->post->getInt('comment_id', 0);
		$reason = ReportRules::reason($this->input->post->get('reason', '', 'raw'));
		$note   = ReportRules::cleanNote($this->input->post->get('note', '', 'raw'));

		$limits = [];

		foreach (array_keys(ReportRules::LIMITS) as $k)
		{
			$limits[$k] = $p->get($k, null);
		}

		$rs      = new ReactionStore($db);
		$rows    = $cid > 0 ? $rs->comments([$cid]) : [];
		$comment = $rows[$cid] ?? null;
		$visible = false;
		$own     = false;

		if ($comment !== null)
		{
			$chain   = (new Reactions($rs))->chainVisibility($rows);
			$visible = (int) $comment['enabled'] === 1 && !empty($chain[$cid]) && ReactionsController::canView($user, (int) $comment['asset_id']);
			$own     = ((int) $comment['created_by'] === $uid)
				|| ((int) $comment['created_by'] === 0 && $comment['email'] !== '' && strcasecmp($comment['email'], (string) $user->email) === 0);
		}

		$now      = time();
		$register = strtotime((string) $user->registerDate . ' UTC');
		$age      = $register ? (int) floor(($now - $register) / 3600) : 0;
		$hour     = gmdate('Y-m-d H:i:s', $now - 3600);
		$day      = gmdate('Y-m-d H:i:s', $now - 86400);

		$decision = ReportRules::decide([
			'enabled'         => (int) $p->get('reports_enabled', 1) === 1,
			'uid'             => $uid,
			'accountAgeHours' => $age,
			'commentFound'    => $comment !== null,
			'commentVisible'  => $visible,
			'ownComment'      => $own,
			'reason'          => $reason,
			'already'         => $comment !== null && $store->exists($cid, $uid),
			'perHour'         => $store->countByUserSince($uid, $hour),
			'perDay'          => $store->countByUserSince($uid, $day),
			'open'            => $store->countOpen(),
			'limits'          => $limits,
		]);

		$map = [
			'disabled' => 'COM_ENGAGE_REPORT_ERR_DISABLED', 'young' => 'COM_ENGAGE_REPORT_ERR_YOUNG', 'notfound' => 'COM_ENGAGE_REPORT_ERR_GENERIC',
			'own' => 'COM_ENGAGE_REPORT_ERR_OWN', 'reason' => 'COM_ENGAGE_REPORT_ERR_REASON', 'already' => 'COM_ENGAGE_REPORT_ERR_ALREADY',
			'hour' => 'COM_ENGAGE_REPORT_ERR_LIMIT', 'day' => 'COM_ENGAGE_REPORT_ERR_LIMIT', 'full' => 'COM_ENGAGE_REPORT_ERR_FULL', 'login' => 'COM_ENGAGE_REPORT_ERR_GENERIC',
		];

		if ($decision !== 'ok')
		{
			return $map[$decision] ?? 'COM_ENGAGE_REPORT_ERR_GENERIC';
		}

		if (!$store->add($cid, $uid, $reason, $note, gmdate('Y-m-d H:i:s', $now)))
		{
			return 'COM_ENGAGE_REPORT_ERR_ALREADY';
		}

		$ok = true;

		return 'COM_ENGAGE_REPORT_MSG_SENT';
	}
}
