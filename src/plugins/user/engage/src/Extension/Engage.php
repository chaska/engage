<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\User\Engage\Extension;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Site\Helper\Meta;
use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseDriver;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;
use Throwable;

class Engage extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	/**
	 * Disallow registering legacy listeners since we use SubscriberInterface
	 *
	 * @var   bool
	 * @since 3.0.0
	 */
	protected $allowLegacyListeners = false;

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  3.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Should this plugin be allowed to run?
	 *
	 * If the runtime dependencies are not met the plugin effectively self-disables even if it's published. This
	 * prevents a WSOD should the user e.g. uninstall a library or the component without unpublishing the plugin first.
	 *
	 * @var  bool
	 */
	private $enabled = true;

	/**
	 * Cache the user objects to remove
	 *
	 * @var User[]
	 */
	private $usersToRemove = [];


	/**
	 * Constructor
	 *
	 * @param   DispatcherInterface  &$subject  The object to observe
	 * @param   array                 $config   An optional associative array of configuration settings.
	 *
	 * @return  void
	 */
	public function __construct(&$subject, $config = [])
	{
		if (!ComponentHelper::isEnabled('com_engage'))
		{
			$this->enabled = false;
		}

		parent::__construct($subject, $config);
	}

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   3.0.0
	 */
	public static function getsubscribedevents(): array
	{
		return [
			'onUserAfterDelete'  => 'onUserAfterDelete',
			'onUserBeforeDelete' => 'onUserBeforeDelete',
			'onUserLogin'        => 'onUserLogin',
		];
	}

	/**
	 * Remove all user profile information for the given user ID
	 *
	 * Method is called after user data is deleted from the database
	 *
	 * @param   Event  $event  The event we are listening to
	 *
	 * @return  void
	 *
	 */
	public function onUserAfterDelete(Event $event): void
	{
		[$user, $success, $msg] = array_values($event->getArguments());
		$result = $event->getArgument('result', []);
		$event->setArgument('result', array_merge($result, [true]));

		// Make sure we can actually run
		if (!$this->enabled)
		{
			return;
		}

		// Get the user ID; fail if it's not available
		$userId = ArrayHelper::getValue($user, 'id', 0, 'int');

		if (!$userId)
		{
			return;
		}

		// 0.7.1: if Joomla reported failure to remove the user the account still exists: neither the comments nor the reactions
		// are touched (and the cached user object is forgotten).
		if (!$success)
		{
			unset($this->usersToRemove[$userId]);

			return;
		}

		// 0.7.0: the reactions made by the user (likes, dislikes, favorites) are deleted with the account when Joomla deleted it
		// (never throws).
		\Akeeba\Component\Engage\Administrator\Helper\ReactionStore::deleteForUser($this->getDatabase(), (int) $userId);

		// 0.7.1: make sure we've seen this user ID before (onUserBeforeDelete cached the user object). The condition was inverted
		// since the original Akeeba Engage ("if the key EXISTS, return"), so the comments were never pseudonymised.
		if (!array_key_exists($userId, $this->usersToRemove))
		{
			return;
		}

		$userObject = $this->usersToRemove[$userId];
		unset($this->usersToRemove[$userId]);

		// Pseudonymise the comments. The account is already deleted: a failure here must never reach whoever deleted it
		// (it is logged), and it must not stop other plugins from handling the event.
		try
		{
			$this->getApplication()->getLanguage()->load('com_engage', JPATH_ADMINISTRATOR);
			$this->getApplication()->getLanguage()->load('com_engage', JPATH_SITE);

			Meta::pseudonymiseUserComments($userObject, true);
		}
		catch (Throwable $e)
		{
			try
			{
				Log::add('Akeeba Engage: could not pseudonymise the comments of deleted user #' . (int) $userId . ': ' . $e->getMessage(), Log::ERROR, 'com_engage');
			}
			catch (Throwable $e2)
			{
				// Nothing else we can do
			}
		}
	}

	/**
	 * Remove all user profile information for the given user ID.
	 *
	 * Method is called before user data is deleted from the database. We use it to cache the user object so we can use
	 * it onUserAfterDelete when the user object is no longer available through the Joomla API.
	 *
	 * @param   Event  $event
	 *
	 * @return  void
	 *
	 * @throws Exception
	 */
	public function onUserBeforeDelete(Event $event): void
	{
		[$user] = array_values($event->getArguments());
		$result = $event->getArgument('result', []);
		$event->setArgument('result', array_merge($result, [true]));

		// Make sure we can actually run
		if (!$this->enabled)
		{
			return;
		}

		// Get the user ID; fail if it's not available
		$userId = ArrayHelper::getValue($user, 'id', 0, 'int');

		if (!$userId)
		{
			return;
		}

		// Get and verify the user object. 0.7.1: this runs BEFORE Joomla deletes the account, so it must never throw (an
		// exception here would stop the account from being deleted).
		try
		{
			$userObject = UserFetcher::getUser($userId);

			if ($userObject->id != $userId)
			{
				return;
			}

			// Cache the user object
			$this->usersToRemove[$userId] = clone $userObject;
		}
		catch (Throwable $e)
		{
			unset($this->usersToRemove[$userId]);
		}
	}

	/**
	 * This method should handle any login logic and report back to the subject
	 *
	 * @param   Event  $event
	 *
	 * @return  void
	 *
	 * @since   1.0.0.b3
	 */
	public function onUserLogin(Event $event): void
	{
		/**
		 * @var   array $user    Holds the user data
		 * @var   array $options Array holding options (remember, autoregister, group)
		 */
		[$user, $options] = array_values($event->getArguments());
		$result = $event->getArgument('result', []);
		$event->setArgument('result', array_merge($result, [true]));

		// Is the “Own guest comments on login“ option enabled?
		if ($this->params->get('own_comments', 1) != 1)
		{
			return;
		}

		// Load the user object and own the comments
		$userObject = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserByUsername($user['username']);

		if (empty($userObject) || ($userObject->id <= 0))
		{
			return;
		}

		$this->ownComments($userObject);
	}

	/**
	 * Allow the specified user to own the comments they filed as a guest under the same email address
	 *
	 * @param   User  $user
	 *
	 * @return  void
	 */
	private function ownComments(User $user): void
	{
		// Sanity check
		if ($user->guest || ($user->id <= 0) || empty($user->email))
		{
			return;
		}

		// Run a simple update query to let the user own the comments
		$db = $this->getDatabase();

		/**
		 * 0.7.1: a comment cannot be liked or disliked by its own author (the server refuses it), so the likes / dislikes that this user
		 * gave BEFORE the comment became theirs are deleted along with the change of owner (the favourite, which is private, stays).
		 * Without this a guest comment carrying the user's email turned into "own" at login with the previous like still in place.
		 */
		try
		{
			$idsQuery = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select($db->qn('id'))
				->from($db->qn('#__engage_comments'))
				->where($db->qn('email') . ' = ' . $db->q($user->email))
				->where($db->qn('created_by') . ' = 0');
			$ownIds = $db->setQuery($idsQuery)->loadColumn() ?: [];

			\Akeeba\Component\Engage\Administrator\Helper\ReactionStore::deleteOwnLikes($db, (int) $user->id, $ownIds);
		}
		catch (Throwable $e)
		{
			// No problem if this fails: the server also ignores them (state) and does not count them
		}

		$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->update($db->qn('#__engage_comments'))
			->set([
				$db->qn('name') . ' = NULL',
				$db->qn('email') . ' = NULL',
				$db->qn('created_by') . ' = ' . $user->id,
			])
			->where($db->qn('email') . ' = ' . $db->q($user->email))
			->where($db->qn('created_by') . ' = 0');

		try
		{
			$db->setQuery($query)->execute();
		}
		catch (Exception $e)
		{
			// No problem if this fails.
		}
	}
}
