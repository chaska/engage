<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\PermissionsMatrix;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\Application\ExitException;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Permissions matrix (0.9.16): the screen (`main`, only for core.admin) and its single save (`save`: ONE POST with the CSRF token, X-Requested-With, a session
 * and core.admin checked here on the server). The browser is never trusted: it only says which cells it wants to turn to Allow or to «not assigned»; the
 * server validates the groups against the database and the actions against the closed list, recalculates everything with Joomla's own Access and writes with
 * Joomla's Asset table. See PermissionsMatrix.
 */
class PermissionsController extends BaseController
{
	use ControllerEventsTrait;

	/** @inheritdoc */
	protected $default_view = 'permissions';

	/** Closed list of tasks. */
	protected function engageAllowedTasks(): ?array
	{
		return ['main', 'display', 'save'];
	}

	public function save(): void
	{
		try
		{
			$req = $this->input->server;

			if (strtoupper((string) $this->input->getMethod()) !== 'POST')
			{
				$this->respond(405, ['ok' => false, 'error' => 'method']);
			}

			if (strtolower((string) $req->get('HTTP_X_REQUESTED_WITH', '', 'raw')) !== 'xmlhttprequest')
			{
				$this->respond(403, ['ok' => false, 'error' => 'denied']);
			}

			if (!Session::checkToken('post'))
			{
				$this->respond(403, ['ok' => false, 'error' => 'denied']);
			}

			$user = UserFetcher::getUser();

			if ($user === null || $user->guest || !$user->authorise('core.admin', 'com_engage'))
			{
				$this->respond(403, ['ok' => false, 'error' => 'denied']);
			}

			$changes = PermissionsMatrix::parseChanges($this->input->post->get('changes', null, 'raw'));

			if ($changes === null)
			{
				$this->respond(400, ['ok' => false, 'error' => 'invalid']);
			}

			$confirm = (string) $this->input->post->get('confirm', '', 'raw') === '1';
			$db      = Factory::getContainer()->get(DatabaseInterface::class);
			$groups  = PermissionsMatrix::loadGroups($db);
			$ids     = array_column($groups, 'id');
			$super   = array_values(array_filter($ids, static fn(int $g): bool => \Joomla\CMS\Access\Access::checkGroup($g, 'core.admin') === true));
			$before  = PermissionsMatrix::loadExplicit($db);
			$m       = PermissionsMatrix::merge($before, $changes, $ids, $super, PermissionsMatrix::broadGroups($groups));

			if ($m['needsConfirm'] && !$confirm)
			{
				$this->respond(409, ['ok' => false, 'error' => 'confirm']);
			}

			if ($m['applied'])
			{
				if (!PermissionsMatrix::store($m['rules']))
				{
					$this->respond(500, ['ok' => false, 'error' => 'failed']);
				}

				$this->log($user, PermissionsMatrix::summary($m['applied'], array_column($groups, 'title', 'id')));
			}

			$this->respond(200, [
				'ok'      => true,
				'changed' => count($m['applied']),
				'ignored' => $m['ignored'],
				'cells'   => PermissionsMatrix::joomlaCells($groups, PermissionsMatrix::loadExplicit($db)),
			]);
		}
		catch (Throwable $e)
		{
			if ($e instanceof ExitException)
			{
				throw $e;
			}

			$this->respond(500, ['ok' => false, 'error' => 'failed']);
		}
	}

	/** Joomla's action log (needs the Engage action log plugin). No personal data: group titles and action names only. */
	private function log($user, string $summary): void
	{
		try
		{
			if (!\Joomla\CMS\Plugin\PluginHelper::isEnabled('actionlog', 'engage'))
			{
				return;
			}

			$app   = Factory::getApplication();
			$model = $app->bootComponent('com_actionlogs')->getMVCFactory()->createModel('Actionlog', 'Administrator', ['ignore_request' => true]);
			$model->addLog([[
				'title'       => 'permissions',
				'summary'     => $summary,
				'userid'      => $user->id,
				'username'    => $user->username,
				'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			]], 'COM_ENGAGE_USERLOG_PERMISSIONS_CHANGED', 'com_engage', $user->id);
		}
		catch (Throwable $e)
		{
		}
	}

	private function respond(int $status, array $data): void
	{
		$app = Factory::getApplication();

		if (!headers_sent())
		{
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store, max-age=0');
			header('X-Content-Type-Options: nosniff');
		}

		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$app->close();
	}
}
