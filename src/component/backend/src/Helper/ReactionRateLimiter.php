<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.7.0): limite de frecuencia de las reacciones (por usuario, ventana fija). El contador vive donde diga
 * quien lo construye (cache de Joomla por usuario; la sesion como respaldo) y se inyecta como dos funciones. Es un freno contra
 * abusos, no una garantia atomica: dos peticiones simultaneas pueden contar una sola vez, lo que no cambia el orden de magnitud.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Throwable;

final class ReactionRateLimiter
{
	/** @var callable(string):mixed */
	private $read;
	/** @var callable(string,array):void */
	private $write;
	/** @var int */
	private $limit;
	/** @var int */
	private $window;
	/** @var callable():int */
	private $clock;

	public function __construct(callable $read, callable $write, int $limit = Reactions::RATE_LIMIT, int $window = Reactions::RATE_WINDOW, ?callable $clock = null)
	{
		$this->read   = $read;
		$this->write  = $write;
		$this->limit  = max(1, $limit);
		$this->window = max(1, $window);
		$this->clock  = $clock ?? static fn(): int => time();
	}

	/** true = se permite (y se cuenta); false = limite superado. */
	public function allow(int $userId): bool
	{
		$key = 'u' . max(0, $userId);
		$now = ($this->clock)();

		try
		{
			$s = ($this->read)($key);
			$s = (is_array($s) && isset($s['t'], $s['n'])) ? ['t' => (int) $s['t'], 'n' => (int) $s['n']] : ['t' => $now, 'n' => 0];

			if ($now - $s['t'] >= $this->window || $now < $s['t'])
			{
				$s = ['t' => $now, 'n' => 0];
			}

			if ($s['n'] >= $this->limit) { return false; }

			$s['n']++;
			($this->write)($key, $s);
		}
		catch (Throwable $e)
		{
			// Si el contador falla, mejor permitir (solo es un freno) que bloquear a todos
			return true;
		}

		return true;
	}
}
