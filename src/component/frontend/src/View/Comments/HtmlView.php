<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\View\Comments;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\CommentTools;
use Akeeba\Component\Engage\Administrator\Helper\ListOrdering;
use Akeeba\Component\Engage\Administrator\Helper\ReactionIcons;
use Akeeba\Component\Engage\Administrator\Helper\Reactions;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Mixin\ViewLoadAnyTemplateTrait;
use Akeeba\Component\Engage\Site\Helper\Meta;
use Akeeba\Component\Engage\Site\Mixin\ModuleRenderAware;
use Akeeba\Component\Engage\Site\Model\CommentModel;
use Akeeba\Component\Engage\Site\Model\CommentsModel;
use DateTimeZone;
use Exception;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Throwable;

class HtmlView extends BaseHtmlView
{
	use ViewLoadAnyTemplateTrait;
	use ModuleRenderAware;

	/**
	 * Are the comments closed for this content item?
	 *
	 * @var   bool
	 * @since 1.0.0
	 */
	public $areCommentsClosed = false;

	/**
	 * The asset ID to display comments for.
	 *
	 * @var   int
	 * @since 1.0.0
	 */
	public $assetId;

	/**
	 * Language key for displaying the header (number of comments).
	 *
	 * Generic default: COM_ENGAGE_COMMENTS_HEADER_N_COMMENTS
	 *
	 * You can define language strings such as COM_ENGAGE_COMMENTS_CONTENTTYPE_HEADER_N_COMMENTS where CONTENTTYPE is
	 * the content type returned by the Akeeba Engage plugins. For example, for Joomla articles you can use the language
	 * key COM_ENGAGE_COMMENTS_ARTICLE_HEADER_N_COMMENTS.
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	public $headerKey = 'COM_ENGAGE_COMMENTS_HEADER_N_COMMENTS';

	/**
	 * Maximum comment nesting level.
	 *
	 * This is only used for replies. You can reply directly to $maxLevel-1 level comments. Replies to $maxLevel or
	 * deeper comments will be in reply to the $maxLevel-1 parent.
	 *
	 * For example, if $maxLevel = 3 (default) you can file a new top level comment or reply to the first and second
	 * level comments. Replying to a third level comment will actually be in reply to its second level parent comment.
	 *
	 * Imposing a nesting cap prevents excessive margins when displaying comments in a hot conversation, e.g. when two
	 * users are clearly exchanging banter. Think about what happens on YouTube comments and how it caps nesting to two
	 * levels only. It's the same idea here.
	 *
	 * @var   int
	 * @since 1.0.0
	 */
	public $maxLevel = 3;

	/**
	 * Show an "In reply to <name>" note, linked to the parent comment, on every reply (component option reply_show_quote).
	 *
	 * @var   bool
	 * @since 0.6.21
	 */
	public $showInReplyTo = true;

	/**
	 * Indentation of each nesting level: none, small, medium (default) or large (component option reply_indent).
	 * Only whitelisted values are ever stored here, because it ends up in a CSS class name.
	 *
	 * @var   string
	 * @since 0.6.21
	 */
	public $replyIndent = 'medium';

	/**
	 * Visual mark of the replies: line (default), soft or none (component option reply_style). Whitelisted.
	 *
	 * @var   string
	 * @since 0.6.21
	 */
	public $replyStyle = 'line';

	/**
	 * Visual theme of the comments: classic (default, no extra CSS), modern, minimal or dark (component option theme).
	 * Only whitelisted values are ever stored here, because it ends up in a CSS class name.
	 *
	 * @var   string
	 * @since 0.6.23
	 */
	public $theme = 'classic';

	/**
	 * Avatar on narrow screens (component option mobile_avatar): auto, show or hide. Whitelisted.
	 * Ends up in a CSS class name only through mobileAvatarClass().
	 *
	 * @var   string
	 * @since 0.6.28
	 */
	public $mobileAvatar = 'auto';

	/**
	 * Reaction buttons (component options reactions_*), normalised: enabled, dislike, favorites, who. When disabled the page is
	 * byte for byte the one of the previous version (no skeleton, no script). The per-user state is never rendered here.
	 *
	 * @var   array
	 * @since 0.7.0
	 */
	public $reactions = ['enabled' => false, 'dislike' => true, 'favorites' => true, 'who' => 'registered'];

	/**
	 * Tools of the comments list (component options sort_selector, default_sort, copy_link, show_badges), normalised. With all of them
	 * turned off the page is byte for byte the one of the previous version.
	 *
	 * @var   array
	 * @since 0.8.0
	 */
	public $tools = ['sort_selector' => false, 'default_sort' => 'auto', 'copy_link' => false, 'show_badges' => false];

	/**
	 * What CommentTools::resolve() decided for this request (sort mode, favourites filter, buttons). Set by the controller; when the
	 * view is used without it, display() resolves it without any request parameter.
	 *
	 * @var   array|null
	 * @since 0.8.0
	 */
	public $listState = null;

	/**
	 * Cache, for this request, of "can this user moderate comments" (user ID => bool): one ACL check per person, however many
	 * comments they wrote.
	 *
	 * @var   array
	 */
	private $moderatorCache = [];

	/** @var int|null  created_by of the content item (0 = unknown); loaded on first use */
	private $contentAuthorId = null;

	/**
	 * Currently logged in user's permissions
	 *
	 * @var   array
	 * @since 1.0.0
	 */
	public $perms = [
		// Submit new comments
		'create' => false,
		// Edit any comment
		'edit'   => false,
		// Edit own comments
		'own'    => false,
		// Edit comments' state (pubished, unpublished, spam)
		'state'  => false,
		// Delete comments
		'delete' => false,
	];

	/**
	 * Display title for the resource being commented on
	 *
	 * @var   string|null
	 * @since 1.0.0
	 */
	public $title = null;

	/**
	 * Currently logged in user
	 *
	 * @var   User
	 * @since 1.0.0
	 */
	public $user;

	/**
	 * The current user's preferred timezone
	 *
	 * @var DateTimeZone
	 */
	public $userTimezone = null;

	/**
	 * Are the comments closed because a certain amount of time elapsed since the article's creation?
	 *
	 * @var   bool
	 * @since 3.0.7
	 */
	public $areCommentsClosedAfterTime = false;

	/**
	 * The URL option for the component.
	 *
	 * This is used to automatically determine the template overrides path when using _setPath().
	 *
	 * @var    string
	 * @since  3.0.6
	 */
	protected $option = 'com_engage';

	/**
	 * The comment form
	 *
	 * @var   bool|Form|null
	 * @since 3.0.0
	 */
	private $form;

	/**
	 * Number of comments being displayed
	 *
	 * @var   int
	 * @since 1.0.0
	 */
	private $itemCount;

	/**
	 * Comments to display
	 *
	 * @var   object[]
	 * @since 1.0.0
	 */
	private $items;

	/**
	 * The Joomla pagination object
	 *
	 * @var   Pagination
	 * @since 1.0.0
	 */
	private $pagination;

	/**
	 * Display names of the parent comments needed for the "In reply to" notes. Keys: 'same' (parents listed on this
	 * page) and 'other' (parents on another page of the pagination), each an array of comment ID => display name.
	 *
	 * @var   array
	 * @since 0.6.21
	 */
	private $replyToNames = ['same' => [], 'other' => []];

	/** @inheritDoc */
	public function display($tpl = null)
	{
		$this->setLayout('default');

		// The view object can be reused for several articles in the same request (blog layouts): nothing of the previous one may stay
		$this->contentAuthorId = null;
		$this->_setPath('template', [
			JPATH_SITE . '/components/com_engage/tmpl/comments',
		]);
		$this->_setPath('helper', [
			JPATH_SITE . '/components/com_engage/helpers',
		]);

		// User information
		$this->user         = Factory::getUser();
		$this->perms        = array_merge($this->perms, [
				'create' => $this->user->authorise('core.create', 'com_engage'),
				'edit'   => $this->user->authorise('core.edit', 'com_engage'),
				'own'    => $this->user->authorise('core.edit.own', 'com_engage'),
				'state'  => $this->user->authorise('core.edit.state', 'com_engage'),
				'delete' => $this->user->authorise('core.delete', 'com_engage'),
			]
		);
		$this->userTimezone = $this->getUserTimezone();

		// Load the model and persist its state in the session
		/** @var CommentsModel $model */
		$model = $this->getModel();
		$model->setState('filter.asset_id', $this->assetId);

		// Only show unpublished comments to users who can publish and unpublish comments
		if (!$this->perms['state'])
		{
			$model->setState('filter.enabled', 1);
		}

		// Populate display items and total item count
		$this->items     = $model->commentTreeSlice();
		$this->itemCount = $model->getTreeAwareCount();

		// Populate the pagination object
		$this->pagination         = $model->getPagination();
		$this->pagination->prefix = 'akengage_';
		$this->pagination->setAdditionalUrlParam('akengage_cid', '');

		// 0.8.0: the sort chosen by the visitor and the favourites list survive the pagination links
		if (!empty($this->listState['explicit']))
		{
			$this->pagination->setAdditionalUrlParam(CommentTools::PARAM_SORT, (string) $this->listState['explicit']);
		}

		if (!empty($this->listState['favorites']))
		{
			$this->pagination->setAdditionalUrlParam(CommentTools::PARAM_FAV, '1');
		}

		// Asset metadata-based properties
		$meta        = Meta::getAssetAccessMeta($this->assetId, true);
		$this->title = $meta['title'];

		if (!empty($meta['title']))
		{
			$this->headerKey = $this->getHeaderKey($meta['type']) ?? 'COM_ENGAGE_COMMENTS_HEADER_N_COMMENTS';
		}

		$this->areCommentsClosed          = Meta::areCommentsClosed($this->assetId);
		$this->areCommentsClosedAfterTime = Meta::areCommentsClosedAfterTime($this->assetId);

		// Populate properties based on component parameters
		$params         = ComponentHelper::getParams('com_engage');
		$this->maxLevel = $params->get('max_level', 3);

		// Look of the replies (0.6.21). "In reply to" notes are enabled by default; reply_show_quote = 0 turns them off.
		$this->showInReplyTo = ((int) $params->get('reply_show_quote', 1)) === 1;
		$replyIndent         = (string) $params->get('reply_indent', 'medium');
		$replyStyle          = (string) $params->get('reply_style', 'line');
		$this->replyIndent   = in_array($replyIndent, ['none', 'small', 'medium', 'large'], true) ? $replyIndent : 'medium';
		$this->replyStyle    = in_array($replyStyle, ['line', 'soft', 'none'], true) ? $replyStyle : 'line';
		$theme               = (string) $params->get('theme', 'classic');
		$this->theme         = in_array($theme, ['classic', 'modern', 'minimal', 'dark'], true) ? $theme : 'classic';
		$mobileAvatar        = (string) $params->get('mobile_avatar', 'auto');
		$this->mobileAvatar  = in_array($mobileAvatar, ['auto', 'show', 'hide'], true) ? $mobileAvatar : 'auto';
		$this->reactions     = Reactions::options(static fn(string $k, $d) => $params->get($k, $d));
		$this->tools         = CommentTools::options(static fn(string $k, $d) => $params->get($k, $d));
		$this->listState     = $this->listState ?? CommentTools::resolve($this->tools, $this->reactions, null, null, 'ASC', !$this->user->guest);
		$this->replyToNames  = $this->showInReplyTo ? $this->loadReplyToNames() : ['same' => [], 'other' => []];

		// Page parameters
		/** @var SiteApplication $app */
		try
		{
			$app        = Factory::getApplication();
			$pageParams = $app->getParams();

			if (is_object($pageParams) && ($pageParams instanceof Registry))
			{
				$this->pageParams = $pageParams;
			}
		}
		catch (Exception $e)
		{
			$this->pageParams = null;
		}

		$this->pageParams = $this->pageParams ?? new Registry();

		// Script options and language keys
		$doc = $app->getDocument();

		$baseUrl = Uri::getInstance(Route::_('index.php?option=com_engage'));
		// 0.8.1: relative and validated (no host, no unknown query parameters): this value ends up in HTML that the page cache shares
		$baseUrl->setVar('returnurl', base64_encode(CommentTools::relativeUrl(Uri::getInstance()->toString(['path', 'query']))));
		$baseUrl->setVar($app->getFormToken(), 1);

		$baseUrl->setVar('id', '__ID__');
		$baseUrl->setVar('task', 'comment.edit');
		$doc->addScriptOptions('akeeba.Engage.Comments.editURL', base64_encode($baseUrl->toString()));
		$baseUrl->delVar('id');

		$baseUrl->setVar('cid[]', '__ID__');

		$baseUrl->setVar('task', 'comments.delete');
		$doc->addScriptOptions('akeeba.Engage.Comments.deleteURL', base64_encode($baseUrl->toString()));

		$baseUrl->setVar('task', 'comments.publish');
		$doc->addScriptOptions('akeeba.Engage.Comments.publishURL', base64_encode($baseUrl->toString()));

		$baseUrl->setVar('task', 'comments.unpublish');
		$doc->addScriptOptions('akeeba.Engage.Comments.unpublishURL', base64_encode($baseUrl->toString()));

		$baseUrl->setVar('task', 'comments.reportham');
		$doc->addScriptOptions('akeeba.Engage.Comments.markhamURL', base64_encode($baseUrl->toString()));

		$baseUrl->setVar('task', 'comments.reportspam');
		$doc->addScriptOptions('akeeba.Engage.Comments.markspamURL', base64_encode($baseUrl->toString()));

		$baseUrl->setVar('task', 'comments.possiblespam');
		$doc->addScriptOptions('akeeba.Engage.Comments.possiblespamURL', base64_encode($baseUrl->toString()));

		$doc->addScriptOptions('akeeba.Engage.Comments.pleaseWait', $params->get('pleaseWait', 0) ? 1 : 0);
		Text::script('COM_ENGAGE_COMMENTS_FORM_BTN_SUBMIT_PLEASE_WAIT');

		Text::script('COM_ENGAGE_COMMENTS_DELETE_PROMPT');

		// Reactions (0.7.0): the same script options and texts for every visitor (the page may be cached); the state comes from the AJAX call
		if ($this->reactions['enabled'])
		{
			foreach (['HINT_LOGIN', 'HINT_COMMENTERS', 'HINT_OWN', 'ERR_FAILED', 'ERR_RATE', 'ERR_DENIED'] as $reactionKey)
			{
				Text::script('COM_ENGAGE_REACTIONS_' . $reactionKey);
			}

			$doc->addScriptOptions('akeeba.Engage.Reactions', [
				'stateUrl'  => Route::_('index.php?option=com_engage&task=reactions.state&format=json', false),
				'toggleUrl' => Route::_('index.php?option=com_engage&task=reactions.toggle&format=json', false),
			]);
			$doc->getWebAssetManager()->useScript('com_engage.reactions');
		}

		// Tools (0.8.0): copy link. The same script options and texts for every visitor (cache friendly).
		if ($this->tools['copy_link'])
		{
			Text::script('COM_ENGAGE_TOOLS_COPY_OK');
			Text::script('COM_ENGAGE_TOOLS_COPY_FAIL');
			$doc->addScriptOptions('akeeba.Engage.Tools', ['copy' => true]);
			$doc->getWebAssetManager()->useScript('com_engage.tools');
		}

		$this->prepareHead($doc);

		// Comment form
		if (!$this->areCommentsClosed && $this->perms['create'])
		{
			/**
			 * Joomla always try to load the form from the current component. However, the current component is
			 * com_content as we're emulating HMVC (Hierarchical Model–View–Controller). As a result we need to hint
			 * Joomla that it should be loading the form from Akeeba Engage's forms folder.
			 */
			$basePath = JPATH_SITE . '/components/com_engage';
			Form::addFormPath($basePath . '/forms');

			/** @var CommentModel $formModel */
			$formModel  = $this->getModel('comment');
			$this->form = $formModel->getForm([
				'id'       => null,
				'asset_id' => $this->assetId,
			], true);
		}

		parent::display($tpl);
	}


	/**
	 * Information for the "In reply to" note of a comment.
	 *
	 * Returns null when the comment is not a reply (or the notes are turned off). The name is NOT escaped: escape it on
	 * output. If the parent comment is not available to the current user (unpublished, deleted, other content item)
	 * the name is empty and no link is given, so a name is never leaked.
	 *
	 * @param   object  $comment  A comment as returned by the model
	 *
	 * @return  array|null  [id => int (0 = unknown), name => string, samePage => bool]
	 * @since   0.6.21
	 */
	public function getReplyToInfo(object $comment): ?array
	{
		$parentId = (int) ($comment->parent_id ?? 0);

		if (!$this->showInReplyTo || $parentId <= 0)
		{
			return null;
		}

		if (($this->replyToNames['same'][$parentId] ?? '') !== '')
		{
			return ['id' => $parentId, 'name' => $this->replyToNames['same'][$parentId], 'samePage' => true];
		}

		if (($this->replyToNames['other'][$parentId] ?? '') !== '')
		{
			return ['id' => $parentId, 'name' => $this->replyToNames['other'][$parentId], 'samePage' => false];
		}

		return ['id' => 0, 'name' => '', 'samePage' => false];
	}

	/**
	 * Public display name of the author of a comment (same rule as the comments list template).
	 *
	 * @param   object  $comment
	 *
	 * @return  string
	 * @since   0.6.21
	 */
	private function getAuthorName(object $comment): string
	{
		if (!empty($comment->name) || empty($comment->created_by))
		{
			return (string) ($comment->name ?? '');
		}

		$user = UserFetcher::getUser((int) $comment->created_by);

		return $user ? (string) $user->name : '';
	}

	/**
	 * Collect the display names of the parents of the comments being displayed.
	 *
	 * Parents listed on this page come from the page itself. Parents on another page of the pagination are fetched with
	 * one query that is limited to this content item and, unless the user can moderate, to published comments.
	 *
	 * @return  array
	 * @since   0.6.21
	 */
	private function loadReplyToNames(): array
	{
		$ret = ['same' => [], 'other' => []];

		foreach ($this->items as $comment)
		{
			$ret['same'][(int) $comment->id] = $this->getAuthorName($comment);
		}

		$missing = [];

		foreach ($this->items as $comment)
		{
			$parentId = (int) ($comment->parent_id ?? 0);

			if ($parentId > 0 && !isset($ret['same'][$parentId]))
			{
				$missing[$parentId] = $parentId;
			}
		}

		if (empty($missing))
		{
			return $ret;
		}

		try
		{
			// 0.8.0: getDatabase() of the models is protected in Joomla 6 and calling it is an Error (not an Exception), so this
			// lookup killed the WHOLE list whenever a page began with a reply whose parent was on the previous page.
			$db      = Factory::getContainer()->get(DatabaseInterface::class);
			$assetId = (int) $this->assetId;
			$query   = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select($db->quoteName(['id', 'name', 'created_by']))
				->from($db->quoteName('#__engage_comments'))
				->whereIn($db->quoteName('id'), array_values($missing), ParameterType::INTEGER)
				->where($db->quoteName('asset_id') . ' = :asset_id')
				->bind(':asset_id', $assetId, ParameterType::INTEGER);

			if (!$this->perms['state'])
			{
				$query->where($db->quoteName('enabled') . ' = 1');
			}

			foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row)
			{
				$ret['other'][(int) $row->id] = $this->getAuthorName($row);
			}
		}
		catch (Throwable $e)
		{
			// Without the parent's name the note falls back to the generic text.
		}

		return $ret;
	}

	/**
	 * Make sure the comment's parent information is cached in $parentIds and $parentNames.
	 *
	 * This is required when a page starts the listing with a comment of level 'max_level' (see config.xml) or deeper.
	 * In these cases the reply information we need to pass is meant to be that of the last parent with a nesting level
	 * of 'max_depth' minus 1. The following code makes sure that is the case.
	 *
	 * @param   object  $comment
	 * @param   array   $parentIds
	 * @param   array   $parentNames
	 *
	 * @throws  Exception
	 * @since   1.0.0
	 */
	protected function ensureHasParentInfo(object $comment, array &$parentIds, array &$parentNames): void
	{
		$parentLevel = $comment->depth - 1;

		if (isset($parentIds[$parentLevel]) && isset($parentNames[$parentLevel]))
		{
			return;
		}

		$myComment = $comment;
		$maxLevel  = ComponentHelper::getParams('com_engage')->get('max_level', 3);
		$maxLevel  = max($maxLevel, 1);

		$table = $this->getModel()->getTable('Comment', 'Administrator');

		do
		{
			$newDepth  = $myComment->depth - 1;
			$myComment = clone $table;
			$myComment->reset();
			if (!$myComment->load($myComment->parent_id))
			{
				break;
			};
			$myComment->depth = $newDepth;

			$parentNames[$myComment->depth] = $myComment->created_by
				? UserFetcher::getUser($myComment->created_by)->name : $myComment->name;
			$parentIds[$myComment->depth]   = $myComment->id;
		} while ($myComment->depth > ($maxLevel - 1));
	}

	/**
	 * Get an IP lookup URL for the provided IP address
	 *
	 * @param   string|null  $ip  The IP address to look up
	 *
	 * @return  string  The lookup URL, empty if not applicable.
	 * @since   1.0.0
	 */
	protected function getIPLookupURL(?string $ip): string
	{
		return HTMLHelper::_('engage.getIPLookupURL', $ip);
	}

	/**
	 * Get the appropriate language key for the content type provided
	 *
	 * @param   string  $type  Content type, e.g. 'article'
	 *
	 * @return  string|null  The custom language key or null if no appropriate key is found.
	 * @since   1.0.0
	 */
	private function getHeaderKey(string $type): ?string
	{
		$key = sprintf('COM_ENGAGE_COMMENTS_%s_HEADER_N_COMMENTS', strtoupper($type));

		try
		{
			$lang = Factory::getApplication()->getLanguage();
		}
		catch (Exception $e)
		{
			return null;
		}

		return $lang->hasKey($key) ? $key : null;
	}

	/**
	 * Get the timezone for the currently logged in user (site's timezone for guest users).
	 *
	 * @return  DateTimeZone
	 * @throws  Exception
	 * @since   1.0.0
	 */
	private function getUserTimezone(): DateTimeZone
	{
		try
		{
			$siteTimezone = Factory::getApplication()->get('offset', 'UTC');
		}
		catch (Exception $e)
		{
			$siteTimezone = 'UTC';
		}

		$zone = $this->user->guest ? $siteTimezone : $this->user->getParam('timezone', $siteTimezone);

		try
		{
			return new DateTimeZone($zone);
		}
		catch (Exception $e)
		{
			return new DateTimeZone('UTC');
		}
	}

	/**
	 * Head of the page when the visitor sorted the list or filtered it (0.8.0): the "only my favourites" list is personal, so it is
	 * kept out of search engines; a sorted list points to the page without parameters, so that search engines do not index the same
	 * comments three times. Nothing happens when no parameter was used.
	 *
	 * @param   object  $doc  The HTML document
	 */
	private function prepareHead($doc): void
	{
		try
		{
			$state = $this->listState ?? [];

			if (!empty($state['favorites']))
			{
				$doc->setMetaData('robots', 'noindex, nofollow');

				return;
			}

			if (empty($state['explicit']))
			{
				return;
			}

			// 0.8.1: the origin is only the live_site configured in Joomla, never the Host header of the visitor (the page is shared through the
			// cache); without a valid live_site no canonical is added (the path and the query are cleaned with a whitelist as well)
			$origin = CommentTools::trustedOrigin((string) Factory::getApplication()->get('live_site', ''));

			if ($origin === '')
			{
				return;
			}

			$canonical = $origin . CommentTools::cleanUrl(Uri::getInstance()->toString(['path', 'query']));

			if (!CommentTools::isSafeAbsoluteUrl($canonical))
			{
				return;
			}

			// Do not add a second canonical if the site (or Joomla) already declared one
			foreach ((array) ($doc->_links ?? []) as $link)
			{
				if (is_array($link) && (($link['relation'] ?? '') === 'canonical'))
				{
					return;
				}
			}

			$doc->addHeadLink(htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'canonical');
		}
		catch (Exception $e)
		{
			// Without the extra head data the page is still correct
		}
	}

	/**
	 * Relative URL (path and query) of the current page, with the fragment of the comments section, after removing and adding
	 * parameters of this fork. Used for the links of the sort selector and of the favourites toggle: no scheme or host, so nothing of the
	 * request's Host header ends up in cacheable HTML.
	 *
	 * @param   string[]             $remove
	 * @param   array<string,string> $add
	 */
	private function toolsUrl(array $remove, array $add): string
	{
		// safeRelative: the path of the request is attacker-influenced (a path starting with // would be a link to another site)
		return CommentTools::withQuery(CommentTools::relativeUrl(Uri::getInstance()->toString(['path', 'query'])), $remove, $add, 'akengage-comments-section');
	}

	/** Link of one sort mode (back to the first page; the favourites filter, if active, is kept). */
	public function sortUrl(string $mode): string
	{
		// The favourites parameter is kept only when that list is the one shown (a guest's ignored parameter is not passed on)
		return (ListOrdering::sortMode($mode) === null) ? '' : $this->toolsUrl(
			array_merge([CommentTools::PARAM_SORT, 'akengage_limitstart', 'akengage_limit', 'akengage_cid'], $this->isFavoritesView() ? [] : [CommentTools::PARAM_FAV]),
			[CommentTools::PARAM_SORT => $mode, 'akengage_limitstart' => '0']
		);
	}

	/** Link of the favourites toggle: with $on the filtered list, without it the full list (the chosen sort is kept in both). */
	public function favoritesUrl(bool $on): string
	{
		$add = ['akengage_limitstart' => '0'];

		if ($on)
		{
			$add[CommentTools::PARAM_FAV] = '1';
		}

		return $this->toolsUrl([CommentTools::PARAM_FAV, 'akengage_limitstart', 'akengage_limit', 'akengage_cid'], $add);
	}

	/**
	 * Permalink of a comment (date link, "view in thread", "in reply to" and the copy button), RELATIVE: path, whitelisted query,
	 * `akengage_cid` and the anchor. 0.8.1: no scheme or host (the Host header of the first visitor used to end up in the cached
	 * page) and no unknown query parameter. tools.js makes the copied link absolute with the real origin of the visitor.
	 */
	public function commentPermalink(int $commentId): string
	{
		return CommentTools::permalinkRelative(Uri::getInstance()->toString(['path', 'query']), $commentId);
	}

	/** Is the list currently the "only my favourites" one? */
	public function isFavoritesView(): bool
	{
		return !empty($this->listState['favorites']);
	}

	/**
	 * User ID of the author of the content item the comments belong to (0 = unknown or not an article). One bound query per page.
	 */
	public function getContentAuthorId(): int
	{
		if ($this->contentAuthorId !== null)
		{
			return $this->contentAuthorId;
		}

		$this->contentAuthorId = 0;

		try
		{
			// getDatabase() of the models is protected in Joomla 6: take the database from the container
			$db      = Factory::getContainer()->get(DatabaseInterface::class);
			$assetId = (int) $this->assetId;
			$query   = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select($db->quoteName('created_by'))
				->from($db->quoteName('#__content'))
				->where($db->quoteName('asset_id') . ' = :asset_id')
				->bind(':asset_id', $assetId, ParameterType::INTEGER);
			$this->contentAuthorId = max(0, (int) $db->setQuery($query, 0, 1)->loadResult());
		}
		catch (Exception $e)
		{
			$this->contentAuthorId = 0;
		}

		return $this->contentAuthorId;
	}

	/**
	 * Can this user moderate comments? (core.edit.state or core.manage of the component, evaluated with Joomla's ACL.) Cached per
	 * user for the request. A blocked or missing account is never shown as a moderator.
	 */
	public function isModeratorUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (!array_key_exists($userId, $this->moderatorCache))
		{
			$ok = false;

			try
			{
				$u  = UserFetcher::getUser($userId);
				$ok = $u !== null && !$u->guest && (int) $u->id === $userId && empty($u->block)
					&& ($u->authorise('core.edit.state', 'com_engage') || $u->authorise('core.manage', 'com_engage'));
			}
			catch (Exception $e)
			{
				$ok = false;
			}

			$this->moderatorCache[$userId] = (bool) $ok;
		}

		return $this->moderatorCache[$userId];
	}

	/**
	 * Badges to show next to the name of the author of a comment: a list of keys ('author', 'moderator'). Empty when the option is off.
	 *
	 * @return string[]
	 */
	public function badgesFor(object $comment): array
	{
		if (empty($this->tools['show_badges']))
		{
			return [];
		}

		return CommentTools::badges((int) ($comment->created_by ?? 0), $this->getContentAuthorId(), fn(int $id): bool => $this->isModeratorUser($id));
	}

	/**
	 * HTML of the badges of one comment (texts escaped, fixed icons, no group names, no IDs). Empty string when there are none.
	 *
	 * @param   string[]  $badges  badgesFor()
	 */
	public function badgesHtml(array $badges): string
	{
		if (!$badges)
		{
			return '';
		}

		$e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$html = '<span class="akengage-badges">';

		foreach ($badges as $badge)
		{
			if ($badge === 'author')
			{
				$html .= '<span class="akengage-badge akengage-badge--author">' . ReactionIcons::svg('badge-author') . '<span class="akengage-badge-text">' . $e(Text::_('COM_ENGAGE_BADGE_AUTHOR')) . '</span></span>';
			}
			elseif ($badge === 'moderator')
			{
				$html .= '<span class="akengage-badge akengage-badge--moderator">' . ReactionIcons::svg('badge-moderator') . '<span class="akengage-badge-text">' . $e(Text::_('COM_ENGAGE_BADGE_MODERATOR')) . '</span></span>';
			}
		}

		return $html . '</span>';
	}

	/**
	 * HTML of the copy-link button of one comment (0.8.0): a button with an outline icon and the relative permalink in a data
	 * attribute. Hidden until tools.js confirms the browser can copy (without JavaScript it is not shown). Empty when the option is
	 * off or the link cannot be validated.
	 */
	public function copyButtonHtml(int $commentId): string
	{
		if (empty($this->tools['copy_link']) || $commentId <= 0)
		{
			return '';
		}

		$url = $this->commentPermalink($commentId);

		if ($url === '')
		{
			return '';
		}

		$e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$label = $e(Text::_('COM_ENGAGE_TOOLS_COPY_LABEL'));

		return '<span class="akengage-copylink" data-engage-copywrap hidden>'
			. '<button type="button" class="akengage-copy-btn" data-engage-copy="' . $e($url) . '" data-engage-id="' . (int) $commentId . '" aria-label="' . $label . '" title="' . $label . '">'
			. ReactionIcons::svg('link') . ReactionIcons::svg('check')
			. '</button></span>';
	}

	/**
	 * HTML skeleton of the reaction buttons of one comment (0.7.0). Identical for every visitor: no state, no counters, hidden
	 * until reactions.js fills it in (without JavaScript the buttons are not shown). Texts and the comment ID are escaped.
	 *
	 * @param   int  $commentId
	 *
	 * @return  string
	 * @since   0.7.0
	 */
	public function reactionsHtml(int $commentId): string
	{
		if (!$this->reactions['enabled'] || $commentId <= 0)
		{
			return '';
		}

		$e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$types = ['like' => true, 'dislike' => $this->reactions['dislike'], 'favorite' => $this->reactions['favorites']];
		$html  = '<div class="akengage-reactions" role="group" aria-label="' . $e(Text::_('COM_ENGAGE_REACTIONS_GROUP')) . '" data-engage-reactions hidden>';

		foreach ($types as $type => $on)
		{
			if (!$on)
			{
				continue;
			}

			$label = $e(Text::_('COM_ENGAGE_REACTIONS_' . strtoupper($type)));
			$html .= '<span class="akengage-react-item akengage-react-item--' . $type . '">'
				. '<button type="button" class="akengage-react-btn akengage-react-' . $type . '" data-engage-react="' . $type . '" data-engage-id="' . (int) $commentId . '" aria-pressed="false" aria-label="' . $label . '">'
				. ReactionIcons::svg($type) . '</button>'
				. (($type === 'favorite') ? '' : '<span class="akengage-react-count" data-engage-count="' . $type . '" aria-live="polite"></span>')
				. '</span>';
		}

		return $html . '</div>';
	}

	/**
	 * CSS class (with leading space) that tells the stylesheets what to do with the avatar below 576 px. Empty for the
	 * classic theme with "auto", so that its HTML stays byte for byte the original one.
	 *
	 * auto: the theme decides (classic keeps the original behaviour; modern, minimal and dark show a smaller avatar).
	 *
	 * @return  string
	 * @since   0.6.28
	 */
	public function mobileAvatarClass(): string
	{
		if ($this->mobileAvatar === 'hide')
		{
			return ' akengage-mobile-avatar--hide';
		}

		if ($this->mobileAvatar === 'show' || ($this->mobileAvatar === 'auto' && $this->theme !== 'classic'))
		{
			return ' akengage-mobile-avatar--show';
		}

		return '';
	}
}