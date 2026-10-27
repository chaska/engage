<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Lista de comentarios del administrador. 0.9.3: diseno moderno (.eg-admin, panel.css + admin-lists.css), tabla compacta en escritorio y
 * tarjetas en movil. Sin estilos en linea, sin JavaScript propio, sin recursos externos (el avatar son las iniciales: no se carga Gravatar).
 * Se conserva todo lo anterior: barra de herramientas, filtros (searchtools), ordenacion, paginacion, seleccion multiple, modales, tokens.
 */

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\CommentTypes;
use Akeeba\Component\Engage\Administrator\Helper\LayoutHelper;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Table\CommentTable;
use Akeeba\Component\Engage\Site\Helper\Meta;
use Joomla\CMS\Environment\Browser;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/** @var \Akeeba\Component\Engage\Administrator\View\Comments\HtmlView $this */

if (version_compare(JVERSION, '4.999.999', 'lt'))
{
	$this->loadAnyTemplate('Common/joomla_eol');
}

HTMLHelper::_('bootstrap.tooltip', '.hasTooltip');
HTMLHelper::_('bootstrap.modal');

$e         = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$app       = Factory::getApplication();
$user      = $app->getIdentity();
$userId    = $user->get('id');
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$tr        = static fn(string $k): string => Text::_($k);

// The asset we are filtered by
$filteredAsset = $this->activeFilters['asset_id'] ?? null;
$totalTypes    = array_sum($this->typeCounts);
$statusMap     = [
	1  => ['ok', 'JPUBLISHED'],
	0  => ['warn', 'JUNPUBLISHED'],
	-3 => ['warn', 'COM_ENGAGE_COMMENT_ENABLED_OPT_POSSIBLE_SPAM'],
	-2 => ['bad', 'COM_ENGAGE_COMMENT_ENABLED_OPT_SPAM'],
];
$chipUrl = static fn(string $type): string => Route::_('index.php?option=com_engage&view=comments&filter[itemtype]=' . rawurlencode($type) . '&filter[itemid]=&limitstart=0', false);
$initials = static function (string $name): string {
	$parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
	$out   = '';

	foreach (array_slice($parts, 0, 2) as $p)
	{
		$out .= mb_strtoupper(mb_substr($p, 0, 1));
	}

	return $out !== '' ? $out : '?';
};

?>
<div class="eg-admin eg-admin--list" id="eg-admin-comments">
<form action="<?= $e(Route::_('index.php?option=com_engage&view=comments', false)) ?>" method="post" name="adminForm" id="adminForm">
	<div class="eg-list-head">
		<h2 class="eg-list-title"><?= $e(Text::_('COM_ENGAGE_TITLE_COMMENTS')) ?></h2>
		<span class="eg-list-count"><?= (int) $this->pagination->total ?></span>
	</div>

	<?php if ($this->typeCounts) : ?>
	<nav class="eg-typebar" aria-label="<?= $e(Text::_('COM_ENGAGE_COMMENTS_FILTER_ITEMTYPE')) ?>">
		<span class="eg-typebar__label"><?= $e(Text::_('COM_ENGAGE_COMMENTS_FILTER_ITEMTYPE')) ?>:</span>
		<ul class="eg-typebar__chips">
			<li>
				<a class="eg-typechip" href="<?= $e($chipUrl('')) ?>"<?= $this->activeType === '' ? ' aria-current="true"' : '' ?>><?= $e(Text::_('COM_ENGAGE_COMMENTS_FILTER_ITEMTYPE_ALL')) ?> (<?= (int) $totalTypes ?>)</a>
			</li>
			<?php foreach ($this->typeCounts as $type => $n) : ?>
			<li>
				<a class="eg-typechip" href="<?= $e($chipUrl((string) $type)) ?>"<?= $this->activeType === $type ? ' aria-current="true"' : '' ?>><?= $e(CommentTypes::label((string) $type, $tr)) ?> (<?= (int) $n ?>)</a>
			</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php endif; ?>

	<div id="j-main-container" class="j-main-container">
		<?= LayoutHelper::render('joomla.searchtools.default', ['view' => $this]) ?>
		<?php if (empty($this->items)) : ?>
			<div class="eg-emptystate">
				<p><?= $e(Text::_('JGLOBAL_NO_MATCHING_RESULTS')) ?></p>
			</div>
		<?php else : ?>
			<div class="eg-tablewrap">
				<table class="eg-table" id="commentsList">
					<caption class="visually-hidden">
						<?= $e(Text::_('COM_ENGAGE_COMMENTS_TABLE_CAPTION')) ?>,
						<span id="orderedBy"><?= $e(Text::_('JGLOBAL_SORTED_BY')) ?> </span>,
						<span id="filteredBy"><?= $e(Text::_('JGLOBAL_FILTERED_BY')) ?></span>
					</caption>
					<thead>
					<tr>
						<td class="eg-col-check">
							<?= HTMLHelper::_('grid.checkall'); ?>
						</td>
						<th scope="col">
							<?= HTMLHelper::_('searchtools.sort', 'COM_ENGAGE_COMMENT_FIELD_CREATED_BY', 'user_name', $listDirn, $listOrder); ?>
						</th>
						<th scope="col">
							<?= HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'c.id', $listDirn, $listOrder); ?>
							<span aria-hidden="true">&ensp;/&ensp;</span>
							<?= $e(Text::_('COM_ENGAGE_COMMENT_FIELD_BODY')) ?>
						</th>
						<th scope="col" class="eg-col-date">
							<?= HTMLHelper::_('searchtools.sort', 'COM_ENGAGE_COMMENT_FIELD_CREATED_ON', 'c.created', $listDirn, $listOrder); ?>
						</th>
						<th scope="col" class="eg-col-status">
							<?= HTMLHelper::_('searchtools.sort', 'JSTATUS', 'c.enabled', $listDirn, $listOrder); ?>
						</th>
					</tr>
					</thead>
					<tbody data-nested="false">
					<?php foreach ($this->items as $i => $item) :
						// Permissions
						$canChange = $user->authorise('core.edit.state', 'com_engage');
						// Author
						$creator    = UserFetcher::getUser($item->created_by);
						$authorName = (string) ($item->user_name ?: ($creator->name ?? ''));
						$registered = empty($item->name) && empty($item->email);
						// Metadata
						$meta        = $this->itemMeta((int) $item->asset_id);
						$numComments = Meta::getNumCommentsForAsset($item->asset_id, false);
						$itemType    = $this->typeOf((int) $item->asset_id);
						// IP and User Agent
						$ipLookupUrl = HTMLHelper::_('engage.getIPLookupURL', $item->ip);
						$jBrowser    = Browser::getInstance($item->user_agent);
						// Processed HTML comment (purified)
						$processedComment = $this->purifier->purify(HTMLHelper::_('engage.processCommentTextForDisplay', $item->body));
						$excerpt          = HTMLHelper::_('engage.textExcerpt', $processedComment);
						// Parent comment (if applicable)
						$parent       = null;
						$parentAuthor = null;
						$public_uri   = null;

						if (!empty($item->parent_id))
						{
							try
							{
								/** @var CommentTable $parent */
								$parent = $this->getModel()->getTable('comment');

								if (!$parent->load($item->parent_id))
								{
									throw new RuntimeException('Parent not found.');
								}

								$public_uri = new Uri((string) $meta['public_url']);
								$public_uri->setVar('akengage_cid', $parent->getId());
								$public_uri->setFragment('akengage-comment-' . $parent->getId());

								$parentUser   = UserFetcher::getUser($parent->created_by);
								$parentAuthor = empty($parentUser) ? '' : $parentUser->name;
								$parentAuthor = $parent->name ?: $parentAuthor;
							}
							catch (Exception $ex)
							{
								$parent = null;
							}
						}

						[$stClass, $stKey] = $statusMap[(int) $item->enabled] ?? ['warn', 'JSTATUS'];
						$react = $this->reactionCounts[(int) $item->id] ?? null;
						?>
						<tr>
							<td class="eg-col-check">
								<?= HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->id); ?>
							</td>
							<td data-label="<?= $e(Text::_('COM_ENGAGE_COMMENT_FIELD_CREATED_BY')) ?>">
								<div class="eg-author">
									<span class="eg-avatar eg-avatar--<?= (int) (crc32($authorName) % 6) ?>" aria-hidden="true"><?= $e($initials($authorName)) ?></span>
									<span class="eg-author__txt">
										<span class="eg-author__name">
											<?php if ($registered && !empty($creator->id)) : ?>
												<a href="<?= $e(Route::_('index.php?option=com_users&task=user.edit&id=' . (int) $creator->id, false)) ?>"><?= $e($authorName) ?></a>
											<?php else : ?>
												<?= $e($authorName !== '' ? $authorName : '(guest)') ?>
											<?php endif; ?>
										</span>
										<?php if (!empty($item->user_email)) : ?>
											<span class="eg-author__sub"><?= $e($item->user_email) ?></span>
										<?php endif; ?>
									</span>
								</div>
								<div class="eg-meta">
									<?php if (!empty($item->user_agent)) : ?>
										<span class="eg-meta__ua hasTooltip" title="<?= $e(Text::_('COM_ENGAGE_COMMENTS_LBL_BROWSERTYPE_' . ($jBrowser->isMobile() ? 'mobile' : 'desktop'))) ?>">
											<span class="fa fa-<?= $jBrowser->isMobile() ? 'mobile-alt' : 'desktop' ?>" aria-hidden="true"></span>
											<?= $e(Text::_('COM_ENGAGE_COMMENTS_LBL_BROWSERTYPE_' . ($jBrowser->isMobile() ? 'mobile' : 'desktop'))) ?>
										</span>
									<?php endif; ?>
									<?php if (trim((string) ($item->ip ?? '')) !== '') : // 0.9.10: «0» (imported comments) is shown as it is; empty() would hide it ?>
										<span class="eg-meta__ip">
											<span class="visually-hidden"><?= $e(Text::_('COM_ENGAGE_COMMENTS_FILTER_IP')) ?></span>
											<?php if (!empty($ipLookupUrl)) : ?>
												<a href="<?= $e((string) $ipLookupUrl) ?>" class="font-monospace" target="_blank" rel="noopener"><?= $e($item->ip) ?></a>
											<?php else : ?>
												<span class="font-monospace"><?= $e($item->ip) ?></span>
											<?php endif; ?>
											<a href="<?= $e(Route::_('index.php?option=com_engage&view=comments&filter[search]=ip:' . urlencode($item->ip) . '&limitstart=0', false)) ?>"
											   class="hasTooltip" title="<?= $e(Text::_('JSEARCH_FILTER_LABEL') . ' ' . $item->ip) ?>">
												<span class="fa fa-search" aria-hidden="true"></span>
												<span class="visually-hidden"><?= $e(Text::_('JSEARCH_FILTER_LABEL') . ' ' . $item->ip) ?></span>
											</a>
										</span>
									<?php endif; ?>
								</div>
							</td>
							<td data-label="<?= $e(Text::_('COM_ENGAGE_COMMENT_FIELD_BODY')) ?>">
								<p class="eg-citem">
									<span class="eg-cid">#<?= (int) $item->id ?></span>
									<?php if ($itemType !== '') : ?>
										<span class="eg-tag" title="<?= $e($itemType) ?>"><?= $e(CommentTypes::label($itemType, $tr)) ?></span>
									<?php endif; ?>
									<?php // 0.8.5: title and link come from the sanitised sheet (Meta::sanitiseMeta / cleanUrl); the link only if there is one ?>
									<?php if ($meta['type'] !== 'unknown') : ?>
										<?php $mTitle = $meta['title'] !== '' ? $meta['title'] : Text::sprintf('COM_ENGAGE_COMMENTS_LBL_ITEM_UNAVAILABLE', (int) $item->asset_id); ?>
										<?php if (!empty($meta['url'])) : ?>
											<a href="<?= $e((string) $meta['url']) ?>"><?= $e($mTitle) ?></a>
										<?php else : ?>
											<?= $e($mTitle) ?>
										<?php endif; ?>
									<?php elseif (!empty($item->article_title)) : ?>
										<?= $e($item->article_title) ?>
										<span class="eg-badge eg-badge--warn"><?= $e(Text::sprintf('COM_ENGAGE_COMMENTS_LBL_ITEM_UNAVAILABLE', (int) $item->asset_id)) ?></span>
									<?php else : ?>
										<span class="eg-badge eg-badge--bad"><?= $e(Text::sprintf('COM_ENGAGE_COMMENTS_LBL_ITEM_UNAVAILABLE', (int) $item->asset_id)) ?></span>
									<?php endif; ?>
								</p>

								<?php if (!is_null($parent) && !empty($parentAuthor) && $public_uri !== null) : ?>
									<p class="eg-reply"><?= Text::sprintf('COM_ENGAGE_COMMENTS_LBL_INREPLYTO', htmlspecialchars((string) $public_uri->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), htmlspecialchars((string) $parentAuthor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
								<?php endif; ?>

								<div class="eg-cbody comEngageCommentPreview">
									<?php if ($excerpt === $processedComment) : ?>
										<?= $excerpt ?>
									<?php else : ?>
										<p><?= $excerpt ?></p>
										<button type="button" class="eg-btn eg-btn--small" data-bs-toggle="modal" data-bs-target="#comEngageCommentModal<?= (int) $item->id ?>">
											<?= $e(Text::_('COM_ENGAGE_COMMENTS_FILTER_BODY')) ?>
										</button>
										<div class="modal" tabindex="-1" id="comEngageCommentModal<?= (int) $item->id ?>">
											<div class="modal-dialog">
												<div class="modal-content">
													<div class="modal-header">
														<h3 class="modal-title"><?= $e(Text::_('COM_ENGAGE_COMMENTS_FILTER_BODY')) ?></h3>
														<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e(Text::_('JCLOSE')) ?>"></button>
													</div>
													<div class="modal-body p-2">
														<?= $processedComment ?>
													</div>
													<div class="modal-footer">
														<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $e(Text::_('JCLOSE')) ?></button>
													</div>
												</div>
											</div>
										</div>
									<?php endif; ?>
								</div>

								<div class="eg-actions">
									<a href="<?= $e(Route::_('index.php?option=com_engage&view=comment&task=edit&id=' . (int) $item->id, false)) ?>" class="eg-btn eg-btn--small hasTooltip" title="<?= $e(Text::_('JGLOBAL_EDIT_ITEM')) ?>">
										<span class="fa fa-edit" aria-hidden="true"></span> <?= $e(Text::_('JGLOBAL_EDIT_ITEM')) ?>
									</a>
									<?php if ($filteredAsset != $item->asset_id) : ?>
										<a href="<?= $e(Route::_('index.php?option=com_engage&view=comments&filter.asset_id=' . (int) $item->asset_id . '&limitstart=0', false)) ?>" class="eg-btn eg-btn--small eg-btn--ghost">
											<span aria-hidden="true" class="fa fa-filter"></span> <?= $e(Text::_('COM_ENGAGE_COMMENTS_LBL_FILTER_BY_ARTICLE')) ?>
										</a>
									<?php else : ?>
										<a href="<?= $e(Route::_('index.php?option=com_engage&view=comments&filter.asset_id=&limitstart=0', false)) ?>" class="eg-btn eg-btn--small eg-btn--ghost">
											<span aria-hidden="true" class="fa fa-ban"></span> <?= $e(Text::_('COM_ENGAGE_COMMENTS_LBL_UNFILTER_BY_ARTICLE')) ?>
										</a>
									<?php endif; ?>
									<?php if ($meta['type'] !== 'unknown' && !empty($meta['public_url'])) : ?>
										<a href="<?= $e((string) $meta['public_url']) ?>" target="_blank" rel="noopener" class="eg-btn eg-btn--small eg-btn--ghost hasTooltip" title="<?= $e(Text::_('COM_ENGAGE_COMMENTS_LBL_VIEWCONTENT_DESC')) ?>">
											<span class="fa fa-external-link-alt" aria-hidden="true"></span> <?= $e(Text::_('COM_ENGAGE_COMMENTS_LBL_VIEWCONTENT')) ?>
										</a>
									<?php endif; ?>
									<span class="eg-react hasTooltip" title="<?= $e(Text::plural('COM_ENGAGE_COMMENTS_LBL_NUMCOMMENTS', $numComments)) ?>">
										<span class="fa fa-comments" aria-hidden="true"></span> <?= (int) $numComments ?>
										<span class="visually-hidden"><?= $e(Text::plural('COM_ENGAGE_COMMENTS_LBL_NUMCOMMENTS', $numComments)) ?></span>
									</span>
									<?php if ($react !== null && ($react[0] > 0 || $react[1] > 0)) : ?>
										<span class="eg-react">
											<span class="fa fa-thumbs-up" aria-hidden="true"></span> <?= (int) $react[0] ?>
											<span class="fa fa-thumbs-down" aria-hidden="true"></span> <?= (int) $react[1] ?>
											<span class="visually-hidden"><?= $e(Text::sprintf('COM_ENGAGE_COMMENTS_LBL_REACTIONS', (int) $react[0], (int) $react[1])) ?></span>
										</span>
									<?php endif; ?>
								</div>
							</td>
							<td class="eg-col-date" data-label="<?= $e(Text::_('COM_ENGAGE_COMMENT_FIELD_CREATED_ON')) ?>">
								<?= $e(Factory::getDate($item->created)->format(Text::_('DATE_FORMAT_LC2'), true, true)) ?>
							</td>
							<td class="eg-col-status" data-label="<?= $e(Text::_('JSTATUS')) ?>">
								<span class="eg-badge eg-badge--<?= $e($stClass) ?>"><?= $e(Text::_($stKey)) ?></span>
								<span class="eg-statustoggle"><?= HTMLHelper::_('engage.published', $item->enabled, $i, 'comments.', $canChange, 'cb'); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?= $this->pagination->getListFooter(); ?>
		<?php endif; ?>

		<input type="hidden" name="task" value=""> <input type="hidden" name="boxchecked" value="0">
		<?= HTMLHelper::_('form.token'); ?>
	</div>
</form>
</div>
