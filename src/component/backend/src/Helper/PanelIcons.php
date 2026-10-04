<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.24): iconos SVG propios para el panel de control. Todo va en la propia pagina (hoja de simbolos
 * inline): ninguna fuente de iconos, CDN ni descarga externa. Trazo de 1,8 con extremos redondeados, cuadricula de 24.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

final class PanelIcons
{
	/** @var array<string,string> nombre => contenido SVG (cuadricula 24x24, trazo currentColor) */
	private const ICONS = [
		'chat'     => '<path d="M21 11.2c0 4.3-4 7.8-9 7.8-1.2 0-2.3-.2-3.3-.5L4 20l1.3-3.6C3.8 15.1 3 13.2 3 11.2 3 6.9 7 3.4 12 3.4s9 3.5 9 7.8z"/>',
		'mail'     => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 8 8 5.5L20 8"/>',
		'sliders'  => '<path d="M4 7h9m4 0h3M4 17h3m4 0h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
		'lock'     => '<rect x="5" y="11" width="14" height="9" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		'shield'   => '<path d="M12 3 5 6v5.5c0 4.3 2.9 7.7 7 9.5 4.1-1.8 7-5.2 7-9.5V6z"/><path d="m9.2 12 2 2 3.6-4"/>',
		'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.6h.01"/>',
		'check'    => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		'ok'       => '<circle cx="12" cy="12" r="9"/><path d="m8 12.4 2.8 2.8L16.2 9.6"/>',
		'warn'     => '<path d="M12 4 3 19.5h18z"/><path d="M12 10v4.5"/><path d="M12 17.3h.01"/>',
		'bad'      => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
		'flag'     => '<path d="M5 21V4"/><path d="M5 5h11l-2 4 2 4H5"/>',
		'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
		'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M5.6 18.4 7 17M17 7l1.4-1.4"/>',
		'moon'     => '<path d="M20 14.2A8 8 0 0 1 9.8 4a8 8 0 1 0 10.2 10.2z"/>',
		'half'     => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/>',
		'dim'      => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.5" fill="currentColor"/>',
		'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
		'book'     => '<path d="M5 4h10a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h10"/>',
		'code'     => '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M13.5 6l-3 12"/>',
		'log'      => '<path d="M7 3h8l4 4v14H7z"/><path d="M15 3v4h4M10 12h6M10 16h6"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		'palette'  => '<path d="M12 3a9 9 0 0 0 0 18c1.4 0 2-1 1.6-2.2-.4-1.2.4-2.3 1.7-2.3H17a4 4 0 0 0 4-4C21 6.7 17 3 12 3z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7.5" r="1"/><circle cx="14.5" cy="7.5" r="1"/>',
		'bell'     => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 21h4"/>',
		'eye'      => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		'wrench'   => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5l3 3 5.8-5.8a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
		'chevron'  => '<path d="m9 6 6 6-6 6"/>',
		'grid'     => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
		'heart'    => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>',
		'send'     => '<path d="M21 3 10.5 13.5M21 3l-6.5 18-4-7.5L3 9.5z"/>',
		'image'    => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.7"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
		'reply'    => '<path d="M10 8 4 13l6 5v-3.2c4.8 0 8 1.2 10 4.2-.4-5.2-3.4-9-10-9z"/>',
	];

	/** Nombres validos (para pruebas y para no pintar nunca un icono desconocido). */
	public static function names(): array
	{
		return array_keys(self::ICONS);
	}

	/** Hoja de simbolos: se imprime UNA vez por pagina, al principio del contenedor. */
	public static function sprite(): string
	{
		$out = '<svg class="eg-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" width="0" height="0">';

		foreach (self::ICONS as $name => $body)
		{
			$out .= '<symbol id="eg-i-' . $name . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
				. $body . '</symbol>';
		}

		return $out . '</svg>';
	}

	/** Un icono decorativo (aria-hidden): el texto accesible va siempre en el elemento que lo contiene. */
	public static function icon(string $name, string $class = ''): string
	{
		if (!isset(self::ICONS[$name]))
		{
			return '';
		}

		$cls = 'eg-ic' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '');

		return '<svg class="' . $cls . '" aria-hidden="true" focusable="false" width="24" height="24"><use href="#eg-i-' . $name . '"/></svg>';
	}
}
