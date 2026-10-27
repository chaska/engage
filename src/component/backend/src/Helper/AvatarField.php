<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.9.8): la foto del usuario que sale de un CAMPO PERSONALIZADO de usuario (opcion `customfield_avatar`). El valor lo escribe el propio
 * usuario: no es de fiar. Todo aqui son funciones puras (sin Joomla), probadas con valores hostiles.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use DOMDocument;
use Throwable;

final class AvatarField
{
	/** Tipos de campo personalizado de usuario que pueden llevar una foto: imagen (media), lista de imagenes, URL y texto. */
	public const TYPES = ['media', 'imagelist', 'url', 'text'];

	public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

	/** Avatares locales (genericos) que pone el plugin Gravatar en los modos «preguntar» y «desactivado»: no son una foto. */
	public const GENERIC = ['avatar-generico.svg', 'avatar-blanco.svg'];

	/** Id de campo valido para la opcion: '' (ninguno) o un entero positivo de hasta 10 cifras. */
	public static function validId($v): string
	{
		$v = is_scalar($v) ? trim((string) $v) : '';

		return preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1 ? $v : '';
	}

	/**
	 * La ruta de la imagen que lleva el valor de un campo (HTML `<img src>`, JSON de un campo de imagen, ruta o URL), o '' si no hay una segura.
	 *
	 * @param   mixed   $value       Valor del campo (de cualquier tipo)
	 * @param   string  $origin      `https://host[:puerto]` de la base fiable ('' = ninguna)
	 * @param   string  $basePath    Carpeta del sitio (`/subcarpeta` o '')
	 *
	 * @return  string  Ruta relativa a la raiz del dominio (`/images/a.jpg`) o ''
	 */
	public static function imagePath($value, string $origin = '', string $basePath = ''): string
	{
		if (is_array($value))
		{
			$value = reset($value);
		}

		if (!is_string($value) || $value === '' || strlen($value) > 4000)
		{
			return '';
		}

		$raw = trim($value);

		if (str_contains($raw, '<'))
		{
			$raw = self::srcOfFirstImage($raw);
		}
		elseif ($raw !== '' && $raw[0] === '{')
		{
			$j   = json_decode($raw, true);
			$raw = is_array($j) && is_string($j['imagefile'] ?? null) ? $j['imagefile'] : '';
		}

		return self::cleanPath($raw, $origin, $basePath);
	}

	/** El `src` de la primera etiqueta img, con el analizador de HTML (nunca con una expresion regular). */
	private static function srcOfFirstImage(string $html): string
	{
		try
		{
			$prev = libxml_use_internal_errors(true);
			$d    = new DOMDocument();
			$d->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			$img = $d->getElementsByTagName('img')->item(0);

			return $img ? trim((string) $img->getAttribute('src')) : '';
		}
		catch (Throwable $e)
		{
			return '';
		}
	}

	/** Valida una ruta o URL: solo la ruta relativa del sitio o una URL https del propio sitio, solo imagen, sin `..`. */
	public static function cleanPath(string $raw, string $origin = '', string $basePath = ''): string
	{
		$raw = trim($raw);

		if ($raw === '' || strlen($raw) > 512 || preg_match('/[\x00-\x20\x7F"\'<>`\\\\]/', $raw) === 1)
		{
			return '';
		}

		// Sufijo de los campos de imagen de Joomla (`images/a.jpg#joomlaImage://local-images/a.jpg?width=1&height=1`) y consultas: fuera
		$raw = explode('#', explode('?', $raw, 2)[0], 2)[0];

		if (preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*):~', $raw, $m) === 1)
		{
			// Solo https y solo del propio sitio (la base fiable): nada de fotos de terceros ni de esquemas raros
			if (strtolower($m[1]) !== 'https' || $origin === '' || stripos($raw, $origin . '/') !== 0)
			{
				return '';
			}

			$raw = substr($raw, strlen($origin));
		}

		if ($raw === '' || str_starts_with($raw, '//') || str_contains($raw, '\\'))
		{
			return '';
		}

		if ($raw[0] !== '/')
		{
			$raw = '/' . $raw;
		}

		$basePath = rtrim($basePath, '/');

		if ($basePath !== '' && (preg_match('~^/[A-Za-z0-9_\-./]*$~D', $basePath) !== 1 || str_contains($basePath, '..')))
		{
			return '';
		}

		// Si la ruta ya lleva la carpeta del sitio no se repite
		if ($basePath !== '' && !str_starts_with($raw, $basePath . '/'))
		{
			$raw = $basePath . $raw;
		}

		if (preg_match('#^/[A-Za-z0-9_\-./%~@+()]{1,400}$#D', $raw) !== 1)
		{
			return '';
		}

		$dec = rawurldecode($raw);

		if (preg_match('/[\x00-\x1F\x7F\\\\]/', $dec) === 1 || in_array('..', explode('/', $dec), true) || in_array('..', explode('/', $raw), true) || preg_match('~%(2e|2f|5c|00)~i', $raw) === 1)
		{
			return '';
		}

		$ext = strtolower((string) pathinfo($raw, PATHINFO_EXTENSION));

		return in_array($ext, self::EXTENSIONS, true) ? $raw : '';
	}

	/**
	 * 0.9.12: what a photo plugin may return (the string goes into an `<img src>`; it is untrusted code of a third party): ONLY
	 *  - a path of this site that starts with ONE «/» (never «//», never «/\\»: those are other hosts for the browser), or
	 *  - an https address on the trusted base of the site (`$origin`), or
	 *  - a Gravatar avatar address (https, www.gravatar.com, /avatar/<hash>).
	 * No spaces, quotes, angle brackets, backslashes or control characters; at most 2000 characters. Anything else is discarded.
	 *
	 * @param   mixed   $s       What the plugin returned
	 * @param   string  $origin  Trusted origin (scheme + host), '' if there is none
	 */
	public static function pluginResultOk($s, string $origin = ''): bool
	{
		if (!is_string($s) || $s === '' || strlen($s) > 2000 || preg_match('#[\x00-\x20\x7f\\\\"\'<>`]#', $s))
		{
			return false;
		}

		if ($s[0] === '/')
		{
			return !isset($s[1]) || $s[1] !== '/';
		}

		if (preg_match('#^https://www\.gravatar\.com/avatar/[0-9a-f]{32,64}(\?[A-Za-z0-9_=&%.+\-]*)?$#D', $s))
		{
			return true;
		}

		return $origin !== '' && str_starts_with($origin, 'https://') && str_starts_with($s, $origin . '/') && !str_contains(substr($s, strlen($origin)), '//');
	}

	/** Prioridad de un avatar: 0 = foto (de un plugin o del campo), 1 = Gravatar, 2 = avatar generico local. Gana la menor; a igualdad, la primera. */
	public static function rank(string $src): int
	{
		if (self::isGeneric($src))
		{
			return 2;
		}

		return (preg_match('~^https?://([a-z0-9.-]+\.)?gravatar\.com/~i', $src) === 1) ? 1 : 0;
	}

	/** ¿Es este avatar el generico local de Engage (no una foto)? */
	public static function isGeneric(string $src): bool
	{
		$path = explode('#', explode('?', $src, 2)[0], 2)[0];

		return in_array(strtolower(basename($path)), self::GENERIC, true) && str_contains($path, '/media/com_engage/');
	}
}
