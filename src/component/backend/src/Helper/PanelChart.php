<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.24): serie diaria y geometria del mini-grafico de barras (SVG inline, sin librerias).
 * Funciones puras: no tocan Joomla ni la base de datos, para poder probarlas con datos simulados.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class PanelChart
{
	public const WIDTH  = 600;
	public const HEIGHT = 150;
	public const TOP    = 6;

	/**
	 * Serie de $days dias terminada en $today (UTC), con ceros en los dias sin comentarios.
	 *
	 * @param   array<int,array{d:string,n:int|string}>  $rows   Filas de la consulta: d = Y-m-d, n = cantidad.
	 *
	 * @return  array<int,array{date:string,n:int}>
	 */
	public static function buildSeries(array $rows, DateTimeImmutable $today, int $days = 30): array
	{
		$days   = max(1, min(366, $days));
		$byDate = [];

		foreach ($rows as $row)
		{
			$d = (string) ($row['d'] ?? '');

			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1)
			{
				$byDate[$d] = ($byDate[$d] ?? 0) + max(0, (int) ($row['n'] ?? 0));
			}
		}

		$today  = $today->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0, 0);
		$series = [];

		for ($i = $days - 1; $i >= 0; $i--)
		{
			$d        = $today->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
			$series[] = ['date' => $d, 'n' => $byDate[$d] ?? 0];
		}

		return $series;
	}

	/** Maximo de la escala: al menos 4 y, a partir de ahi, multiplo de 4 para que las lineas caigan en numeros enteros. */
	public static function scaleMax(array $series): int
	{
		$max = 0;

		foreach ($series as $p)
		{
			$max = max($max, (int) $p['n']);
		}

		return max(4, (int) (ceil($max / 4) * 4));
	}

	/**
	 * Geometria del grafico. Cada barra: columna completa (zona de puntero, mas grande que la marca), trazado con el
	 * extremo superior redondeado anclado a la linea base y los datos para el tooltip.
	 *
	 * @return array{width:int,height:int,max:int,grid:array<int,array{y:float,v:int}>,bars:array<int,array<string,mixed>>,total:int,peak:int}
	 */
	public static function geometry(array $series): array
	{
		$n      = max(1, count($series));
		$w      = self::WIDTH;
		$h      = self::HEIGHT;
		$top    = self::TOP;
		$base   = (float) $h;
		$plot   = $base - $top;
		$max    = self::scaleMax($series);
		$slot   = $w / $n;
		$barW   = round($slot * 0.58, 2);
		$bars   = [];
		$total  = 0;
		$peak   = 0;

		foreach (array_values($series) as $i => $p)
		{
			$v      = (int) $p['n'];
			$total += $v;
			$peak   = max($peak, $v);
			$x      = round($i * $slot + ($slot - $barW) / 2, 2);
			$bh     = $v > 0 ? max(3.0, round($plot * $v / $max, 2)) : 0.0;
			$y      = round($base - $bh, 2);
			$r      = round(min(3.0, $barW / 2, $bh), 2);
			$path   = '';

			if ($bh > 0)
			{
				$path = sprintf(
					'M%s,%s V%s a%s,%s 0 0 1 %s,-%s H%s a%s,%s 0 0 1 %s,%s V%s Z',
					$x, $base, round($y + $r, 2), $r, $r, $r, $r, round($x + $barW - $r, 2), $r, $r, $r, $r, $base
				);
			}

			$bars[] = [
				'date'  => (string) $p['date'],
				'n'     => $v,
				'path'  => $path,
				'hitX'  => round($i * $slot, 2),
				'hitW'  => round($slot, 2),
				'index' => $i,
			];
		}

		$grid = [];

		foreach ([0, $max / 2, $max] as $v)
		{
			$grid[] = ['y' => round($base - $plot * $v / $max, 2), 'v' => (int) $v];
		}

		return ['width' => $w, 'height' => $h, 'max' => $max, 'grid' => $grid, 'bars' => $bars, 'total' => $total, 'peak' => $peak];
	}
}
