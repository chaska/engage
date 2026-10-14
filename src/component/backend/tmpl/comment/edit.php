<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * 0.9.3: edicion de un comentario con el diseno moderno (tarjetas por grupo de campos). El formulario, los campos, la validacion y el token son los de siempre.
 */

defined('_JEXEC') or die;

/** @var \Akeeba\Component\Engage\Administrator\View\Comment\HtmlView $this */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

HTMLHelper::_('behavior.formvalidator');

Factory::getApplication()->getDocument()->getWebAssetManager()->useStyle('com_engage.lists')->useScript('com_engage.panel.theme');

$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

?>
<div class="eg-admin eg-admin--edit" id="eg-admin-comment">
<form action="<?= $e(Route::_('index.php?option=com_engage&view=comment&layout=edit&id=' . (int) $this->item->id, false)) ?>"
      aria-label="<?= $e(Text::_('COM_ENGAGE_TITLE_COMMENTS_EDIT')) ?>"
      class="form-validate" id="autoreply-form" method="post" name="adminForm">

	<?php foreach ($this->form->getFieldsets() as $fieldset => $info): ?>
		<section class="eg-card eg-formcard">
			<div class="eg-card__head">
				<h2 class="eg-h2"><?= $e(Text::_($info->label)) ?></h2>
			</div>
			<?php foreach ($this->form->getFieldset($fieldset) as $field): ?>
				<?= $field->renderField(); ?>
			<?php endforeach; ?>
		</section>
	<?php endforeach ?>

	<input type="hidden" name="task" value="">
	<?= HTMLHelper::_('form.token'); ?>
</form>
</div>
