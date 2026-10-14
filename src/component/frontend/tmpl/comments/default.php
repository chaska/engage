<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die();

/**
 * View Template for comments display
 *
 * This is the main view template used when comments are being displayed e.g. at the end of an article.
 *
 * This provides the outer HTML structure of the comments.
 *
 * It loads the following view templates:
 * - default_list.php  The threaded list of comments
 * - default_login.php  Login form for guest users
 * - default_form.php  Comment / reply submission form
 */

use Akeeba\Component\Engage\Administrator\Helper\ReactionIcons;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

/** @var \Akeeba\Component\Engage\Site\View\Comments\HtmlView $this */

$cParams = ComponentHelper::getParams('com_engage');

// 0.8.0: sort selector and "only my favourites" (CommentTools::resolve). With the options off nothing new is printed, not even whitespace.
$listState   = $this->listState ?? [];
$favView     = $this->isFavoritesView();
$showToolbar = !empty($listState['selector']) && ($favView || $this->pagination->total >= 2);
$headerHtml  = $favView
	? Text::sprintf('COM_ENGAGE_FAV_HEADER', (int) $this->pagination->total)
	: Text::plural($this->headerKey, $this->pagination->total, htmlspecialchars((string) $this->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
?>
<section id="akengage-comments-section" class="akengage-outer-container<?= ($this->theme !== 'classic') ? ' akengage-theme--' . $this->escape($this->theme) : '' ?><?= $this->escape($this->mobileAvatarClass()) ?>"
		aria-label="<?= Text::_('COM_ENGAGE_COMMENTS_SECTION_HEADER') ?>"
		itemscope itemtype="https://schema.org/CreativeWork">
	<?php // 0.9.5: the comments are properties (itemprop="comment") of this CreativeWork: Google requires every Comment to be nested in one. Name and url only when known (the title is escaped; the url is the clean canonical: trusted base or relative path). ?>
	<?php if ((string) $this->title !== ''): ?><meta itemprop="name" content="<?= $this->escape((string) $this->title) ?>"><?php endif; ?>
	<?php if (($schemaUrl = $this->schemaUrl()) !== ''): ?><link itemprop="url" href="<?= $this->escape($schemaUrl) ?>"><?php endif; ?>

	<h3 class="akengage-title h4 border-bottom mb-2">
		<?= $headerHtml ?>
	</h3>

	<?= $this->loadPosition('engage-before-comments') ?>
<?php if ($showToolbar): ?>
	<div class="akengage-toolbar" data-engage-toolbar>
		<div class="akengage-sort" role="group" aria-labelledby="akengage-sort-label-<?= (int) $this->assetId ?>">
			<span class="akengage-toolbar-label" id="akengage-sort-label-<?= (int) $this->assetId ?>"><?= Text::_('COM_ENGAGE_SORT_LABEL') ?></span>
			<?php foreach ($listState['modes'] as $sortMode): $sortActive = ($sortMode === $listState['selected']); ?>
			<a class="akengage-sort-link<?= $sortActive ? ' is-active' : '' ?>" href="<?= $this->escape($this->sortUrl($sortMode)) ?>" rel="nofollow"<?= $sortActive ? ' aria-current="true"' : '' ?>><?= Text::_('COM_ENGAGE_SORT_' . strtoupper($sortMode)) ?></a>
			<?php endforeach; ?>
		</div>
		<?php if (!empty($listState['favButton'])): ?>
		<a class="akengage-fav-toggle<?= $favView ? ' is-active' : '' ?>" href="<?= $this->escape($this->favoritesUrl(!$favView)) ?>" rel="nofollow" data-engage-fav-toggle<?= $favView ? ' aria-current="true"' : ' hidden' ?>><?= ReactionIcons::svg('favorite') ?><span class="akengage-fav-toggle-text"><?= Text::_($favView ? 'COM_ENGAGE_FAV_SHOW_ALL' : 'COM_ENGAGE_FAV_ONLY') ?></span></a>
		<?php endif; ?>
	</div>
<?php endif; ?>

	<?php if ($this->pagination->total): ?>
		<div class="akengage-list-container">
			<?= $this->loadTemplate('list') ?>
		</div>

		<?= $this->loadPosition('engage-after-comments') ?>

		<?php if ($this->pagination->pagesTotal > 1): ?>
		<div class="akengage-pagination">
			<div class="akengage-pagination-pages pagination" itemscope itemtype="https://schema.org/SiteNavigationElement">
				<?= $this->paginationLinksHtml() ?>
			</div>
		</div>
		<?php endif; ?>
	<?php elseif ($favView): ?>
		<p class="akengage-fav-empty"><?= Text::_('COM_ENGAGE_FAV_EMPTY') ?></p>
	<?php endif; ?>

	<?php if (!$this->areCommentsClosed && $this->user->guest && !$this->perms['create']): ?>
		<?= $this->loadTemplate('login') ?>
	<?php endif; ?>

	<?php if ($this->perms['create'] && !$this->areCommentsClosed): ?>
		<?= $this->loadTemplate('form') ?>
	<?php endif; ?>

	<?php if ($this->perms['create'] && $this->areCommentsClosed): ?>
		<div class="alert alert-info">
			<h3 class="alert-heading">
				<?= Text::_('COM_ENGAGE_COMMENTS_LBL_CLOSED_HEADER') ?>
			</h3>
			<p>
				<?php if ($this->areCommentsClosedAfterTime): ?>
					<?= Text::_('COM_ENGAGE_COMMENTS_LBL_CLOSED_AFTERTIME') ?>
				<?php else: ?>
					<?= Text::_('COM_ENGAGE_COMMENTS_LBL_CLOSED_BODY') ?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>
</section>