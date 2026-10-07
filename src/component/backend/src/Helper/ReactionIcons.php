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

	private const THUMB_DOWN = '<path d="M3 13h4V3H3z"/><path d="M7 13l4 8c1.7 0 2.8-1.3 2.5-3L13 14h6.2c1.2 0 2.1-1.1 1.9-2.3l-1.3-7c-.2-1.1-1.1-1.7-2.1-1.7H7z"/>';

	/** Cabecera de los iconos de la 0.8.0 (copiar enlace, insignias): la clase la fija la hoja de estilos, 1em en las insignias. */
	private static function open(string $class): string
	{
		return '<svg class="' . $class . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
	}

	/** @param string $type like | dislike | favorite | link | check | badge-author | badge-moderator */
	public static function svg(string $type): string
	{
		switch ($type)
		{
			case 'like':
				return self::OPEN . self::THUMB . '</svg>';

			case 'dislike':
				// Trazado propio (el pulgar reflejado en vertical), sin "transform": con "prefers-reduced-motion" la hoja de estilos
				// anula las transformaciones y el icono salia como pulgar arriba en los moviles con animaciones reducidas.
				return self::OPEN . self::THUMB_DOWN . '</svg>';

			case 'favorite':
				return self::OPEN . '<path d="M12 2.8l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.6l-5.8 3.1 1.1-6.5L2.6 9.6l6.5-.9z"/></svg>';

			// 0.8.0: copiar enlace (dos eslabones de cadena) y su confirmacion (marca); insignias del autor (lapiz) y del moderador (estrella)
			case 'link':
				return self::open('akengage-react-icon akengage-copy-icon akengage-copy-icon--link') . '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3.1-3.1a4.5 4.5 0 0 0-6.4-6.4l-1.1 1.1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3.1 3.1a4.5 4.5 0 0 0 6.4 6.4l1.1-1.1"/></svg>';

			case 'check':
				return self::open('akengage-react-icon akengage-copy-icon akengage-copy-icon--done') . '<path d="M4.5 12.5l5 5 10-11"/></svg>';

			case 'badge-author':
				return self::open('akengage-badge-icon') . '<path d="M4 20l1-4.5L16.5 4a2.1 2.1 0 0 1 3 3L8 18.5z"/><path d="M14.5 6l3.5 3.5"/></svg>';

			case 'badge-moderator':
				return self::open('akengage-badge-icon') . '<path d="M12 2.8l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.6l-5.8 3.1 1.1-6.5L2.6 9.6l6.5-.9z"/></svg>';
		}

		return '';
	}
}
