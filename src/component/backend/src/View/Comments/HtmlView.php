<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\View\Comments;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\HtmlFilter;
use Akeeba\Component\Engage\Administrator\Helper\PermissionsNav;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Mixin\ViewLoadAnyTemplateTrait;
use Akeeba\Component\Engage\Administrator\Model\CommentsModel;
use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Toolbar\Button\DropdownButton;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Registry\Registry;

/**
 * HTML view class for listing comments
 *
 * @since 3.0.0
 */
class HtmlView extends BaseHtmlView
{
	use ViewLoadAnyTemplateTrait;

	/**
	 * The active search filters
	 *
	 * @var    array
	 * @since  3.0.0
	 */
	public $activeFilters = [];

	/**
	 * The search tools form
	 *
	 * @var    Form
	 * @since  3.0.0
	 */
	public $filterForm;

	/**
	 * An array of items
	 *
	 * @var    array
	 * @since  3.0.0
	 */
	protected $items = [];

	/**
	 * The pagination object
	 *
	 * @var    Pagination
	 * @since  3.0.0
	 */
	protected $pagination;

	/**
	 * The model state
	 *
	 * @var    Registry
	 * @since  3.0.0
	 */
	protected $state;

	/**
	 * Is this view an Empty State
	 *
	 * @var    boolean
	 * @since  3.0.0
	 */
	private $isEmptyState = false;

	/**
	 * A preconfigured instance of HTML purifier for displaying comments / comment excerpts in the backend
	 *
	 * @var   HTMLPurifier|null
	 * @since 3.0.0
	 */
	private $purifier;

	/**
	 * Execute and display a template script.
	 *
	 * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
	 *
	 * @return  void
	 *
	 * @throws  Exception
	 * @see     self::loadTemplate()
	 * @since   3.0.0
	 */
	/** @var array<int,array{0:int,1:int}> 0.9.3: comment id => [likes, dislikes] of the comments of this page (empty if the reactions are not available) */
	public $reactionCounts = [];

	/** @var array<string,int> 0.9.3: type => number of comments (for the chips «Tipo de elemento») */
	public $typeCounts = [];

	/** @var string 0.9.3: the type chosen in the filter (already validated), or '' */
	public $activeType = '';

	/** Type of the content item of a comment (`com_content.article`…) from the name of its asset, or ''. */
	public function typeOf(int $assetId): string
	{
		try
		{
			return (string) (\Akeeba\Component\Engage\Administrator\Helper\CommentTypes::fromAssetName(\Akeeba\Component\Engage\Site\Helper\Meta::assetName($assetId)) ?? '');
		}
		catch (\Throwable $e)
		{
			return '';
		}
	}

	/** Maximum number of DIFFERENT content items whose sheet is asked of the plugins for one page of the list (0.8.5). */
	public const MAX_META_ASSETS = 50;

	/** @var array<int,bool> */
	private $metaSeen = [];

	/**
	 * Sheet of the content item of a row. Cached per asset by Meta (one plugin call per asset per request) and capped: past MAX_META_ASSETS
	 * different items the rows show «item not available» instead of calling third-party plugins once more.
	 */
	public function itemMeta(int $assetId): array
	{
		if (!isset($this->metaSeen[$assetId]))
		{
			if (count($this->metaSeen) >= self::MAX_META_ASSETS)
			{
				return ['type' => 'unknown', 'title' => '', 'url' => null, 'public_url' => null];
			}

			$this->metaSeen[$assetId] = true;
		}

		return \Akeeba\Component\Engage\Site\Helper\Meta::getAssetAccessMeta($assetId, false);
	}

	public function display($tpl = null)
	{
		/** @var CommentsModel $model */
		$model               = $this->getModel();
		$this->items         = $model->getItems();
		$this->pagination    = $model->getPagination();
		$this->state         = $model->getState();
		$this->filterForm    = $model->getFilterForm();
		$this->activeFilters = $model->getActiveFilters();

		// Check for errors.
		if (count($errors = $this->get('Errors')))
		{
			throw new GenericDataException(implode("\n", $errors), 500);
		}

		if (!\count($this->items) && $this->isEmptyState = $this->get('IsEmptyState'))
		{
			$this->setLayout('emptystate');
		}

		// 0.9.3: modern design (shared with the control panel) and the data that the list shows
		$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
		$wa->useStyle('com_engage.lists')->useScript('com_engage.panel.theme');

		$this->typeCounts = \Akeeba\Component\Engage\Administrator\Helper\CommentTypes::counts();
		$this->activeType = \Akeeba\Component\Engage\Administrator\Helper\CommentTypes::validate($this->state->get('filter.itemtype'), \Akeeba\Component\Engage\Administrator\Helper\CommentTypes::available());

		try
		{
			$ids = array_map(static fn($i): int => (int) $i->id, (array) $this->items);

			if ($ids)
			{
				$store = new \Akeeba\Component\Engage\Administrator\Helper\ReactionStore(Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class));

				foreach ($store->counts($ids) as $cid => $byType)
				{
					$this->reactionCounts[(int) $cid] = [(int) ($byType[\Akeeba\Component\Engage\Administrator\Helper\Reactions::LIKE] ?? 0), (int) ($byType[\Akeeba\Component\Engage\Administrator\Helper\Reactions::DISLIKE] ?? 0)];
				}
			}
		}
		catch (\Throwable $e)
		{
			$this->reactionCounts = [];
		}

		// Create an HTML purifier instance
		HtmlFilter::includeHTMLPurifier();

		$config = HTMLPurifier_Config::createDefault();
		$config->set('Core.Encoding', 'UTF-8');
		$config->set('HTML.Doctype', 'HTML 4.01 Transitional');
		$config->set('Cache.SerializerPath', HtmlFilter::getCachePath());
		$config->set('HTML.Allowed', 'p,b,a[href],i,u,strong,em,small,big,ul,ol,li,br,img[src],img[width],img[height],code,pre,blockquote');

		$this->purifier = new HTMLPurifier($config);

		$this->addToolbar();

		parent::display($tpl);
	}

	/**
	 * Set up Joomla!'s toolbar.
	 *
	 * @return  void
	 * @throws  Exception
	 * @since   5.0.0
	 */
	private function addToolbar(): void
	{
		$canDo = ContentHelper::getActions('com_engage', 'component');
		$user  = UserFetcher::getUser();

		// Get the toolbar object instance
		$toolbar = Toolbar::getInstance('toolbar');

		ToolbarHelper::title(
			sprintf("%s: <span class=\"fw-bold\">%s</span>", Text::_('COM_ENGAGE'), Text::_('COM_ENGAGE_TITLE_COMMENTS')),
			'icon-engage'
		);

		if (!$this->isEmptyState && $canDo->get('core.edit.state'))
		{
			/** @var DropdownButton $dropdown */
			$dropdown = $toolbar->dropdownButton('status-group')
				->text('JTOOLBAR_CHANGE_STATUS')
				->toggleSplit(false)
				->icon('icon-ellipsis-h')
				->buttonClass('btn btn-action')
				->listCheck(true);

			$childBar = $dropdown->getChildToolbar();

			$childBar->publish('comments.publish')->listCheck(true);

			$childBar->unpublish('comments.unpublish')->listCheck(true);

			$childBar->standardButton('possiblespam')
				->text('COM_ENGAGE_COMMENTS_TOOLBAR_POSSIBLESPAM')
				->task('comments.possiblespam')
				->icon('fas fa-flag')
				->listCheck(true);

			$childBar->confirmButton('reportspam')
				->text('COM_ENGAGE_COMMENTS_TOOLBAR_REPORTSPAM')
				->task('comments.reportspam')
				->icon('fa fa-exclamation-circle')
				->message('COM_ENGAGE_COMMENTS_CONFIRM_REPORTSPAM')
				->listCheck(true);

			$childBar->confirmButton('reportham')
				->text('COM_ENGAGE_COMMENTS_TOOLBAR_REPORTHAM')
				->task('comments.reportham')
				->icon('fa fa-check-square')
				->message('COM_ENGAGE_COMMENTS_CONFIRM_REPORTHAM')
				->listCheck(true);
		}

		if (!$this->isEmptyState && $canDo->get('core.delete'))
		{
			$toolbar->delete('comments.delete')
				->text('JTOOLBAR_DELETE')
				->message('COM_ENGAGE_COMMENTS_CONFIRM_DELETE')
				->listCheck(true);
		}

		$toolbar->link('COM_ENGAGE_TITLE_EMAILTEMPLATES', 'index.php?option=com_engage&view=emailtemplates')
			->icon('fa fa-envelope');

		if (PermissionsNav::canOpen())
		{
			$toolbar->link('COM_ENGAGE_PERMS_BTN', PermissionsNav::matrixUrl())->icon('fa fa-lock');
		}
		elseif ($user->authorise('core.admin', 'com_engage') || $user->authorise('core.options', 'com_engage'))
		{
			$toolbar->preferences('com_engage');
		}
	}
}