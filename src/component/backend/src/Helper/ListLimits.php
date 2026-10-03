<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Topes de paginación del listado público de comentarios.
 *
 * `akengage_limit` y `akengage_limitstart` llegan de la petición de cualquier visitante. Sin tope, `akengage_limit=0`
 * o 999999999 devuelve el hilo completo (memoria y tiempo sin límite) y un valor no entero rompe tipos del modelo.
 * El máximo es el mayor número que ofrece la opción "default_limit" de la configuración del componente (500).
 *
 * @since 0.6.9
 */
abstract class ListLimits
{
	/** Máximo de comentarios por página que puede pedir un visitante. */
	public const MAX_LIMIT = 500;

	/** Máximo desplazamiento aceptado (evita enteros enormes; más allá del total la lista sale vacía). */
	public const MAX_START = 1000000;

	/**
	 * Límite de página efectivo.
	 *
	 * Un valor del visitante que no sea un entero positivo (0, negativo, texto, array...) se sustituye por el valor por
	 * defecto del administrador; uno superior a MAX_LIMIT se reduce a MAX_LIMIT. El valor por defecto lo fija el
	 * administrador (puede ser 0 = todos, a propósito) y solo se acota si es negativo o supera MAX_LIMIT.
	 *
	 * @param   mixed  $value    Valor recibido (cualquier tipo)
	 * @param   int    $default  Valor por defecto del administrador
	 *
	 * @return  int
	 */
	public static function limit($value, int $default): int
	{
		$default = max(0, min($default, self::MAX_LIMIT));

		if (is_string($value) && preg_match('/^[0-9]{1,18}$/', $value))
		{
			$value = (int) $value;
		}

		if (!is_int($value) || $value <= 0)
		{
			return $default;
		}

		return min($value, self::MAX_LIMIT);
	}

	/**
	 * Desplazamiento efectivo: entero entre 0 y MAX_START; cualquier otro valor da 0.
	 *
	 * @param   mixed  $value  Valor recibido (cualquier tipo)
	 *
	 * @return  int
	 */
	public static function start($value): int
	{
		if (is_string($value) && preg_match('/^[0-9]{1,18}$/', $value))
		{
			$value = (int) $value;
		}

		if (!is_int($value) || $value < 0)
		{
			return 0;
		}

		return min($value, self::MAX_START);
	}
}
