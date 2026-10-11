<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Controller\CommentController as AdminCommentController;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerRedirectionTrait;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerReturnURLTrait;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerReusableModelsTrait;
use Akeeba\Component\Engage\Administrator\Table\CommentTable;
use Akeeba\Component\Engage\Site\Helper\CommentAccess;
use Akeeba\Component\Engage\Site\Mixin\ControllerFrontendCommentsTrait;
use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use RuntimeException;

class CommentController extends AdminCommentController
{
	use ControllerFrontendCommentsTrait;
	use ControllerRedirectionTrait;
	use ControllerReturnURLTrait;
	use ControllerReusableModelsTrait;

	/**
	 * Closed list of the tasks that the pages use (0.8.3): `main` (the edit screen, also after a failed save), `edit` (the Edit button)
	 * and `save` (both forms). Anything else (loadhistory, runTransition, batch, cancel, reload, unknown tasks...) answers 403.
	 */
	protected function engageAllowedTasks(): ?array
	{
		return ['main', 'edit', 'save'];
	}

	public function batch($model = null)
	{
		throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
	}

	/**
	 * Shows the edit screen (0.8.3: ACL; before, ANY visitor could read ANY comment by ID, including its author's email address).
	 *
	 * The screen is only for a new comment (ID 0: the form shown again after a failed save, with the visitor's own data) or for an existing
	 * comment that the user may edit (`core.edit`, or `core.edit.own` and the comment is theirs). Every other case answers the same 403,
	 * whether the comment exists or not, so that nothing tells an attacker which IDs exist.
	 *
	 * @throws  RuntimeException
	 */
	public function display($cachable = false, $urlparams = [])
	{
		$id = CommentAccess::requestedId($this->input);

		if (!CommentAccess::mayOpen($id, UserFetcher::getUser(), !empty($this->app->getUserState('com_engage.edit.comment.data', []))))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		// The model reads the ID from the application's input: both carry the canonical integer, never the raw request value
		$this->app->getInput()->set('id', $id);
		$this->input->set('id', $id);

		return parent::display($cachable, $urlparams);
	}

	public function editAssociations()
	{
		throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
	}

	/**
	 * Method to check if you can edit an existing record.
	 *
	 * @param   array   $data  An array of input data.
	 * @param   string  $key   The name of the key for the primary key; default is id.
	 *
	 * @return  boolean
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	protected function allowEdit($data = [], $key = 'id')
	{
		if (parent::allowEdit($data, $key))
		{
			return true;
		}

		/**
		 * If I am here the user does not have the core.edit permissions. I will check if the user has core.edit.own and
		 * the referenced comment belongs to the user.
		 */
		$user = UserFetcher::getUser();

		if ($user->guest || !$user->authorise('core.edit.own', 'com_engage'))
		{
			return false;
		}

		/** @var CommentTable $table */
		$table = $this->getModel()->getTable('Comment', 'Administrator');
		$id    = $data[$key] ?? null;

		if (empty($id) || !$table->load($id))
		{
			return false;
		}

		return $table->created_by == $user->id;
	}

	protected function onAfterApply()
	{
		$this->applyReturnUrl();

		$this->cleanCache();
	}

	protected function onAfterSave()
	{
		$this->applyReturnUrl();

		$this->cleanCache();
	}

	protected function onBeforeMain()
	{
		$this->disableJoomlaCache();
		$this->getView()->returnUrl = $this->getRedirection() ?: $this->getReturnUrl() ?: '';
	}

	protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
	{
		// If the user was unsubscribed from comments we need to resubscribe them
		$user = UserFetcher::getUser();
		$model->resubscribeUser((int) ($validData['asset_id'] ?? 0), $user, $validData['email'] ?? null);

		$this->setMessage(Text::_('COM_ENGAGE_COMMENTS_MSG_SUCCESS'), 'success');

		parent::postSaveHook($model, $validData);
	}

}