<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\ListOrdering;
use Akeeba\Component\Engage\Administrator\Helper\Reactions;
use Akeeba\Component\Engage\Administrator\Helper\Timer;
use Akeeba\Component\Engage\Administrator\Mixin\ModelPopulateStateTrait;
use DateInterval;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseQuery;
use Joomla\Database\ParameterType;

/**
 * Backend comments list model
 *
 * @since 3.0.0
 */
#[\AllowDynamicProperties]
class CommentsModel extends ListModel
{
	use ModelPopulateStateTrait;

	/**
	 * The number of tree-aware comments fetched by commentIDTreeSliceWithDepth
	 *
	 * @var   int
	 * @see   self::commentIDTreeSliceWithDepth
	 * @since 1.0.0
	 */
	private $treeAwareCount = null;

	/**
	 * Constructor
	 *
	 * @param   array                $config   An array of configuration options (name, state, dbo, table_path, ignore_request).
	 * @param   MVCFactoryInterface  $factory  The factory.
	 *
	 * @since   3.0.0
	 * @throws  Exception
	 */
	public function __construct($config = [], ?MVCFactoryInterface $factory = null)
	{
		$config['filter_fields'] = $config['filter_fields'] ?? [
				// Sortable and/or filter columns
				'id', 'asset_id', 'name', 'email', 'ip', 'user_agent', 'enabled',
				'created', 'created_by', 'modified', 'modified_by',
				'categories_include', 'categories_exclude',
				// Sort–only fields
				'c.id', 'user_name', 'c.enabled', 'c.created',
				// Filter–only fields
				'search', 'from', 'to',
			];

		parent::__construct($config, $factory);

		$this->setupStateFilters(
			[
				// Visible filters
				'search'             => 'string',
				'from'               => 'string',
				'to'                 => 'string',
				'created_by'         => 'int',
				'enabled'            => 'int',

				// Internal filters
				'asset_id'           => 'int',
				'parent_id'          => 'int',
				'frontend'           => 'int',
				'categories_include' => 'array',
				'categories_exclude' => 'array',
			], 'c.created', 'DESC');
	}

	/**
	 * Get the number of tree–aware comments fetched by commentIDTreeSliceWithDepth
	 *
	 * @return   int
	 * @since    1.0.0
	 */
	public function getTreeAwareCount(): int
	{
		if (is_null($this->treeAwareCount))
		{
			$this->commentIDTreeSliceWithDepth(0);
		}

		return $this->treeAwareCount ?? 0;
	}

	/**
	 * Tree-aware version of getItems(), returning a slice of the tree.
	 *
	 * @param   int|null  $start  Starting offset
	 * @param   int|null  $limit  Max number of items to retrieve
	 *
	 * @return  array
	 * @since   1.0.0
	 * @see     self::get
	 */
	public function commentTreeSlice(?int $start = null, ?int $limit = null): array
	{
		$start = $start ?? $this->getStart();
		$limit = $limit ?? $this->getState('list.limit');

		// Get a slice of comment IDs and their depth in tree listing order
		$idsAndDepth = $this->commentIDTreeSliceWithDepth($start, $limit);

		// No IDs? No items!
		if (empty($idsAndDepth))
		{
			return [];
		}

		// Get the comments with the IDs specified. They are NOT in order.
		$db = $this->getDatabase();
		$query = $this->getListQuery()
			->whereIn($db->quoteName('c.id'), array_map('trim', array_keys($idsAndDepth)))
			->clear('order');
		$items = $db->setQuery($query)->loadObjectList('id');

		// Create a new collection
		$ret = [];

		/**
		 * Distribute the items to the collection in the order they SHOULD appear.
		 *
		 * Magic trick: since the collection internally has an array consisting entirely of objects, creating a second
		 * collection referencing the same objects has minimal overhead. The reason is that objects are stored in arrays
		 * as references. Adding the same object to two arrays only adds its reference to the array, without copying the
		 * actual object. This helps keep memory pressure low while we are rearranging our items in an arbitrary order.
		 * Neat, huh?
		 */
		foreach ($idsAndDepth as $id => $depth)
		{
			$id = (int) $id;

			if (!isset($items[$id]))
			{
				continue;
			}

			// When adding the item to the collection we also need to set its level information.
			$item        = $items[$id];
			$item->depth = $depth;
			$ret[]       = $item;
		}

		return $ret;
	}

	/**
	 * Automatically deletes obsolete spam comments older than this many days, using an upper execution time limit.
	 *
	 * If the $maxDays == 0 nothing is deleted; we return without querying the database.
	 *
	 * If there are numerous spam comments this method will delete at least one chunk (100 comments). It will keep on
	 * going until the maxExecutionTime limit is reached or exceeded; or until there are no more spam comments left to
	 * delete.
	 *
	 * Use $maxExecutionTime=0 to only delete up to 100 comments.
	 *
	 * @param   int  $maxDays           Spam older than this many days will be automatically deleted
	 * @param   int  $maxExecutionTime  Maximum time to spend cleaning obsolete spam
	 *
	 * @return  int  Total number of spam comments deleted.
	 * @since   1.0.0
	 */
	public function cleanSpam(int $maxDays = 15, int $maxExecutionTime = 1): int
	{
		$timer   = new Timer($maxExecutionTime, 100);
		$deleted = 0;

		do
		{
			$deletedNow = $this->cleanSpamChunk($maxDays);
			$deleted    += $deletedNow;

			if ($deletedNow === 0)
			{
				break;
			}
		} while ($timer->getTimeLeft() > 0.01);

		return $deleted;
	}

	/**
	 * Get a slice of comment IDs with depth (level) information.
	 *
	 * The comment ID slice is aware of the tree nature of the comments.
	 *
	 * Use $start=0 and $limit=null to retrieve the entire tree
	 *
	 * @param   int       $start  Starting offset of the slice
	 * @param   int|null  $limit  Maximum number of elements to retrieve
	 *
	 * @return  array  An array of id => depth
	 * @since   1.0.0
	 */
	public function commentIDTreeSliceWithDepth(int $start, ?int $limit = null): array
	{
		// Get all the IDs filtered by the model
		$db     = $this->getDatabase();
		$query  = $this->getListQuery(true)
			->clear('select')
			->select([
				$db->qn('c.id'),
				$db->qn('c.parent_id'),
			]);

		// 0.8.0: sort modes chosen by the visitor (closed list, see ListOrdering::sortMode) and the "only my favourites" flat list
		$mode     = ListOrdering::sortMode($this->getState('list.sortmode'));
		$favorite = $this->favoriteUserId() > 0;
		$assetId  = (int) $this->getState('filter.asset_id');

		if ($mode === 'top' && $assetId <= 0)
		{
			// The score subquery is limited to one content item; without it fall back to the date order
			$mode = 'newest';
		}

		if ($mode !== null)
		{
			$query->select($db->qn('c.created'));
			$query->clear('order');

			if ($mode === 'top')
			{
				$this->joinReactionScore($query, $assetId);
			}

			foreach (ListOrdering::sortOrderBy($mode) as [$column, $direction])
			{
				// Column and direction come from the fixed table of ListOrdering, never from the request
				$query->order($db->quoteName($column) . ' ' . ($direction === 'ASC' ? 'ASC' : 'DESC'));
			}
		}

		$allIDs = $db->setQuery($query)->loadAssocList('id') ?? [];

		$this->treeAwareCount = 0;

		// No IDs? Empty list!
		if (empty($allIDs))
		{
			return [];
		}

		$this->treeAwareCount = count($allIDs);

		// Flat list (favourites): no tree, every comment at the first level, in the order of the query
		if ($favorite)
		{
			$flat = [];

			foreach (array_keys($allIDs) as $id)
			{
				$flat[" " . $id] = 1;
			}

			return ($limit > 0) ? array_slice($flat, $start, $limit, true) : array_slice($flat, $start, null, true);
		}

		// With a sort mode the first level follows the mode and the replies of each comment are always oldest first
		if ($mode !== null)
		{
			$allIDs = $this->repliesOldestFirst($allIDs);
		}

		// Convert into an ID => parent array
		$allIDs = array_map(function ($x) {
			return $x['parent_id'] ?: null;
		}, $allIDs);

		// Filter out orphan nodes (children of deleted or unpublished comments)
		$allIDs = array_filter($allIDs, function ($parent_id) use ($allIDs) {
			return is_null($parent_id) || array_key_exists($parent_id, $allIDs);
		});

		/**
		 * Create a tree version of the comments and flatten it out
		 *
		 * Starting at parent id NULL forces makeIDTree to start from the first level nodes that have no parents.
		 */
		$flattened = $this->flattenIDTree($this->makeIDTree($allIDs, null));

		unset($allIDs);

		if ($limit > 0)
		{
			return array_slice($flattened, $start, $limit, true);
		}

		return array_slice($flattened, $start, null, true);
	}

	/**
	 * Keeps the first level comments in the order they come in and puts every reply after them, oldest first (date, then ID).
	 * The tree builder takes the children of a comment in the order they appear, so this is what makes the replies chronological
	 * whatever the sort mode of the first level is.
	 *
	 * @param   array  $rows  id => [id, parent_id, created, ...] in query order
	 *
	 * @return  array
	 * @since   0.8.0
	 */
	private function repliesOldestFirst(array $rows): array
	{
		$roots   = [];
		$replies = [];

		foreach ($rows as $id => $row)
		{
			if (empty($row['parent_id']))
			{
				$roots[$id] = $row;
			}
			else
			{
				$replies[$id] = $row;
			}
		}

		uasort($replies, static function (array $a, array $b): int {
			return [(string) ($a['created'] ?? ''), (int) $a['id']] <=> [(string) ($b['created'] ?? ''), (int) $b['id']];
		});

		return $roots + $replies;
	}

	/**
	 * ID of the user whose favourites are listed (filter.favorite_user), or 0. Only ever a positive integer.
	 *
	 * @return  int
	 * @since   0.8.0
	 */
	private function favoriteUserId(): int
	{
		$v = $this->getState('filter.favorite_user');

		return (is_int($v) && $v > 0) ? $v : 0;
	}

	/**
	 * Adds to the query the net score of the reactions of each comment of ONE content item: likes minus dislikes (dislikes only
	 * count when the option shows them), leaving out the reactions that the author gave to his own comment, exactly as the counters
	 * that people see (ReactionStore::counts). A single aggregated subquery over #__engage_reactions joined on its comment_id
	 * index; all values are bound. Comments without reactions get 0.
	 *
	 * @param   DatabaseQuery  $query
	 * @param   int            $assetId  The content item
	 *
	 * @return  void
	 * @since   0.8.0
	 */
	private function joinReactionScore(DatabaseQuery $query, int $assetId): void
	{
		$db      = $this->getDatabase();
		$like    = Reactions::LIKE;
		$dislike = Reactions::DISLIKE;
		$useDis  = $this->getState('filter.score_dislikes', true) !== false;
		$types   = $useDis ? [$like, $dislike] : [$like];
		$sub     = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->select([
				$db->quoteName('r.comment_id'),
				'SUM(CASE ' . $db->quoteName('r.type') . ' WHEN ' . (int) $like . ' THEN 1 WHEN ' . (int) $dislike . ' THEN -1 ELSE 0 END) AS ' . $db->quoteName('score'),
			])
			->from($db->quoteName('#__engage_reactions', 'r'))
			->join('INNER', $db->quoteName('#__engage_comments', 'sc'), $db->quoteName('sc.id') . ' = ' . $db->quoteName('r.comment_id'))
			->join('LEFT', $db->quoteName('#__users', 'su'), $db->quoteName('su.id') . ' = ' . $db->quoteName('r.user_id'))
			->where($db->quoteName('sc.asset_id') . ' = :rs_asset')
			->where($db->quoteName('r.type') . ' IN (' . implode(',', array_map('intval', $types)) . ')')
			->where($db->quoteName('r.user_id') . ' <> IFNULL(' . $db->quoteName('sc.created_by') . ', 0)')
			->where('NOT (IFNULL(' . $db->quoteName('sc.created_by') . ', 0) <= 0 AND IFNULL(' . $db->quoteName('sc.email') . ", '') <> '' AND " . $db->quoteName('sc.email') . ' = ' . $db->quoteName('su.email') . ')')
			->group($db->quoteName('r.comment_id'));

		$query
			->select('COALESCE(' . $db->quoteName('rs.score') . ', 0) AS ' . $db->quoteName('reaction_score'))
			->join('LEFT', '(' . $sub . ') AS ' . $db->quoteName('rs') . ' ON ' . $db->quoteName('rs.comment_id') . ' = ' . $db->quoteName('c.id'))
			->bind(':rs_asset', $assetId, ParameterType::INTEGER);
	}

	/**
	 * Utility function that converts an array of id => parent_id into a tree representation of IDs.
	 *
	 * @param   array     $allIDs    The source array of id => parent_id entries
	 * @param   int|null  $parentId  The parent ID to retrieve
	 *
	 * @return  array
	 * @see     self::commentIDTreeSliceWithDepth
	 * @since   1.0.0
	 */
	protected function makeIDTree(array &$allIDs, ?int $parentId): array
	{
		$childIDs = array_keys($allIDs, $parentId);

		if (empty($childIDs))
		{
			return [];
		}

		$ret = [];

		foreach ($childIDs as $thisParentId)
		{
			$ret[$thisParentId] = $this->makeIDTree($allIDs, $thisParentId);
		}

		return $ret;
	}

	/**
	 * Converts a tree of IDs into a flat array of ID => depth preserving ID order as seen in the tree.
	 *
	 * @param   array  $tree         The tree array
	 * @param   int    $parentLevel  Which level am I currently in
	 *
	 * @return  array
	 * @since   1.0.0
	 * @see     self::commentIDTreeSliceWithDepth
	 * @see     self::makeIDTree
	 */
	protected function flattenIDTree(array $tree, $parentLevel = 0): array
	{
		$ret = [];

		foreach ($tree as $k => $v)
		{
			$ret[" " . $k] = $parentLevel + 1;

			if (!empty($v) && is_array($v))
			{
				$ret = array_merge($ret, $this->flattenIDTree($v, $parentLevel + 1));
			}
		}

		return $ret;
	}

	/**
	 * Automatically deletes up to 100 spam comments which are older than this many days.
	 *
	 * @param   int  $maxDays
	 *
	 * @return  int  Number of spam comments deleted
	 * @since   1.0.0
	 */
	private function cleanSpamChunk(int $maxDays = 15): int
	{
		$maxDays = max(0, $maxDays);

		if ($maxDays === 0)
		{
			return 0;
		}

		try
		{
			$interval     = new DateInterval(sprintf('P%uD', $maxDays));
			$earliestDate = (clone Factory::getDate())->sub($interval);
		}
		catch (Exception $e)
		{
			return 0;
		}

		/** @var self $model */
		$model = $this->getMVCFactory()->createModel('Comments', 'Administrator', [
			'ignore_request' => true,
		]);
		$model->setState('filter.enabled', -3);
		$model->setState('filter.to', $earliestDate->toISO8601());
		$obsoleteSpam = $model->getItems();

		if (empty($obsoleteSpam))
		{
			return 0;
		}

		$spamIds = array_map(function ($x) {
			return $x->id;
		}, $obsoleteSpam);
		$spamIds = array_unique($spamIds);

		if (empty($spamIds))
		{
			return 0;
		}

		/** @var CommentModel $commentModel */
		$commentModel = $this->getMVCFactory()->createModel('Comment', 'Administrator', [
			'ignore_request' => true,
		]);

		if (!$commentModel->delete($spamIds))
		{
			throw new \RuntimeException($commentModel->getError());
		}

		return count($spamIds);
	}

	/**
	 * Get a DatabaseQuery object for retrieving the data from the database table.
	 *
	 * @return  DatabaseQuery  A DatabaseQuery object to retrieve the data.
	 *
	 * @since   3.0
	 */
	protected function getListQuery()
	{
		$db    = $this->getDatabase();
		$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->select([
				$db->quoteName('c') . '.*',
				'IFNULL(' . $db->quoteName('u.name') . ', ' . $db->quoteName('c.name') . ') AS ' . $db->quoteName('user_name'),
				'IFNULL(' . $db->quoteName('u.email') . ', ' . $db->quoteName('c.email') . ') AS ' . $db->quoteName('user_email'),
				$db->quoteName('a.title', 'article_title'),
				$db->quoteName('a.alias', 'article_alias'),
				$db->quoteName('a.catid', 'article_catid'),
				$db->quoteName('cat.title', 'cat_title'),
				$db->quoteName('cat.alias', 'cat_alias'),
			])
			->from($db->quoteName('#__engage_comments', 'c'))
			->join('LEFT', $db->quoteName('#__users', 'u'),
				$db->quoteName('u.id') . ' = ' . $db->quoteName('c.created_by')
			)
			->join('LEFT', $db->quoteName('#__content', 'a'),
				$db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id')
			)
			->join('LEFT', $db->quoteName('#__categories', 'cat'),
				$db->quoteName('cat.id') . ' = ' . $db->quoteName('a.catid')
			);

		// Frontend listing filter
		$fltFrontend = $this->getState('filter.frontend');

		if ($fltFrontend === 1)
		{
			$user       = Factory::getApplication()->getIdentity() ?? (new User());
			$userAccess = $user->getAuthorisedViewLevels() ?: [];
			$query
				->select([
					$db->quoteName('a.id', 'article_id'),
					$db->quoteName('cat.id', 'cat_id'),
				])
				->where($db->quoteName('cat.published') . ' = 1')
				->where($db->quoteName('a.state') . ' = 1')
				->whereIn($db->quoteName('a.access'), $userAccess, ParameterType::INTEGER)
				->whereIn($db->quoteName('cat.access'), $userAccess, ParameterType::INTEGER);
		}

		// Include Categories Filter
		$fltCatInclude = $this->getState('filter.categories_include', []);

		if (is_array($fltCatInclude) && !empty($fltCatInclude))
		{
			$query->whereIn($db->quoteName('cat.id'), $fltCatInclude);
		}

		// Exclude Categories Filter
		$fltCatExclude = $this->getState('filter.categories_exclude', []);

		if (is_array($fltCatExclude) && !empty($fltCatExclude))
		{
			$query->whereNotIn($db->quoteName('cat.id'), $fltCatExclude);
		}

		// Asset ID filter
		$fltAssetId = $this->getState('filter.asset_id');

		if (is_numeric($fltAssetId) && ($fltAssetId > 0))
		{
			$query->where($db->quoteName('c.asset_id') . ' = :asset_id')
				->bind(':asset_id', $fltAssetId, ParameterType::INTEGER);
		}

		// Parent ID filter
		$fltParentid = $this->getState('filter.parent_id');

		if (is_numeric($fltParentid) && ($fltParentid > 0))
		{
			$query->where($db->quoteName('c.parent_id') . ' = :parent_id')
				->bind(':parent_id', $fltParentid, ParameterType::INTEGER);
		}

		// 0.8.0: only the comments that this user marked as favourite (type 3). The ID is a bound integer taken from the session by
		// the controller; the join also narrows the query that loads the items of the page.
		$fltFavorite = $this->favoriteUserId();

		if ($fltFavorite > 0)
		{
			$favType = Reactions::FAVORITE;
			$query->join('INNER', $db->quoteName('#__engage_reactions', 'fv'),
				$db->quoteName('fv.comment_id') . ' = ' . $db->quoteName('c.id')
				. ' AND ' . $db->quoteName('fv.user_id') . ' = :fav_user AND ' . $db->quoteName('fv.type') . ' = :fav_type'
			)
				->bind(':fav_user', $fltFavorite, ParameterType::INTEGER)
				->bind(':fav_type', $favType, ParameterType::INTEGER);
		}

		// Search filter
		$fltSearch    = $this->getState('filter.search');
		$fltCreatedBy = $this->getState('filter.created_by');

		if (!empty($fltSearch))
		{
			if (substr($fltSearch, 0, 3) === 'id:')
			{
				$fsValue = substr($fltSearch, 3);

				$query->where($db->quoteName('c.id') . ' = :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::INTEGER);
			}
			if (substr($fltSearch, 0, 3) === 'ip:')
			{
				$fsValue = '%' . substr($fltSearch, 3) . '%';

				$query->where($db->quoteName('c.ip') . ' LIKE :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::STRING);
			}
			elseif (substr($fltSearch, 0, 5) === 'user:')
			{
				$fltCreatedBy = null;
				$fsValue      = '%' . substr($fltSearch, 5) . '%';

				if ($query->where === null)
				{
					// So that extendWhere() doesn't output bad SQL
					$query->where('1=1');
				}

				$query->extendWhere('AND', [
					$db->quoteName('u.name') . ' LIKE :filter_search_1',
					$db->quoteName('c.name') . ' LIKE :filter_search_2',
					$db->quoteName('u.email') . ' LIKE :filter_search_3',
					$db->quoteName('c.email') . ' LIKE :filter_search_3',
				], 'OR')
					->bind(':filter_search_1', $fsValue, ParameterType::STRING)
					->bind(':filter_search_2', $fsValue, ParameterType::STRING)
					->bind(':filter_search_3', $fsValue, ParameterType::STRING)
					->bind(':filter_search_4', $fsValue, ParameterType::STRING);
			}
			elseif (substr($fltSearch, 0, 9) === 'username:')
			{
				$fsValue = '%' . substr($fltSearch, 9) . '%';
				$query->where($db->quoteName('u.username') . ' LIKE :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::STRING);
			}
			elseif (substr($fltSearch, 0, 6) === 'title:')
			{
				$fsValue = '%' . substr($fltSearch, 6) . '%';
				$query->where($db->quoteName('a.asset_id') . ' = :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::INTEGER);
			}
			elseif (substr($fltSearch, 0, 8) === 'comment:')
			{
				$fsValue = '%' . substr($fltSearch, 8) . '%';

				$query->where($db->quoteName('c.body') . 'LIKE :filter_search')
					->bind(':filter_search', $fsValue, ParameterType::STRING);
			}
			else
			{
				$fsValue = '%' . $fltSearch . '%';

				if ($query->where === null)
				{
					// So that extendWhere() doesn't output bad SQL
					$query->where('1=1');
				}

				$query->extendWhere('AND', [
					$db->quoteName('c.body') . 'LIKE :filter_search',
					$db->quoteName('u.name') . ' LIKE :filter_search_1',
					$db->quoteName('c.name') . ' LIKE :filter_search_2',
					$db->quoteName('u.email') . ' LIKE :filter_search_3',
					$db->quoteName('c.email') . ' LIKE :filter_search_4',
				], 'OR')
					->bind(':filter_search', $fsValue, ParameterType::STRING)
					->bind(':filter_search_1', $fsValue, ParameterType::STRING)
					->bind(':filter_search_2', $fsValue, ParameterType::STRING)
					->bind(':filter_search_3', $fsValue, ParameterType::STRING)
					->bind(':filter_search_4', $fsValue, ParameterType::STRING);
			}
		}

		// Created By filter
		if (is_numeric($fltCreatedBy) && ($fltCreatedBy > 0))
		{
			$query->where($db->quoteName('c.created_by') . ' = :created_by')
				->bind(':created_by', $fltCreatedBy, ParameterType::INTEGER);
		}

		// Enabled filter
		$fltEnabled = $this->getState('filter.enabled');

		if (is_numeric($fltEnabled))
		{
			$query->where($db->quoteName('c.enabled') . ' = :enabled')
				->bind(':enabled', $fltEnabled, ParameterType::INTEGER);
		}

		// From/to filter
		$fltFrom = $this->getState('filter.from');
		$fltTo   = $this->getState('filter.to');

		// -- Convert to Joomla date objects
		try
		{
			$fltFrom = $fltFrom ? Factory::getDate($fltFrom) : null;
		}
		catch (Exception $e)
		{
			$fltFrom = null;
		}

		try
		{
			$fltTo = $fltTo ? Factory::getDate($fltTo) : null;
		}
		catch (Exception $e)
		{
			$fltTo = null;
		}

		// Swap dates if both are defined but from is later than to.
		if (!empty($fltTo) && !empty($fltFrom) && ($fltFrom > $fltTo))
		{
			$temp    = $fltFrom;
			$fltFrom = $fltTo;
			$fltTo   = $temp;
			unset($temp);
		}

		if (!empty($fltTo) && !empty($fltFrom))
		{
			$sFrom = $fltFrom->toSql();
			$sTo   = $fltTo->toSql();
			$query->where($db->quoteName('c.created') . ' BETWEEN :from AND :to')
				->bind(':from', $sFrom, ParameterType::STRING)
				->bind(':to', $sTo, ParameterType::STRING);
		}
		elseif (!empty($fltFrom))
		{
			$sFrom = $fltFrom->toSql();
			$query->where($db->quoteName('c.created') . ' >= :from')
				->bind(':from', $sFrom, ParameterType::STRING);
		}
		elseif (!empty($fltTo))
		{
			$sTo = $fltTo->toSql();
			$query->where($db->quoteName('c.created') . ' <= :to')
				->bind(':to', $sTo, ParameterType::STRING);
		}

		// List ordering clause
		// Lista blanca: nunca se usa tal cual el valor del estado (puede venir de la petición del visitante).
		$orderCol  = ListOrdering::column($this->state->get('list.ordering', 'c.created'));
		$orderDirn = ListOrdering::direction($this->state->get('list.direction', 'DESC'));
		$ordering  = $db->quoteName($orderCol) . ' ' . $db->escape($orderDirn);

		/**
		 * -- When ordering by a column other that the comment ID apply an additional ordering to make sure that the
		 *    comments always appear in the same order. Otherwise if there are two or more comments filed on the same
		 *    date and time (to the second) and we're sorting by date they would appear in a different order every time
		 *    we load the page. Same for the user_name and enabled status.
		 */
		if ($orderCol != 'c.id')
		{
			$ordering .= ', ' . $db->quoteName('c.id') . ' DESC';
		}

		$query->order($ordering);

		return $query;
	}

	protected function getStoreId($id = '')
	{
		// serialize(): los filtros pueden llegar como array desde la petición (filter[search][]=x) y no se pueden concatenar.
		$id .= ':' . serialize($this->getState('filter.search'));
		$id .= ':' . serialize($this->getState('filter.from'));
		$id .= ':' . serialize($this->getState('filter.to'));
		$id .= ':' . serialize($this->getState('filter.created_by'));
		$id .= ':' . serialize($this->getState('filter.enabled'));
		$id .= ':' . serialize($this->getState('filter.asset_id'));
		$id .= ':' . serialize($this->getState('filter.parent_id'));
		$id .= ':' . serialize($this->getState('filter.frontend'));
		$id .= ':' . serialize($this->getState('filter.categories_include'));
		$id .= ':' . serialize($this->getState('filter.categories_exclude'));
		$id .= ':' . serialize($this->getState('list.sortmode'));
		$id .= ':' . serialize($this->getState('filter.favorite_user'));
		$id .= ':' . serialize($this->getState('filter.score_dislikes'));

		return parent::getStoreId($id);
	}

	public function clearCache($id = '')
	{
		unset($this->cache[$this->getStoreId($id)]);
	}

	/**
	 * Get the latest commented articles, with their corresponding latest comment's information.
	 *
	 * @param   int  $numArticles  Up to how many articles to return
	 *
	 * @return  array
	 * @since   3.4.0
	 */
	public function getLatestArticles(int $numArticles = 10): array
	{
		$db = $this->getDatabase();
		/** @var DatabaseQuery $query */
		$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true));
		$query->select('DISTINCT ' . $db->quoteName('c.asset_id'))
			->from($db->quoteName('#__engage_comments', 'c'))
			->join('LEFT', $db->quoteName('#__content', 'a'),
				$db->quoteName('a.asset_id') . ' = ' . $db->quoteName('c.asset_id')
			)
			->order($db->quoteName('c.created') . ' DESC')
			->setLimit($numArticles, 0);

		// Include Categories Filter
		$fltCatInclude = $this->getState('filter.categories_include', []);

		if (is_array($fltCatInclude) && !empty($fltCatInclude))
		{
			$query->whereIn($db->quoteName('a.catid'), $fltCatInclude);
		}

		// Exclude Categories Filter
		$fltCatExclude = $this->getState('filter.categories_exclude', []);

		if (is_array($fltCatExclude) && !empty($fltCatExclude))
		{
			$query->whereNotIn($db->quoteName('a.catid'), $fltCatExclude);
		}

		try
		{
			$assetIds = $db->setQuery($query)->loadColumn();
		}
		catch (Exception $e)
		{
			return [];
		}

		if (empty($assetIds))
		{
			return [];
		}

		$stashAssetId = $this->getState('filter.asset_id');
		$stashStart   = $this->getState('list.start');
		$stashLimit   = $this->getState('list.limit');

		$ret = [];

		foreach ($assetIds as $assetId)
		{
			$this->setState('filter.asset_id', $assetId);
			$this->setState('list.start', 0);
            $this->setState('list.limit', 1);

			$ret = array_merge($ret, $this->getItems() ?: []);
		}

		$this->setState('filter.asset_id', $stashAssetId);
		$this->setState('list.start', $stashStart);
		$this->setState('list.limit', $stashLimit);

		return $ret;
	}
}
