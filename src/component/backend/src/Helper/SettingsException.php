<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Error del guardado de ajustes. El codigo HTTP es generico a proposito: 403 (sin permiso o sin token), 422 (ambito, clave o
 * valor no validos: no se distingue cual, para no dar pistas) y 500 (fallo al guardar).
 */
final class SettingsException extends \RuntimeException
{
	public const DENIED  = 403;
	public const INVALID = 422;
	public const FAILED  = 500;

	public static function denied(): self { return new self('denied', self::DENIED); }

	public static function invalid(string $why = 'invalid'): self { return new self($why, self::INVALID); }

	public static function failed(?\Throwable $previous = null): self { return new self('failed', self::FAILED, $previous); }
}
