<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * 0.9.3: plantillas de correo con el diseno moderno: lista con el nombre, el idioma, el estado y la vista previa de cada una. Las plantillas se
 * siguen editando en las Plantillas de correo de Joomla; los botones de instalar/actualizar y restablecer llevan su token (y la comprobacion de
 * permisos del controlador). Sin estilos en linea.
 */

/** @var \Akeeba\Component\Engage\Administrator\View\Emailtemplates\HtmlView $this */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$e     = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$token = Factory::getApplication()->getFormToken();
?>
<div class="eg-admin eg-admin--list" id="eg-admin-emailtemplates">
	<div class="eg-list-head">
		<h2 class="eg-list-title"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_LBL_LIST_HEAD')) ?></h2>
		<a href="<?= $e(Route::_('index.php?option=com_mails&filter[extension]=com_engage', false)) ?>" class="eg-btn eg-btn--small">
			<span class="fa fa-envelope" aria-hidden="true"></span> <?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_BTN_MAILTEMPLATES')) ?>
		</a>
	</div>

	<p class="eg-intro"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_LBL_WHERE_TEXT')) ?></p>

	<div class="eg-tablewrap">
		<table class="eg-table eg-table--tpl">
			<caption class="visually-hidden"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_LBL_LIST_HEAD')) ?></caption>
			<thead>
			<tr>
				<th scope="col"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_NAME')) ?></th>
				<th scope="col"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_LANG')) ?></th>
				<th scope="col"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_STATE')) ?></th>
				<th scope="col"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_ACTIONS')) ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($this->rows as $row) : ?>
				<tr>
					<td data-label="<?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_NAME')) ?>">
						<span class="eg-author__name"><?= $e($row['name']) ?></span>
						<span class="eg-author__sub"><?= $e($row['key']) ?></span>
					</td>
					<td data-label="<?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_LANG')) ?>">
						<?php foreach ($row['languages'] as $lang) : ?>
							<span class="eg-tag eg-lang"><?= $e($lang) ?></span>
						<?php endforeach; ?>
						<?php if (!$row['languages']) : ?>&mdash;<?php endif; ?>
					</td>
					<td data-label="<?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_STATE')) ?>">
						<?php if ($row['installed']) : ?>
							<span class="eg-badge eg-badge--ok"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_STATE_INSTALLED')) ?></span>
						<?php else : ?>
							<span class="eg-badge eg-badge--warn"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_STATE_MISSING')) ?></span>
						<?php endif; ?>
					</td>
					<td data-label="<?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_COL_ACTIONS')) ?>">
						<div class="eg-actions">
							<?php if ($row['preview'] !== '') : ?>
								<a href="<?= $e($row['preview']) ?>" class="eg-btn eg-btn--small">
									<span class="fa fa-eye" aria-hidden="true"></span> <?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_BTN_PREVIEW')) ?>
								</a>
							<?php endif; ?>
							<?php if ($row['edit'] !== '') : ?>
								<a href="<?= $e($row['edit']) ?>" class="eg-btn eg-btn--small eg-btn--ghost">
									<span class="fa fa-edit" aria-hidden="true"></span> <?= $e(Text::_('JGLOBAL_EDIT_ITEM')) ?>
								</a>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<h3 class="eg-list-title eg-list-title--sub"><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_LBL_MANAGE_HEAD')) ?></h3>
	<div class="eg-grid2">
		<div class="eg-card">
			<p><?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_LBL_UPDATE')) ?></p>
			<a href="<?= $e(Route::_('index.php?option=com_engage&view=Emailtemplates&task=updateEmails&' . $token . '=1', false)) ?>" class="eg-btn eg-btn--ok">
				<span class="fa fa-check" aria-hidden="true"></span> <?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_BTN_UPDATE')) ?>
			</a>
		</div>
		<div class="eg-card">
			<p><?= Text::_('COM_ENGAGE_EMAILTEMPLATES_LBL_RESET') ?></p>
			<a href="<?= $e(Route::_('index.php?option=com_engage&view=Emailtemplates&task=resetEmails&' . $token . '=1', false)) ?>" class="eg-btn eg-btn--bad">
				<span class="fa fa-redo-alt" aria-hidden="true"></span> <?= $e(Text::_('COM_ENGAGE_EMAILTEMPLATES_BTN_RESET')) ?>
			</a>
		</div>
	</div>
</div>
