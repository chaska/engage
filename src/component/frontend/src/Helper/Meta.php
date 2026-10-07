<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Helper;

defined('_JEXEC') or die();

use Akeeba\Component\Engage\Administrator\Mixin\RunPluginsTrait;
use Akeeba\Component\Engage\Administrator\Model\CommentsModel;
use DateInterval;
use Exception;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Throwable;

/**
 * Helper class to get information about articles and their comments
 *
 * @since 1.0.0
 */
final class Meta
{
	use RunPluginsTrait;

	/**
	 * Cached results of resource metadata per asset ID
	 *
	 * @var  array
	 */
	private static $cachedMeta = [];

	/**
	 * IDs of comments per asset in the same order they are paginated in the front-end
	 *
	 * @var  array
	 */
	private static $commentIDsPerAsset = [];

	/**
	 * The MVC Factory object for com_engage
	 *
	 * @var   MVCFactoryInterface|null
	 * @since 3.0.0
	 */
	private static $mvcFactory;

	/**
	 * Number of comments per asset ID
	 *
	 * @var  int[]
	 */
	private static $numCommentsPerAsset = [];

	/**
	 * Are the comments closed for the specified resource for any reason?
	 *
	 * @param   int  $assetId  The asset ID of the resource
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	public static function areCommentsClosed(int $assetId = 0)
	{
		$meta = self::getAssetAccessMeta($assetId, true);

		/** @var Registry $params */
		$params = $meta['parameters'];

		if ($params->get('comments_enabled', 0) != 1)
		{
			return true;
		}

		return self::areCommentsClosedAfterTime($assetId);
	}

	/**
	 * Are the comments autoamtically closed for the specified resource because a certain amoutn of time elapsed?
	 *
	 * @param   int  $assetId  The asset ID of the resource
	 *
	 * @return  bool
	 * @since   3.0.7
	 */
	public static function areCommentsClosedAfterTime(int $assetId = 0): bool
	{
		$meta = self::getAssetAccessMeta($assetId, true);
		/** @var Registry $params */
		$params     = $meta['parameters'];
		$closeAfter = $params->get('comments_close_after', 0);

		if ($closeAfter <= 0)
		{
			return false;
		}

		// Check if the comments are auto-closed.
		/** @var Date $date */
		$date = $meta['published_on'];

		try
		{
			return ($date->add(new DateInterval(sprintf('P%dD', $closeAfter)))->toUnix() <= time());
		}
		catch (Exception $e)
		{
			return false;
		}
	}

	/**
	 * Is the category of the content published? (0.7.1)
	 *
	 * Joomla's own article page serves an article only when its category has `published > 0` (published or archived; unpublished
	 * and trashed categories answer 404) and the user has the category's access level. It looks at the article's OWN category only,
	 * never at the ancestors, and so do we (checked against a real Joomla). NULL (not applicable: the asset has no category) counts
	 * as published.
	 *
	 * @param   array  $meta  The result of getAssetAccessMeta()
	 *
	 * @return  bool
	 * @since   0.7.1
	 */
	public static function isCategoryPublished(array $meta): bool
	{
		$value = $meta['category_published'] ?? null;

		return ($value === null) || ((int) $value > 0);
	}

	/**
	 * Returns the metadata of an asset.
	 *
	 * This method goes through the onAkeebaEngageGetAssetMeta plugin event, allowing different plugins to return
	 * information about the asset IDs they recognize. The results are cached to avoid expensive roundtrips to the
	 * Joomla plugin event system and the database.
	 *
	 * @param   int   $assetId         The asset ID to load
	 * @param   bool  $loadParameters  Should I also load the asset's parameters?
	 *
	 * @return  array{type:string, title:string, category:string, author_name:string, author_email:string, url:string,
	 *     public_url:string, published:bool, published_on:Date, access:int, parent_access:int,
	 *     category_published:int|null, parameters:Registry}
	 * @since   1.0.0
	 */
	public static function getAssetAccessMeta(int $assetId = 0, bool $loadParameters = false): array
	{
		$metaKey    = hash('md5', $assetId . '_' . ($loadParameters ? 'with' : 'without') . '_parameters');
		$altMetaKey = hash('md5', $assetId . '_with_parameters');

		if (array_key_exists($metaKey, self::$cachedMeta))
		{
			return self::$cachedMeta[$metaKey];
		}

		if (array_key_exists($altMetaKey, self::$cachedMeta))
		{
			return self::$cachedMeta[$altMetaKey];
		}

		self::$cachedMeta[$metaKey] = [
			'type'          => 'unknown',
			'title'         => '',
			'category'      => null,
			'author_name'   => null,
			'author_email'  => null,
			'url'           => null,
			'public_url'    => null,
			'published'     => false,
			'published_on'  => clone Factory::getDate(),
			'access'        => 0,
			'parent_access' => null,
			// 0.7.1: published state of the content's category (NULL = unknown / not applicable); see isAssetViewable()
			'category_published' => null,
			'parameters'    => new Registry(),
		];

		PluginHelper::importPlugin('content');
		$pluginResults = self::triggerPluginEventStatic('onAkeebaEngageGetAssetMeta', [$assetId, $loadParameters]);

		$pluginResults = array_filter($pluginResults, function ($x) {
			return is_array($x);
		});

		if (empty($pluginResults))
		{
			return self::$cachedMeta[$metaKey];
		}

		$tempRet = array_shift($pluginResults);

		foreach (self::$cachedMeta[$metaKey] as $k => $v)
		{
			if (!array_key_exists($k, $tempRet))
			{
				continue;
			}

			self::$cachedMeta[$metaKey][$k] = $tempRet[$k] ?? $v;
		}

		return self::$cachedMeta[$metaKey];
	}

	/**
	 * Returns the total number of comments for a specific asset ID
	 *
	 * @param   int  $asset_id  The asset ID to check for the total number of comments
	 *
	 * @return  int
	 */
	public static function getNumCommentsForAsset(int $asset_id): int
	{
		if (isset(self::$numCommentsPerAsset[$asset_id]))
		{
			return self::$numCommentsPerAsset[$asset_id];
		}

		/** @var CommentsModel $model */
		$model = self::getMVCFactory()->createModel('Comments', 'Administrator', ['ignore_request' => true]);

		$model->setState('filter.asset_id', $asset_id);

		self::$numCommentsPerAsset[$asset_id] = $model->getTotal() ?: 0;

		return self::$numCommentsPerAsset[$asset_id];
	}

	/**
	 * Returns the comments IDs for an asset, in the same order as they are paginated in the frontend
	 *
	 * @param   int   $asset_id   Asset ID
	 * @param   bool  $asManager  True to take into account unpublished comments
	 *
	 * @return  int[]
	 * @since   1.0.0
	 */
	public static function getPaginatedCommentIDsForAsset(int $asset_id, bool $asManager = false): array
	{
		if (isset(self::$commentIDsPerAsset[$asset_id]))
		{
			return self::$commentIDsPerAsset[$asset_id];
		}

		/** @var CommentsModel $model */
		$model = self::getMVCFactory()->createModel('Comments', 'Administrator', ['ignore_request' => true]);
		$model->setState('filter.asset_id', $asset_id);

		if (!$asManager)
		{
			$model->setState('filter.enabled', 1);
		}

		self::$commentIDsPerAsset[$asset_id] = array_keys($model->commentIDTreeSliceWithDepth(0, null));

		return self::$commentIDsPerAsset[$asset_id];
	}

	/**
	 * Pseudonymises and removes the content of comments filed by a user.
	 *
	 * All comments filed either directly with their user ID or under their email address are affected.
	 *
	 * The following actions are taken:
	 *
	 * * The comment text is replaced with COM_ENGAGE_COMMENTS_LBL_DELETEDCOMMENT
	 * * The name (for guest comments filed under the user's email) is replaced with COM_ENGAGE_COMMENTS_LBL_DELETEDUSER
	 * * The email (for guest comments filed under the user's email) is replaced with deleted.<USER_ID>@<SITE_HOSTNAME>
	 * * 0.7.1: the IP address is emptied and the user agent too, and, when converting to guest, created_by becomes 0
	 * * All #__engage_unsubscribe records with that email address are removed
	 *
	 * This is similar to how other sites, e.g. Slashdot, treat user account deletion. If we were to completely delete a
	 * user's comments we would also be deleting the entire conversation below them since we can't have orphan comments.
	 * Deleting the comment's contents is less disruptive. Of course it doesn't do you much good if you were directly
	 * quoted by a different user but that's something you should have thought before saying something regrettable on
	 * the Internet...
	 *
	 * @param   User|null  $user            The user object whose comments will be nuked
	 *
	 * @param   bool       $convertToGuest  True to convert attributed comments to pseudonymized guest comments
	 *
	 * @return  int[]  The comment IDs affected
	 */
	public static function pseudonymiseUserComments(?User $user, bool $convertToGuest = false): array
	{
		if (empty($user))
		{
			return [];
		}

		if ($user->guest || ((int) $user->id <= 0))
		{
			return [];
		}

		/** @var DatabaseDriver $db */
		$db     = Factory::getContainer()->get(DatabaseInterface::class);
		$userId = (int) $user->id;
		$email  = (string) $user->email;
		$body   = Text::_('COM_ENGAGE_COMMENTS_LBL_DELETEDCOMMENT');
		$name   = Text::sprintf('COM_ENGAGE_COMMENTS_LBL_DELETEDUSER', $userId);

		// Host for the pseudonymised email address (0.7.1: also when there is no HTTP host, e.g. the Joomla console)
		$host = '';

		try
		{
			$host = (string) Uri::getInstance()->getHost();
		}
		catch (Throwable $e)
		{
			// Uri::getInstance() cannot parse the request URI under the Joomla console (cli/joomla.php with a fixed live_site)
		}

		if ($host === '')
		{
			try
			{
				$host = (string) parse_url((string) Uri::root(), PHP_URL_HOST);
			}
			catch (Throwable $e)
			{
				$host = '';
			}
		}

		$host = ($host !== '') ? $host : 'localhost';

		$newQuery = static function () use ($db) {
			return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
		};

		/**
		 * 0.7.1: everything happens in ONE transaction, so a failure half way leaves the comments as they were (the caller can
		 * retry or report) instead of half pseudonymised. The IP address and the browser (user agent) are personal data too:
		 * they are emptied together with the text (ip is nullable; user_agent is NOT NULL, so it becomes an empty string).
		 */
		$db->transactionStart();

		try
		{
			// Comments directly attributed to the user ID
			$q   = $newQuery()
				->select($db->qn('id'))
				->from($db->qn('#__engage_comments'))
				->where($db->qn('created_by') . ' = :userid')
				->bind(':userid', $userId, ParameterType::INTEGER);
			$cid = $db->setQuery($q)->loadColumn() ?: [];

			$q = $newQuery()
				->update($db->qn('#__engage_comments'))
				->set([
					$db->qn('body') . ' = :body',
					$db->qn('ip') . ' = NULL',
					$db->qn('user_agent') . " = ''",
				])
				->where($db->qn('created_by') . ' = :userid')
				->bind(':body', $body, ParameterType::STRING)
				->bind(':userid', $userId, ParameterType::INTEGER);
			$db->setQuery($q)->execute();

			// Comments attributed to the user's email address (guest comments filed with it)
			if ($email !== '')
			{
				$q   = $newQuery()
					->select($db->qn('id'))
					->from($db->qn('#__engage_comments'))
					->where($db->qn('email') . ' = :email')
					->bind(':email', $email, ParameterType::STRING);
				$cid = array_merge($cid, $db->setQuery($q)->loadColumn() ?: []);

				$deletedEmail = sprintf('deleted.%u@%s', $userId, $host);

				$q = $newQuery()
					->update($db->qn('#__engage_comments'))
					->set([
						$db->qn('body') . ' = :body',
						$db->qn('name') . ' = :name',
						$db->qn('email') . ' = :newemail',
						$db->qn('ip') . ' = NULL',
						$db->qn('user_agent') . " = ''",
					])
					->where($db->qn('email') . ' = :email')
					->bind(':body', $body, ParameterType::STRING)
					->bind(':name', $name, ParameterType::STRING)
					->bind(':newemail', $deletedEmail, ParameterType::STRING)
					->bind(':email', $email, ParameterType::STRING);
				$db->setQuery($q)->execute();
			}

			$cid = array_values(array_unique(array_map('intval', array_filter($cid, function ($id) {
				return is_numeric($id) && ($id > 0);
			}))));

			/**
			 * If converting the comments to guest comments (when the user record itself is deleted) we need to do some more
			 * post processing for these comments: name and email as above, and created_by = 0 (0.7.1), so that no user ID is
			 * left that a future account could inherit (some database servers reuse the highest ID after a restart).
			 */
			if ($convertToGuest && !empty($cid))
			{
				$deletedEmail = sprintf('deleted.%u@%s', $userId, $host);

				foreach (array_chunk($cid, 500) as $chunk)
				{
					$q = $newQuery()
						->update($db->qn('#__engage_comments'))
						->set([
							$db->qn('body') . ' = :body',
							$db->qn('name') . ' = :name',
							$db->qn('email') . ' = :newemail',
							$db->qn('created_by') . ' = 0',
							$db->qn('ip') . ' = NULL',
							$db->qn('user_agent') . " = ''",
						])
						->whereIn($db->qn('id'), $chunk, ParameterType::INTEGER)
						->bind(':body', $body, ParameterType::STRING)
						->bind(':name', $name, ParameterType::STRING)
						->bind(':newemail', $deletedEmail, ParameterType::STRING);
					$db->setQuery($q)->execute();
				}
			}

			// Remove #__engage_unsubscribe records
			if ($email !== '')
			{
				$q = $newQuery()
					->delete($db->qn('#__engage_unsubscribe'))
					->where($db->qn('email') . ' = :email')
					->bind(':email', $email, ParameterType::STRING);
				$db->setQuery($q)->execute();
			}

			$db->transactionCommit();
		}
		catch (Throwable $e)
		{
			$db->transactionRollback();

			throw $e;
		}

		return $cid;
	}

	/**
	 * Get the MVC factory for the component
	 *
	 * @return  MVCFactoryInterface|null
	 * @throws  Exception
	 * @since   3.0.0
	 */
	private static function getMVCFactory(): ?MVCFactoryInterface
	{
		if (!empty(self::$mvcFactory))
		{
			return self::$mvcFactory;
		}

		$component = Factory::getApplication()->bootComponent('com_engage');

		if (!$component instanceof MVCFactoryServiceInterface)
		{
			self::$mvcFactory = null;

			return null;
		}

		self::$mvcFactory = $component->getMVCFactory();

		return self::$mvcFactory;
	}
}