<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * 0.9.3: estado vacio con el diseno moderno (sin estilos en linea).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="eg-admin eg-admin--list" id="eg-admin-comments">
	<div class="eg-emptystate">
		<h2 class="eg-list-title"><?= $e(Text::_('COM_ENGAGE_COMMENTS_EMPTYSTATE_TITLE')) ?></h2>
		<p><?= $e(Text::_('COM_ENGAGE_COMMENTS_EMPTYSTATE_CONTENT')) ?></p>
	</div>
</div>
