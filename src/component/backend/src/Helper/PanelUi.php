<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.25): piezas de interfaz comunes al panel de control y a la pantalla de opciones.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

final class PanelUi
{
	/** Selector de diseno (Claro, Medio, Oscuro, Automatico): radiogroup que maneja panel.js. */
	public static function themeSwitcher(): string
	{
		$e   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$out = '<div class="eg-theme" role="radiogroup" aria-label="' . $e(Text::_('COM_ENGAGE_PANEL_THEME_LABEL')) . '">';

		foreach (['light' => 'sun', 'mid' => 'dim', 'dark' => 'moon', 'auto' => 'half'] as $t => $icon)
		{
			$out .= '<button type="button" class="eg-theme__btn" role="radio" aria-checked="false" tabindex="-1" data-eg-theme-set="' . $e($t) . '">'
				. PanelIcons::icon($icon) . '<span>' . $e(Text::_('COM_ENGAGE_PANEL_THEME_' . strtoupper($t))) . '</span></button>';
		}

		return $out . '</div>';
	}
}
