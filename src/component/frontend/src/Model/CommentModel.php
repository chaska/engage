<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Model;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Model\CommentModel as AdminCommentModel;
use Akeeba\Component\Engage\Administrator\Table\CommentTable;
use Akeeba\Component\Engage\Site\Helper\Meta;
use Exception;
use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Utilities\IpHelper;
use RuntimeException;

class CommentModel extends AdminCommentModel
{
	/**
	 * Method for getting a form.
	 *
	 * @param   array    $data      Data for the form.
	 * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
	 *
	 * @return  Form|bool
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 *
	 */
	public function getForm($data = [], $loadData = true)
	{
		$id     = $data['id'] ?? null;
		$isNew  = empty($id);
		$source = $isNew ? 'comment_new' : 'comment';

		$form = $this->loadForm(
			'com_engage.comment',
			$source,
			[
				'control'   => 'jform',
				'load_data' => $loadData,
			]) ?: false;

		if (empty($form))
		{
			return false;
		}

		$form->bind($data);

		if ($isNew)
		{
			$this->postProcessNewCommentForm($form, $data);
		}
		else
		{
			$this->postProcessEditCommentForm($form, $data);
		}

		return $form;
	}

	/**
	 * Resubscribe a user to some content's comments
	 *
	 * @param   int          $asset_id  The asset ID of the comment we are commenting on
	 * @param   User|null    $user      The user account which is commenting
	 * @param   string|null  $email     The email address of the user commenting if $user is a guest
	 *
	 * @since   3.0.0
	 */
	public function resubscribeUser(int $asset_id, ?User $user, ?string $email)
	{
		$email = $user->guest ? $email : $user->email;

		if (empty($email))
		{
			return;
		}

		$db    = $this->getDbo();
		$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->delete($db->quoteName('#__engage_unsubscribe'))
			->where($db->quoteName('asset_id') . ' = :asset_id')
			->where($db->quoteName('email') . ' = :email')
			->bind(':asset_id', $asset_id)
			->bind(':email', $email);
		try
		{
			$db->setQuery($query)->execute();
		}
		catch (Exception $e)
		{
			// Ignore any failures, they are not important.
		}
	}

	/**
	 * Method to validate the form data.
	 *
	 * This is used to apply additional validation on top of what the form itself already offers.
	 *
	 * @param   Form    $form   The form to validate against.
	 * @param   array   $data   The data to validate.
	 * @param   string  $group  The name of the field group to validate.
	 *
	 * @return  array|boolean  Array of filtered data if valid, false otherwise.
	 *
	 * @throws  Exception
	 * @see     InputFilter
	 * @since   3.0.0
	 */
	public function validate($form, $data, $group = null)
	{
		try
		{
			$data    = $this->whitelistSubmittedData((array) $data);
			$assetId = (int) $data['asset_id'];

			$this->assertAssetAccess($assetId);
			$this->assertCommentsOpen($assetId);
			$this->assertAcceptTos($data['accept_tos'] ?? false);
			$this->assertValidParent((int) $data['parent_id'], $assetId, empty($data['id']));
		}
		catch (Exception $e)
		{
			$this->setError($e->getMessage());

			return false;
		}

		return parent::validate($form, $data, $group);
	}

	/**
	 * Fuerza los campos que el visitante NO decide (mass assignment: id, asset_id, parent_id y campos del servidor).
	 *
	 * - Comentario existente: asset_id y parent_id son SIEMPRE los almacenados; el formulario de edición no puede
	 *   mover un comentario a otro contenido ni colgarlo de otro comentario (ni crear ciclos en el árbol).
	 * - Comentario nuevo: id = 0; asset_id y parent_id solo se aceptan como enteros decimales; se descartan los campos
	 *   que fija el servidor (enabled, created, created_by, modified, modified_by, ip, user_agent).
	 *
	 * @param   array  $data  Datos enviados
	 *
	 * @return  array
	 * @throws  Exception
	 * @since   0.6.8
	 */
	private function whitelistSubmittedData(array $data): array
	{
		$toInt = static function ($v): ?int {
			if (is_int($v))
			{
				return $v;
			}

			return (is_string($v) && preg_match('/^[0-9]{1,18}$/', $v)) ? (int) $v : null;
		};

		$rawId = $data['id'] ?? 0;
		$id    = ($rawId === '' || $rawId === null) ? 0 : $toInt($rawId);

		if ($id === null || $id < 0)
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		if ($id > 0)
		{
			/** @var CommentTable $stored */
			$stored = $this->getTable('Comment', 'Administrator');

			if (!$stored->load($id))
			{
				throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
			}

			$data['id']        = $id;
			$data['asset_id']  = (int) $stored->asset_id;
			$data['parent_id'] = (int) ($stored->parent_id ?? 0);

			return $data;
		}

		foreach (['enabled', 'created', 'created_by', 'modified', 'modified_by', 'ip', 'user_agent'] as $field)
		{
			unset($data[$field]);
		}

		$rawParent = $data['parent_id'] ?? 0;
		$parentId  = ($rawParent === '' || $rawParent === null) ? 0 : $toInt($rawParent);

		if ($parentId === null && is_string($rawParent) && preg_match('/^-[0-9]{1,18}$/', $rawParent))
		{
			// Un negativo siempre se ha tratado como "sin padre" (CommentTable::onBeforeCheck).
			$parentId = 0;
		}

		if ($parentId === null)
		{
			throw new RuntimeException(Text::_('COM_ENGAGE_COMMENTS_ERR_INVALID_PARENT'));
		}

		$data['id']        = 0;
		$data['asset_id']  = $toInt($data['asset_id'] ?? 0) ?? 0;
		$data['parent_id'] = max(0, $parentId);

		return $data;
	}

	/**
	 * Comprueba el comentario al que se responde: existe, es del mismo contenido y es visible para quien responde
	 * (publicado, salvo moderadores). Todos los fallos dan el mismo mensaje para no revelar si el comentario existe.
	 *
	 * @param   int   $parentId  ID del comentario padre (0 = ninguno)
	 * @param   int   $assetId   Asset ID del contenido que se comenta
	 * @param   bool  $isNew     Solo se comprueba en comentarios nuevos
	 *
	 * @return  void
	 * @since   0.6.8
	 */
	private function assertValidParent(int $parentId, int $assetId, bool $isNew): void
	{
		if (!$isNew || $parentId <= 0)
		{
			return;
		}

		/** @var CommentTable $parent */
		$parent = $this->getTable('Comment', 'Administrator');

		if (!$parent->load($parentId) || ((int) $parent->asset_id !== $assetId))
		{
			throw new RuntimeException(Text::_('COM_ENGAGE_COMMENTS_ERR_INVALID_PARENT'));
		}

		if ($parent->enabled == 1)
		{
			return;
		}

		$user = UserFetcher::getUser();

		if (!$user->authorise('core.manage', 'com_engage') && !$user->authorise('core.edit.state', 'com_engage'))
		{
			throw new RuntimeException(Text::_('COM_ENGAGE_COMMENTS_ERR_INVALID_PARENT'));
		}
	}

	/**
	 * Post–process the edit an existing comment comment form
	 *
	 * @param   Form   $form  The form we have already loaded
	 * @param   array  $data  The data we loaded in the form
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	protected function postProcessEditCommentForm(Form $form, array $data)
	{
		$user           = UserFetcher::getUser();
		$removeFields   = [];
		$readonlyFields = [];

		if (!$user->authorise('core.manage', 'com_engage'))
		{
			$readonlyFields = ['name', 'email', 'created_by', 'created', 'enabled'];
			$removeFields   = ['ip', 'user_agent', 'modified', 'modified_by'];

			if ($user->authorise('core.edit.state', 'com_engage'))
			{
				array_pop($readonlyFields);
			}
		}

		foreach ($removeFields as $fieldName)
		{
			$form->removeField($fieldName);
		}

		foreach ($readonlyFields as $fieldName)
		{
			$form->setFieldAttribute($fieldName, 'disabled', 'true');
			$form->setFieldAttribute($fieldName, 'required', 'false');
			$form->setFieldAttribute($fieldName, 'filter', 'unset');
		}
	}

	/**
	 * Post–process the new comment form
	 *
	 * @param   Form   $form  The form we have already loaded
	 * @param   array  $data  The data we loaded in the form
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	protected function postProcessNewCommentForm(Form $form, array $data)
	{
		$user          = UserFetcher::getUser();
		$cParams       = ComponentHelper::getParams('com_engage');
		$tosPrompt     = $cParams->get('tos_prompt') ?: Text::_('COM_ENGAGE_COMMENTS_FORM_LBL_ACCEPT');
		$acceptTos     = $cParams->get('tos_accept', 0) == 1;
		$tosChecked    = (bool) ($data['accept_tos'] ?? 0);
		$captchaPlugin = $cParams->get('captcha', '0');
		$captchaFor    = $cParams->get('captcha_for', 'guests');

		// Non-guests cannot change their name or email
		if (!$user->guest)
		{
			$form->removeField('name');
			$form->removeField('email');
		}
		else
		{
			$form->setFieldAttribute('name', 'required', 'true');
			$form->setFieldAttribute('email', 'required', 'true');
		}

		// Only guests see the Accept ToS field and only if configured
		if (!$user->guest || !$acceptTos)
		{
			$form->removeField('accept_tos');
		}
		else
		{
			$form->setFieldAttribute('accept_tos', 'label', $tosPrompt);
			$form->setFieldAttribute('accept_tos', 'checked', $tosChecked ? 'true' : 'false');
		}

		// Should I display the CAPTCHA?
		$showCaptcha = false;

		switch ($captchaFor)
		{
			case 'guests':
				$showCaptcha = $user->guest;
				break;

			case 'nonmanager':
				$showCaptcha = !$user->guest && !$user->authorise('core.manage', 'com_engage');
				break;

			default:
				$showCaptcha = true;
		}

		$showCaptcha &= ($captchaPlugin !== '-1');

		if (!$showCaptcha)
		{
			$form->removeField('captcha');
		}
		elseif (!empty($captchaPlugin))
		{
			$form->setFieldAttribute('captcha', 'plugin', $captchaPlugin);
		}
	}

	/**
	 * Prepare the table before saving it into the database.
	 *
	 * @param   CommentTable  $table
	 *
	 * @return  void
	 * @throws  Exception
	 * @since   3.0.0
	 */
	protected function prepareTable($table)
	{
		// We only do something for new comments
		$isNew = empty($table->getId());

		if (!$isNew)
		{
			return;
		}

		// Get the user and the component parameters — I'll use them later.
		$user    = UserFetcher::getUser();
		$cParams = ComponentHelper::getParams('com_engage');

		// Set the created and modified information
		$date               = clone Factory::getDate();
		$table->created     = $date->toSql();
		$table->created_by  = $user->guest ? null : $user->id;
		$table->modified_by = null;
		$table->modified    = null;

		// If it's not a guest user we need to unset the custom name and email
		if (!$user->guest)
		{
			$table->name       = null;
			$table->email      = null;
		}

		// Get the asset meta
		if (empty($table->asset_id ?? null))
		{
			return;
		}

		/**
		 * Set the publish state.
		 *
		 * Managers have their comments always published (they can publish their own comments, so why add an unnecessary
		 * step?). Regular users' comments may be published or not, depending on the component's default_publish option.
		 */
		$assetMeta      = Meta::getAssetAccessMeta($table->asset_id, true);
		$defaultPublish = $assetMeta['parameters']->get('default_publish', -1) == -1
			? $cParams->get('default_publish', 1)
			: $assetMeta['parameters']->get('default_publish');
		$table->enabled = ($defaultPublish || $user->authorise('core.manage', 'com_engage')) ? 1 : 0;

		// Spam check. Possible spam is marked with publish status -3. Definite spam just doesn't post at all!
		PluginHelper::importPlugin('engage');
		$spamResults = Factory::getApplication()->triggerEvent('onAkeebaEngageCheckSpam', [$table]);

		if (in_array(true, $spamResults, true))
		{
			$table->enabled = -3;
		}

		// Set the IP and User Agent from the server environment. Only applies on web applications.
		$app               = Factory::getApplication();
		$isWebApplication  = $app instanceof CMSWebApplicationInterface;
		$table->ip         = $isWebApplication ? IpHelper::getIp() : null;
		$table->user_agent = $isWebApplication ? $app->input->server->getRaw('HTTP_USER_AGENT', '') : '';
	}

	/**
	 * Assert that the guest user has accepted the Terms of Service.
	 *
	 * This is contingent upon a component option.
	 *
	 * @param   bool  $acceptTos
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	private function assertAcceptTos(bool $acceptTos)
	{
		$user       = UserFetcher::getUser();
		$mustAccept = ComponentHelper::getParams('com_engage')->get('tos_accept', 0) == 1;

		if ($user->guest && $mustAccept && !$acceptTos)
		{
			throw new RuntimeException(Text::_('COM_ENGAGE_COMMENTS_ERR_TOSACCEPT'));
		}
	}

	/**
	 * Asserts that the user has view access to a published asset. Throws a RuntimeException otherwise.
	 *
	 * @param   int|null  $assetId
	 *
	 * @return  void
	 * @throws  Exception
	 * @since   3.0.0
	 */
	private function assertAssetAccess(?int $assetId): void
	{
		if (empty($assetId) || ($assetId <= 0))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		// Get the asset access metadata
		$assetMeta = Meta::getAssetAccessMeta($assetId);

		// Make sure the associated asset is published
		if (!$assetMeta['published'])
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		// 0.7.1: ...and its category too (an unpublished or trashed category makes the page answer 404)
		if (!Meta::isCategoryPublished($assetMeta))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		// Make sure the user is allowed to view this asset and its parent
		$access       = $assetMeta['access'];
		$parentAccess = $assetMeta['parent_access'];
		$user         = UserFetcher::getUser();

		if (!is_null($access) && !in_array($access, $user->getAuthorisedViewLevels()))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		if (!is_null($parentAccess) && !in_array($parentAccess, $user->getAuthorisedViewLevels()))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}

	/**
	 * Asserts that the comments for the content being commented on have not been closed.
	 *
	 * @param   int|null  $assetId  The asset ID of the content being commented on.
	 *
	 * @since   3.0.0
	 */
	private function assertCommentsOpen(?int $assetId): void
	{
		if (empty($assetId) || ($assetId <= 0) || Meta::areCommentsClosed($assetId))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}
}