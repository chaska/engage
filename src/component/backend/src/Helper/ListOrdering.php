<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Lista blanca de columnas y dirección de ordenación de los listados de comentarios.
 *
 * El modelo de comentarios une las tablas de usuarios, artículos y categorías. Si el nombre de la columna de
 * ordenación llega de la petición sin validar, un visitante puede ordenar por columnas ajenas (p. ej. u.password) y
 * deducir su contenido por el orden en que salen los comentarios. Todo valor que no esté en la lista se sustituye por
 * el valor por defecto; nunca se interpreta el valor recibido.
 *
 * @since 0.4.0
 */
abstract class ListOrdering
{
	/** Columna por defecto. */
	public const DEFAULT_COLUMN = 'c.created';

	/** Dirección por defecto. */
	public const DEFAULT_DIRECTION = 'DESC';

	/**
	 * Columnas válidas para el modelo (backend y plugin). Clave: valor admitido; valor: columna real calificada.
	 * Los nombres sin alias corresponden al `filter_fields` histórico del modelo y se resuelven a la tabla de
	 * comentarios (`c`); `user_name` es el alias calculado de la consulta.
	 */
	private const MODEL_COLUMNS = [
		'c.id'          => 'c.id',
		'c.asset_id'    => 'c.asset_id',
		'c.name'        => 'c.name',
		'c.email'       => 'c.email',
		'c.ip'          => 'c.ip',
		'c.user_agent'  => 'c.user_agent',
		'c.enabled'     => 'c.enabled',
		'c.created'     => 'c.created',
		'c.created_by'  => 'c.created_by',
		'c.modified'    => 'c.modified',
		'c.modified_by' => 'c.modified_by',
		'id'            => 'c.id',
		'asset_id'      => 'c.asset_id',
		'name'          => 'c.name',
		'email'         => 'c.email',
		'ip'            => 'c.ip',
		'user_agent'    => 'c.user_agent',
		'enabled'       => 'c.enabled',
		'created'       => 'c.created',
		'created_by'    => 'c.created_by',
		'modified'      => 'c.modified',
		'modified_by'   => 'c.modified_by',
		'user_name'     => 'user_name',
	];

	/** Columnas que el listado público (frontend) acepta de la petición. */
	private const FRONTEND_COLUMNS = ['c.created', 'c.id'];

	/**
	 * Devuelve la columna de ordenación del modelo, o la de por defecto si no está en la lista blanca.
	 *
	 * @param   mixed  $column  Valor recibido (cualquier tipo)
	 *
	 * @return  string
	 */
	public static function column($column): string
	{
		if (!is_string($column))
		{
			return self::DEFAULT_COLUMN;
		}

		return self::MODEL_COLUMNS[$column] ?? self::DEFAULT_COLUMN;
	}

	/**
	 * Columna de ordenación aceptada desde la petición del visitante en el listado público.
	 *
	 * @param   mixed  $column  Valor recibido (cualquier tipo)
	 *
	 * @return  string
	 */
	public static function frontendColumn($column): string
	{
		if (!is_string($column) || !in_array($column, self::FRONTEND_COLUMNS, true))
		{
			return self::DEFAULT_COLUMN;
		}

		return $column;
	}

	/**
	 * Devuelve exactamente 'ASC' o 'DESC'; cualquier otro valor da la dirección por defecto.
	 *
	 * @param   mixed  $direction  Valor recibido (cualquier tipo)
	 *
	 * @return  string
	 */
	public static function direction($direction): string
	{
		if (!is_string($direction))
		{
			return self::DEFAULT_DIRECTION;
		}

		$direction = strtoupper($direction);

		return in_array($direction, ['ASC', 'DESC'], true) ? $direction : self::DEFAULT_DIRECTION;
	}
}
