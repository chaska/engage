<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Preview of the notification emails (0.9.0). The preview is a sandboxed iframe (no scripts, no same-origin) fed through srcdoc: it loads nothing.
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Akeeba\Component\Engage\Administrator\View\Emailpreview\HtmlView $this */

$e     = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$quickToken = HTMLHelper::_('form.token');
?>
<div class="eg-admin eg-mailpreview">
	<p><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_INTRO')) ?></p>

	<form action="<?= $e(Route::_('index.php?option=com_engage&view=emailpreview', false)) ?>" method="get" class="eg-mailpreview__form">
		<input type="hidden" name="option" value="com_engage">
		<input type="hidden" name="view" value="emailpreview">
		<label for="eg-mp-lang"><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_LANG')) ?></label>
		<select id="eg-mp-lang" name="lang" class="form-select">
			<option value="es-ES"<?= $this->lang === 'es-ES' ? ' selected' : '' ?>>Español (es-ES)</option>
			<option value="en-GB"<?= $this->lang === 'en-GB' ? ' selected' : '' ?>>English (en-GB)</option>
		</select>
		<label for="eg-mp-kind"><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_KIND')) ?></label>
		<select id="eg-mp-kind" name="kind" class="form-select">
			<option value="moderator"<?= $this->kind === 'moderator' ? ' selected' : '' ?>><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_KIND_MODERATOR')) ?></option>
			<option value="subscriber"<?= $this->kind === 'subscriber' ? ' selected' : '' ?>><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_KIND_SUBSCRIBER')) ?></option>
		</select>
		<button type="submit" class="btn btn-secondary"><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_SHOW')) ?></button>
	</form>

	<iframe class="eg-mailpreview__frame" sandbox="" referrerpolicy="no-referrer" title="<?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_FRAME')) ?>" srcdoc="<?= $e($this->html) ?>"></iframe>

	<form action="<?= $e(Route::_('index.php?option=com_engage&task=emailpreview.sendtest', false)) ?>" method="post" class="eg-mailpreview__send">
		<input type="hidden" name="lang" value="<?= $e($this->lang) ?>">
		<input type="hidden" name="kind" value="<?= $e($this->kind) ?>">
		<button type="submit" class="btn btn-primary"><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_SEND')) ?></button>
		<span class="eg-mailpreview__hint"><?= $e(Text::_('COM_ENGAGE_MAILPREVIEW_SEND_HINT')) ?> <strong><?= $e($this->userEmail) ?></strong></span>
		<?= $quickToken ?>
	</form>
</div>
