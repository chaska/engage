<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * 0.9.16: matriz de permisos. Vista sencilla de los permisos NATIVOS de Joomla sobre com_engage. Todo escapado; sin estilos en linea; cada celda es un boton con
 * aria-label y aria-pressed; el estado se distingue por icono (no solo por color). El JavaScript (permissions.js) solo alterna estados y envia un POST.
 */

/** @var \Akeeba\Component\Engage\Administrator\View\Permissions\HtmlView $this */

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\PermissionsMatrix as PM;
use Joomla\CMS\Language\Text;

$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$svg = static function (string $kind): string {
	$a = 'class="eg-pico eg-pico--' . $kind . '" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"';

	return match ($kind) {
		'allow'   => '<svg ' . $a . '><circle cx="10" cy="10" r="7" fill="currentColor"/></svg>',
		'inherit' => '<svg ' . $a . '><circle cx="10" cy="10" r="6.2" fill="none" stroke="currentColor" stroke-width="2.4"/></svg>',
		'unset'   => '<svg ' . $a . '><path d="M5 10h10" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" fill="none"/></svg>',
		'always'  => '<svg ' . $a . '><circle cx="10" cy="10" r="7" fill="currentColor"/><path class="eg-pico__check" d="M6.6 10.2l2.4 2.4 4.4-4.8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
		default   => '<svg ' . $a . '><path d="M5.5 5.5l9 9M14.5 5.5l-9 9" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" fill="none"/></svg>',
	};
};

$stateKeys = ['allow' => 'ALLOW', 'inherit' => 'INHERIT', 'unset' => 'UNSET', 'deny' => 'DENY', 'always' => 'ALWAYS'];
$sens      = PM::SENSITIVE;
?>
<div class="eg-admin eg-admin--list" id="eg-admin-permissions">
	<div class="eg-list-head">
		<h2 class="eg-list-title"><?= $e(Text::_('COM_ENGAGE_PERMS_HEAD')) ?></h2>
		<a href="<?= $e($this->advancedUrl) ?>" class="eg-btn eg-btn--small eg-btn--ghost"><?= $e(Text::_('COM_ENGAGE_PERMS_ADVANCED')) ?></a>
	</div>

	<p class="eg-intro"><?= $e(Text::_('COM_ENGAGE_PERMS_INTRO')) ?></p>
	<p class="eg-intro"><?= $e(Text::_('COM_ENGAGE_PERMS_VIEW_NOTE')) ?></p>

	<div class="eg-perm-wrap" role="region" tabindex="0" aria-label="<?= $e(Text::_('COM_ENGAGE_PERMS_REGION')) ?>">
		<table class="eg-perm" id="eg-perm-table"
			   data-token="<?= $e($this->token) ?>"
			   data-save-url="<?= $e($this->saveUrl) ?>"
			   data-broad="<?= $e(implode(',', $this->broad)) ?>"
			   data-sensitive="<?= $e(implode(',', $sens)) ?>"
			   data-aria="<?= $e(Text::_('COM_ENGAGE_PERMS_ARIA_TPL')) ?>"
			<?php foreach ($stateKeys as $s => $k) : ?>
			   data-s-<?= $e($s) ?>="<?= $e(Text::_('COM_ENGAGE_PERMS_STATE_' . $k)) ?>"
			<?php endforeach; ?>
			   data-t-saved="<?= $e(Text::_('COM_ENGAGE_PERMS_MSG_SAVED')) ?>"
			   data-t-nochange="<?= $e(Text::_('COM_ENGAGE_PERMS_MSG_NOCHANGE')) ?>"
			   data-t-failed="<?= $e(Text::_('COM_ENGAGE_PERMS_MSG_FAILED')) ?>"
			   data-t-pending="<?= $e(Text::_('COM_ENGAGE_PERMS_MSG_PENDING')) ?>"
			   data-t-confirm-title="<?= $e(Text::_('COM_ENGAGE_PERMS_CONFIRM_TITLE')) ?>"
			   data-t-confirm-text="<?= $e(Text::_('COM_ENGAGE_PERMS_CONFIRM_TEXT')) ?>">
			<caption class="visually-hidden"><?= $e(Text::_('COM_ENGAGE_PERMS_HEAD')) ?></caption>
			<thead>
			<tr>
				<th scope="col" class="eg-perm__group"><?= $e(Text::_('COM_ENGAGE_PERMS_COL_GROUP')) ?></th>
				<?php foreach (PM::ACTIONS as $action => $key) : ?>
					<th scope="col" class="eg-perm__action">
						<span class="eg-perm__name"><?= $e(Text::_('COM_ENGAGE_PERMS_ACT_' . $key)) ?></span>
						<span class="eg-perm__desc"><?= $e(Text::_('COM_ENGAGE_PERMS_ACT_' . $key . '_DESC')) ?></span>
					</th>
				<?php endforeach; ?>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($this->groups as $g) : $gid = (int) $g['id']; ?>
				<tr>
					<th scope="row" class="eg-perm__group eg-perm-depth-<?= (int) min(8, (int) $g['depth']) ?>"><span class="eg-perm__title"><?= $e($g['title']) ?></span></th>
					<?php foreach (PM::ACTIONS as $action => $key) :
						$st   = $this->cells[$gid][$action] ?? 'unset';
						$edit = in_array($st, ['allow', 'inherit', 'unset'], true);
						$an   = Text::_('COM_ENGAGE_PERMS_ACT_' . $key);
						$sl   = Text::_('COM_ENGAGE_PERMS_STATE_' . $stateKeys[$st]);
						?>
						<td class="eg-perm__cell">
							<button type="button" class="eg-perm-cell"
									data-g="<?= (int) $gid ?>" data-gt="<?= $e($g['title']) ?>" data-a="<?= $e($action) ?>" data-an="<?= $e($an) ?>"
									data-state="<?= $e($st) ?>" data-orig="<?= $e($st) ?>"
									aria-pressed="<?= $st === 'allow' || $st === 'always' ? 'true' : ($st === 'inherit' ? 'mixed' : 'false') ?>"
									<?= $edit ? '' : 'aria-disabled="true"' ?>
									aria-label="<?= $e(strtr(Text::_('COM_ENGAGE_PERMS_ARIA_TPL'), ['{g}' => $g['title'], '{a}' => $an, '{s}' => $sl])) ?>">
								<?php foreach (['allow', 'inherit', 'unset', 'always', 'deny'] as $kind) { echo $svg($kind); } ?>
							</button>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="eg-perm-bar">
		<button type="button" class="eg-btn" id="eg-perm-save" disabled><?= $e(Text::_('COM_ENGAGE_PERMS_SAVE')) ?></button>
		<button type="button" class="eg-btn eg-btn--ghost" id="eg-perm-discard" disabled><?= $e(Text::_('COM_ENGAGE_PERMS_DISCARD')) ?></button>
		<span id="eg-perm-status" class="eg-perm-status" role="status" aria-live="polite"></span>
	</div>

	<h3 class="eg-perm-legend-title"><?= $e(Text::_('COM_ENGAGE_PERMS_LEGEND')) ?></h3>
	<ul class="eg-perm-legend">
		<?php foreach (['allow', 'inherit', 'unset', 'always', 'deny'] as $kind) : ?>
			<li class="eg-perm-legend__item eg-perm-legend__item--<?= $e($kind) ?>"><span class="eg-perm-legend__ico"><?= $svg($kind) ?></span> <span><strong><?= $e(Text::_('COM_ENGAGE_PERMS_STATE_' . $stateKeys[$kind])) ?>.</strong> <?= $e(Text::_('COM_ENGAGE_PERMS_LEGEND_' . $stateKeys[$kind])) ?></span></li>
		<?php endforeach; ?>
	</ul>
	<p class="eg-intro"><?= $e(Text::_('COM_ENGAGE_PERMS_HOWTO')) ?></p>

	<dialog id="eg-perm-confirm" class="eg-perm-dialog" aria-labelledby="eg-perm-confirm-title">
		<h3 id="eg-perm-confirm-title" class="eg-perm-dialog__title"></h3>
		<p id="eg-perm-confirm-text" class="eg-perm-dialog__text"></p>
		<ul id="eg-perm-confirm-list" class="eg-perm-dialog__list"></ul>
		<div class="eg-perm-dialog__actions">
			<button type="button" class="eg-btn eg-btn--ghost" id="eg-perm-confirm-no"><?= $e(Text::_('JCANCEL')) ?></button>
			<button type="button" class="eg-btn" id="eg-perm-confirm-yes"><?= $e(Text::_('COM_ENGAGE_PERMS_CONFIRM_YES')) ?></button>
		</div>
	</dialog>
</div>
