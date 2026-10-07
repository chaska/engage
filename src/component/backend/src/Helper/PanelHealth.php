<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.24): semaforo de "Estado y recomendaciones" del panel de control.
 * Funcion pura: recibe unos "hechos" (array) ya recogidos por PanelFacts y devuelve la lista de puntos con su nivel
 * (ok / warn / bad), la clave de texto y, si procede, como arreglarlo. No toca Joomla ni la base de datos ni la red.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

final class PanelHealth
{
	public const OK   = 'ok';
	public const WARN = 'warn';
	public const BAD  = 'bad';

	/** Minimos del paquete (mismos que updates/pkgengage.xml y el Dispatcher). */
	public const MIN_PHP        = '8.1.0';
	public const RECOMMEND_PHP  = '8.2.0';
	public const MIN_MYSQL      = '8.0.13';
	public const MIN_MARIADB    = '10.4.0';

	/** Servidor de actualizaciones del fork (cualquier ubicacion que contenga esto cuenta como "apunta al fork"). */
	public const FORK_MARKER = 'chaska/engage';

	/**
	 * @param   array  $f  Hechos. Claves usadas (todas opcionales; si falta una se trata como "desconocido"):
	 *   php (string), db_version (string), plugins (array 'grupo/elemento' => [exists,enabled,id]),
	 *   params (array de com_engage), gravatar (array de parametros del plugin), akismet (idem),
	 *   captcha_effective (string; vacio = ninguno), captcha_enabled (bool), purifier_cache (bool|null),
	 *   update_sites (array de [location, enabled]), can_edit_plugins (bool), can_options (bool).
	 *
	 * @return array{items:array<int,array<string,mixed>>,counts:array{ok:int,warn:int,bad:int},level:string}
	 */
	public static function evaluate(array $f): array
	{
		$items = [
			self::pluginContent($f),
			self::pluginEmail($f),
			self::pluginCache($f),
			self::antispam($f),
			self::moderation($f),
			self::gravatar($f),
			self::htmlFilter($f),
			self::purifierCache($f),
			self::php($f),
			self::database($f),
			self::updateSite($f),
		];

		$counts = [self::OK => 0, self::WARN => 0, self::BAD => 0];

		foreach ($items as $i)
		{
			$counts[$i['level']]++;
		}

		$level = $counts[self::BAD] > 0 ? self::BAD : ($counts[self::WARN] > 0 ? self::WARN : self::OK);

		return ['items' => $items, 'counts' => $counts, 'level' => $level];
	}

	/** Version de la base de datos: ['version' => '10.11.6', 'maria' => true]. 'version' vacio si no se reconoce. */
	public static function parseDbVersion(string $raw): array
	{
		$maria = stripos($raw, 'mariadb') !== false;

		// MariaDB antiguo anteponia "5.5.5-" por compatibilidad con la replicacion.
		$clean = preg_replace('/^5\.5\.5-/', '', trim($raw)) ?? '';

		if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $clean, $m) !== 1)
		{
			return ['version' => '', 'maria' => $maria];
		}

		return ['version' => $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0'), 'maria' => $maria];
	}

	/** Cierto si la lista de permitidos de HTML Purifier deja pasar la etiqueta (con o sin atributos). */
	public static function whitelistAllows(string $whitelist, string $tag): bool
	{
		$tag = strtolower($tag);

		foreach (preg_split('/[,\s]+/', strtolower($whitelist)) ?: [] as $entry)
		{
			$name = preg_replace('/[\[\*].*$/', '', $entry) ?? '';

			if ($name === $tag)
			{
				return true;
			}
		}

		return false;
	}

	/** Cierto si la lista permite el atributo src en img (img[src], img[src|alt]...). */
	public static function whitelistAllowsImgSrc(string $whitelist): bool
	{
		foreach (preg_split('/,/', strtolower($whitelist)) ?: [] as $entry)
		{
			$entry = trim($entry);

			if (preg_match('/^img\s*\[([^\]]*)\]/', $entry, $m) === 1 && in_array('src', preg_split('/[|,\s]+/', $m[1]) ?: [], true))
			{
				return true;
			}

			// "*" como atributo comodin tambien incluiria src
			if (preg_match('/^img\s*\[\*/', $entry) === 1)
			{
				return true;
			}
		}

		return false;
	}

	private static function item(string $id, string $level, array $args = [], ?array $fix = null): array
	{
		return ['id' => $id, 'level' => $level, 'args' => $args, 'fix' => $fix];
	}

	private static function plugin(array $f, string $key): array
	{
		$p = $f['plugins'][$key] ?? [];

		return ['exists' => (bool) ($p['exists'] ?? false), 'enabled' => (bool) ($p['enabled'] ?? false), 'id' => (int) ($p['id'] ?? 0)];
	}

	private static function pluginFix(array $f, array $p): ?array
	{
		if ($p['id'] > 0 && !empty($f['can_edit_plugins']))
		{
			return ['kind' => 'plugin', 'id' => $p['id']];
		}

		return null;
	}

	private static function pluginContent(array $f): array
	{
		$p = self::plugin($f, 'content/engage');

		if (!$p['exists'])
		{
			return self::item('plugin_content', self::BAD, ['state' => 'missing']);
		}

		return $p['enabled']
			? self::item('plugin_content', self::OK)
			: self::item('plugin_content', self::BAD, ['state' => 'disabled'], self::pluginFix($f, $p));
	}

	private static function pluginEmail(array $f): array
	{
		$p = self::plugin($f, 'engage/email');

		if (!$p['exists'])
		{
			return self::item('plugin_email', self::WARN, ['state' => 'missing']);
		}

		return $p['enabled']
			? self::item('plugin_email', self::OK)
			: self::item('plugin_email', self::WARN, ['state' => 'disabled'], self::pluginFix($f, $p));
	}

	private static function pluginCache(array $f): array
	{
		$joomlaCache = self::plugin($f, 'system/cache');
		$engageCache = self::plugin($f, 'system/engagecache');

		// 0.8.1: la caché «de páginas» es el plugin Sistema - Caché, pero la caché global de Joomla (conservadora/progresiva) también guarda la página
		// del artículo con los comentarios: sin el plugin Engage Cache el orden elegido se ignora, por eso también cuenta
		if (!$joomlaCache['enabled'] && (int) ($f['caching'] ?? 0) <= 0)
		{
			return self::item('plugin_cache', self::OK, ['state' => 'nocache']);
		}

		if ($engageCache['enabled'])
		{
			return self::item('plugin_cache', self::OK, ['state' => 'active']);
		}

		return self::item('plugin_cache', self::WARN, ['state' => $engageCache['exists'] ? 'disabled' : 'missing'], self::pluginFix($f, $engageCache));
	}

	private static function hasAntispam(array $f): bool
	{
		$captcha = (string) ($f['captcha_effective'] ?? '') !== '' && !empty($f['captcha_enabled']);

		$akismetPlugin = self::plugin($f, 'engage/akismet');
		$akismet       = $akismetPlugin['enabled'] && trim((string) ($f['akismet']['key'] ?? '')) !== '';

		return $captcha || $akismet;
	}

	private static function antispam(array $f): array
	{
		$captcha = (string) ($f['captcha_effective'] ?? '') !== '' && !empty($f['captcha_enabled']);
		$akismet = self::plugin($f, 'engage/akismet')['enabled'] && trim((string) ($f['akismet']['key'] ?? '')) !== '';
		$fix     = ['kind' => 'settings', 'section' => 'antispam'];

		if ($captcha && $akismet)
		{
			return self::item('antispam', self::OK, ['state' => 'both']);
		}

		if ($captcha)
		{
			return self::item('antispam', self::OK, ['state' => 'captcha']);
		}

		if ($akismet)
		{
			return self::item('antispam', self::OK, ['state' => 'akismet']);
		}

		return self::item('antispam', self::WARN, ['state' => 'none'], $fix);
	}

	private static function moderation(array $f): array
	{
		$publish = (int) ($f['params']['default_publish'] ?? 1);
		$fix     = ['kind' => 'settings', 'section' => 'moderation'];

		if ($publish === 0)
		{
			return self::item('moderation', self::OK, ['state' => 'moderated']);
		}

		if (self::hasAntispam($f))
		{
			return self::item('moderation', self::OK, ['state' => 'instant_protected'], $fix);
		}

		// Publicacion inmediata y sin ningun antispam: el spam saldria publicado en la web.
		return self::item('moderation', self::BAD, ['state' => 'instant_open'], $fix);
	}

	private static function gravatar(array $f): array
	{
		$p   = self::plugin($f, 'engage/gravatar');
		$fix = ['kind' => 'settings', 'section' => 'privacy'];

		if (!$p['enabled'])
		{
			return self::item('gravatar', self::OK, ['state' => 'disabled']);
		}

		$mode = (string) ($f['gravatar']['mode'] ?? 'ask');

		if ($mode === 'off')
		{
			return self::item('gravatar', self::OK, ['state' => 'off']);
		}

		if ($mode === 'always')
		{
			return self::item('gravatar', self::WARN, ['state' => 'always'], $fix);
		}

		$source = (string) ($f['gravatar']['consent_source'] ?? 'engage');

		if ($source === 'jbcookies')
		{
			if (trim((string) ($f['gravatar']['jbcookies_group'] ?? '')) === '')
			{
				return self::item('gravatar', self::WARN, ['state' => 'jbcookies_nogroup'], $fix);
			}

			return self::item('gravatar', self::OK, ['state' => 'ask_jbcookies']);
		}

		return self::item('gravatar', self::OK, ['state' => 'ask_engage']);
	}

	private static function htmlFilter(array $f): array
	{
		$mode      = (string) ($f['params']['filter_mode'] ?? 'strict');
		$whitelist = (string) ($f['params']['htmlpurifier_configstring'] ?? '');
		$fix       = ['kind' => 'settings', 'section' => 'security'];

		if (!in_array($mode, ['joomla', 'htmlpurifier', 'strict'], true))
		{
			return self::item('htmlfilter', self::WARN, ['state' => 'unknown'], $fix);
		}

		if ($mode === 'joomla')
		{
			return self::item('htmlfilter', self::WARN, ['state' => 'joomla'], $fix);
		}

		// Con "usar la configuracion de filtrado de Joomla" la lista propia de Engage no se aplica.
		if (empty($f['params']['htmlpurifier_config_joomla']))
		{
			foreach (['script', 'iframe', 'object', 'embed', 'style', 'form'] as $tag)
			{
				if (self::whitelistAllows($whitelist, $tag))
				{
					return self::item('htmlfilter', self::BAD, ['state' => 'dangerous', 'tag' => $tag], $fix);
				}
			}

			if (self::whitelistAllowsImgSrc($whitelist))
			{
				return self::item('htmlfilter', self::WARN, ['state' => 'img', 'mode' => $mode], $fix);
			}
		}

		return self::item('htmlfilter', self::OK, ['state' => 'ok', 'mode' => $mode]);
	}

	private static function purifierCache(array $f): array
	{
		$mode = (string) ($f['params']['filter_mode'] ?? 'strict');

		if ($mode === 'joomla')
		{
			return self::item('purifier_cache', self::OK, ['state' => 'unused']);
		}

		$w = $f['purifier_cache'] ?? null;

		if ($w === true)
		{
			return self::item('purifier_cache', self::OK, ['state' => 'writable']);
		}

		return self::item('purifier_cache', self::WARN, ['state' => $w === null ? 'unknown' : 'notwritable']);
	}

	private static function php(array $f): array
	{
		$v = (string) ($f['php'] ?? '');

		if ($v === '' || version_compare($v, self::MIN_PHP, '<'))
		{
			return self::item('php', self::BAD, ['state' => 'unsupported', 'version' => $v, 'min' => self::MIN_PHP, 'recommended' => self::RECOMMEND_PHP]);
		}

		if (version_compare($v, self::RECOMMEND_PHP, '<'))
		{
			return self::item('php', self::WARN, ['state' => 'dated', 'version' => $v, 'min' => self::MIN_PHP, 'recommended' => self::RECOMMEND_PHP]);
		}

		return self::item('php', self::OK, ['state' => 'ok', 'version' => $v, 'min' => self::MIN_PHP, 'recommended' => self::RECOMMEND_PHP]);
	}

	private static function database(array $f): array
	{
		$info = self::parseDbVersion((string) ($f['db_version'] ?? ''));
		$min  = $info['maria'] ? self::MIN_MARIADB : self::MIN_MYSQL;
		$name = $info['maria'] ? 'MariaDB' : 'MySQL';

		if ($info['version'] === '')
		{
			return self::item('database', self::WARN, ['state' => 'unknown', 'name' => $name, 'version' => '?', 'min' => $min]);
		}

		return version_compare($info['version'], $min, '<')
			? self::item('database', self::BAD, ['state' => 'old', 'name' => $name, 'version' => $info['version'], 'min' => $min])
			: self::item('database', self::OK, ['state' => 'ok', 'name' => $name, 'version' => $info['version'], 'min' => $min]);
	}

	private static function updateSite(array $f): array
	{
		$sites = (array) ($f['update_sites'] ?? []);
		$fix   = ['kind' => 'updatesites'];

		if ($sites === [])
		{
			return self::item('updatesite', self::WARN, ['state' => 'none'], $fix);
		}

		$fork = false;
		$off  = false;

		foreach ($sites as $s)
		{
			$loc = strtolower((string) ($s['location'] ?? ''));

			if (str_contains($loc, self::FORK_MARKER))
			{
				if ((int) ($s['enabled'] ?? 1) === 1)
				{
					$fork = true;
				}
				else
				{
					$off = true;
				}
			}
		}

		if ($fork)
		{
			return self::item('updatesite', self::OK, ['state' => 'fork']);
		}

		return self::item('updatesite', self::WARN, ['state' => $off ? 'disabled' : 'other'], $fix);
	}
}
