<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * 0.9.14: queue of reports of comments. Everything printed is escaped; the actions are POST forms with the CSRF token (the controller also checks the permission).
 */

/** @var \Akeeba\Component\Engage\Administrator\View\Reports\HtmlView $this */

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\ReportRules;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e      = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base   = 'index.php?option=com_engage&view=reports';
$stKey  = $this->status < 0 ? 'all' : (string) $this->status;
$stats  = ['0' => 'COM_ENGAGE_REPORTS_STATUS_OPEN', '1' => 'COM_ENGAGE_REPORTS_STATUS_DISMISSED', '2' => 'COM_ENGAGE_REPORTS_STATUS_HANDLED', 'all' => 'COM_ENGAGE_REPORTS_STATUS_ALL'];
$excerpt = static function (string $html): string {
	$t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

	return mb_strlen($t) > 220 ? mb_substr($t, 0, 220) . '…' : $t;
};
?>
<div class="eg-admin eg-admin--list" id="eg-admin-reports">
	<div class="eg-list-head">
		<h2 class="eg-list-title"><?= $e(Text::_('COM_ENGAGE_REPORTS_HEAD')) ?></h2>
	</div>
	<p class="eg-intro"><?= $e(Text::_('COM_ENGAGE_REPORTS_INTRO')) ?></p>

	<form method="get" action="<?= $e(Route::_('index.php', false)) ?>" class="eg-filters" role="search" aria-label="<?= $e(Text::_('COM_ENGAGE_REPORTS_FILTERS')) ?>">
		<input type="hidden" name="option" value="com_engage"><input type="hidden" name="view" value="reports">
		<label for="eg-rp-status"><?= $e(Text::_('COM_ENGAGE_REPORTS_COL_STATUS')) ?></label>
		<select name="status" id="eg-rp-status">
			<?php foreach ($stats as $k => $lbl) : ?>
				<option value="<?= $e($k) ?>"<?= (string) $k === $stKey ? ' selected' : '' ?>><?= $e(Text::_($lbl)) ?></option>
			<?php endforeach; ?>
		</select>
		<label for="eg-rp-reason"><?= $e(Text::_('COM_ENGAGE_REPORTS_COL_REASON')) ?></label>
		<select name="reason" id="eg-rp-reason">
			<option value=""><?= $e(Text::_('COM_ENGAGE_REPORTS_REASON_ALL')) ?></option>
			<?php foreach (ReportRules::REASONS as $code => $key) : ?>
				<option value="<?= (int) $code ?>"<?= $this->reason === $code ? ' selected' : '' ?>><?= $e(Text::_('COM_ENGAGE_REPORT_REASON_' . strtoupper($key))) ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="eg-btn eg-btn--small"><?= $e(Text::_('JSEARCH_FILTER_SUBMIT')) ?></button>
	</form>

	<?php if ($this->failed) : ?>
		<p class="eg-intro"><?= $e(Text::_('COM_ENGAGE_REPORTS_ERR_FAILED')) ?></p>
	<?php elseif (!$this->rows) : ?>
		<p class="eg-intro"><?= $e(Text::_('COM_ENGAGE_REPORTS_EMPTY')) ?></p>
	<?php else : ?>
		<div class="eg-tablewrap">
			<table class="eg-table eg-table--tpl">
				<caption class="visually-hidden"><?= $e(Text::_('COM_ENGAGE_REPORTS_HEAD')) ?></caption>
				<thead>
				<tr>
					<th scope="col"><?= $e(Text::_('COM_ENGAGE_REPORTS_COL_COMMENT')) ?></th>
					<th scope="col"><?= $e(Text::_('COM_ENGAGE_REPORTS_COL_REASON')) ?></th>
					<th scope="col"><?= $e(Text::_('COM_ENGAGE_REPORTS_COL_REPORTER')) ?></th>
					<th scope="col"><?= $e(Text::_('COM_ENGAGE_REPORTS_COL_STATUS')) ?></th>
					<th scope="col"><?= $e(Text::_('COM_ENGAGE_REPORTS_COL_ACTIONS')) ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($this->rows as $r) :
					$rk = ReportRules::REASONS[(int) $r['reason']] ?? 'other';
					?>
					<tr>
						<td data-label="<?= $e(Text::_('COM_ENGAGE_REPORTS_COL_COMMENT')) ?>">
							<span class="eg-author__name"><?= $e($r['comment_name'] !== null && $r['comment_name'] !== '' ? $r['comment_name'] : '#' . (int) $r['comment_by']) ?></span>
							<span class="eg-author__sub"><?= $e($excerpt((string) $r['body'])) ?></span>
							<span class="eg-author__sub"><?= $e(Text::_((int) $r['comment_enabled'] === 1 ? 'COM_ENGAGE_REPORTS_COMMENT_PUBLISHED' : 'COM_ENGAGE_REPORTS_COMMENT_HIDDEN')) ?></span>
						</td>
						<td data-label="<?= $e(Text::_('COM_ENGAGE_REPORTS_COL_REASON')) ?>">
							<span class="eg-tag"><?= $e(Text::_('COM_ENGAGE_REPORT_REASON_' . strtoupper($rk))) ?></span>
							<?php if ((string) $r['note'] !== '') : ?><span class="eg-author__sub"><?= $e($r['note']) ?></span><?php endif; ?>
						</td>
						<td data-label="<?= $e(Text::_('COM_ENGAGE_REPORTS_COL_REPORTER')) ?>">
							<span class="eg-author__name"><?= $e($r['reporter_name'] ?? '') ?></span>
							<span class="eg-author__sub"><?= $e($r['reporter_username'] ?? '') ?> · <?= $e($r['created']) ?> UTC</span>
						</td>
						<td data-label="<?= $e(Text::_('COM_ENGAGE_REPORTS_COL_STATUS')) ?>">
							<span class="eg-badge <?= (int) $r['status'] === 0 ? 'eg-badge--warn' : 'eg-badge--ok' ?>"><?= $e(Text::_($stats[(string) (int) $r['status']] ?? 'COM_ENGAGE_REPORTS_STATUS_OPEN')) ?></span>
						</td>
						<td data-label="<?= $e(Text::_('COM_ENGAGE_REPORTS_COL_ACTIONS')) ?>">
							<div class="eg-actions">
								<a href="<?= $e(Route::_('index.php?option=com_engage&task=comment.edit&id=' . (int) $r['comment_id'], false)) ?>" class="eg-btn eg-btn--small eg-btn--ghost"><?= $e(Text::_('COM_ENGAGE_REPORTS_BTN_VIEW')) ?></a>
								<?php if ($this->canAct && (int) $r['status'] === 0) : ?>
									<?php foreach (['dismiss' => 'COM_ENGAGE_REPORTS_BTN_DISMISS', 'unpublish' => 'COM_ENGAGE_REPORTS_BTN_UNPUBLISH'] as $task => $lbl) : ?>
										<form method="post" action="<?= $e(Route::_('index.php?option=com_engage&task=reports.' . $task, false)) ?>" class="eg-inline-form">
											<input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="comment_id" value="<?= (int) $r['comment_id'] ?>">
											<?= HTMLHelper::_('form.token') ?>
											<button type="submit" class="eg-btn eg-btn--small<?= $task === 'dismiss' ? ' eg-btn--ghost' : '' ?>"><?= $e(Text::_($lbl)) ?></button>
										</form>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		$q = $base . '&status=' . $stKey . ($this->reason ? '&reason=' . $this->reason : '');
		$per = \Akeeba\Component\Engage\Administrator\View\Reports\HtmlView::PER_PAGE;
		?>
		<nav class="eg-pager" aria-label="<?= $e(Text::_('COM_ENGAGE_REPORTS_PAGER')) ?>">
			<span><?= $e(Text::sprintf('COM_ENGAGE_REPORTS_TOTAL', (int) $this->total)) ?></span>
			<?php if ($this->start > 0) : ?><a class="eg-btn eg-btn--small" href="<?= $e(Route::_($q . '&start=' . max(0, $this->start - $per), false)) ?>"><?= $e(Text::_('JPREV')) ?></a><?php endif; ?>
			<?php if ($this->start + $per < $this->total) : ?><a class="eg-btn eg-btn--small" href="<?= $e(Route::_($q . '&start=' . ($this->start + $per), false)) ?>"><?= $e(Text::_('JNEXT')) ?></a><?php endif; ?>
		</nav>
	<?php endif; ?>
</div>
