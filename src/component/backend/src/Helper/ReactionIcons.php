<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.7.0): iconos SVG propios (dibujados para este proyecto) de los botones de reaccion. Contorno con
 * currentColor; la hoja de estilos los rellena cuando el boton tiene aria-pressed="true". Cadenas fijas: nada de lo que llega en
 * la peticion entra en el SVG.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

final class ReactionIcons
{
	private const OPEN = '<svg class="akengage-react-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

	private const THUMB = '<path d="M3 11h4v10H3z"/><path d="M7 11l4-8c1.7 0 2.8 1.3 2.5 3L13 10h6.2c1.2 0 2.1 1.1 1.9 2.3l-1.3 7c-.2 1.1-1.1 1.7-2.1 1.7H7z"/>';

	/** @param string $type like | dislike | favorite */
	public static function svg(string $type): string
	{
		switch ($type)
		{
			case 'like':
				return self::OPEN . self::THUMB . '</svg>';

			case 'dislike':
				return self::OPEN . '<g transform="translate(0 24) scale(1 -1)">' . self::THUMB . '</g></svg>';

			case 'favorite':
				return self::OPEN . '<path d="M12 2.8l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.6l-5.8 3.1 1.1-6.5L2.6 9.6l6.5-.9z"/></svg>';
		}

		return '';
	}
}
