<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Throwable;

/**
 * The only base URL that Engage trusts to build ABSOLUTE links (0.8.3).
 *
 * Until 0.8.2 the signed moderation links of the notification emails (`SignedURL::getAbsoluteSignedURL`) and the links to the content were built with
 * the Host header of the request that caused the email (`Route::_(..., TLS_IGNORE, absolute)`, `Uri::base()`): whoever posted a comment with a forged
 * Host header made the email carry links (with the signed token!) to a domain of their own, and `Route::TLS_IGNORE` let the link drop to http.
 *
 * The base now comes ONLY from the `live_site` that the administrator wrote in Joomla's configuration.php (never from the request). Without it there
 * is no trusted base: the emails carry no absolute links (and the dashboard says so). The scheme is the one of `live_site`, raised to https when
 * the site forces SSL (`force_ssl = 2`, Joomla's TLS_FORCE); it is never lowered to http.
 *
 * @since 0.8.3
 */
final class TrustedBase
{
	/** Test hook: replaces [live_site, force_ssl]. */
	public static $configOverride = null;

	/**
	 * Origin and root of a configured live_site, or NULL when it cannot be trusted (empty, not http/https, odd host, credentials, query...).
	 *
	 * @return  array{origin:string, root:string}|null
	 */
	public static function fromConfig(string $liveSite, int $forceSsl = 0): ?array
	{
		$liveSite = trim($liveSite);

		if ($liveSite === '' || strlen($liveSite) > 255 || preg_match('/[\x00-\x20\x7f"\'<>\\\\`]/', $liveSite) === 1)
		{
			return null;
		}

		$p = parse_url($liveSite);

		if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']))
		{
			return null;
		}

		$scheme = strtolower($p['scheme']);

		if (!in_array($scheme, ['http', 'https'], true))
		{
			return null;
		}

		$host = strtolower($p['host']);

		if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\[[0-9a-f:.]+\])$/D', $host) !== 1)
		{
			return null;
		}

		$port = null;

		if (isset($p['port']))
		{
			if ($p['port'] < 1 || $p['port'] > 65535)
			{
				return null;
			}

			$port = (int) $p['port'];
		}

		$path = rtrim((string) ($p['path'] ?? ''), '/');

		if ($path !== '' && (preg_match('#^(?:/[A-Za-z0-9._~%-]+)+$#D', $path) !== 1 || preg_match('#(?:^|/)\.\.?(?:/|$)|%2e|%2f|%5c#i', $path) === 1))
		{
			return null;
		}

		// Site-wide forced SSL (Joomla's force_ssl = 2, what TLS_FORCE means): https even if live_site says http. Never the other way round.
		if ($forceSsl === 2 && $scheme === 'http')
		{
			$scheme = 'https';

			if ($port === 80)
			{
				$port = null;
			}
		}

		$origin = $scheme . '://' . $host . ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) ? ':' . $port : '');

		return ['origin' => $origin, 'root' => $origin . $path . '/'];
	}

	/** @return array{origin:string, root:string}|null */
	public static function current(): ?array
	{
		try
		{
			if (is_array(self::$configOverride))
			{
				[$live, $force] = self::$configOverride;
			}
			else
			{
				$app   = Factory::getApplication();
				$live  = (string) $app->get('live_site', '');
				$force = (int) $app->get('force_ssl', 0);
			}

			return self::fromConfig((string) $live, (int) $force);
		}
		catch (Throwable $e)
		{
			return null;
		}
	}

	/** Is there a trusted base? (the dashboard and the email plugin ask) */
	public static function available(): bool
	{
		return self::current() !== null;
	}

	/** `https://host[:port]/path/` or an empty string. Replaces Uri::base() / Uri::root() where an absolute URL is needed. */
	public static function root(): string
	{
		return self::current()['root'] ?? '';
	}

	/**
	 * An absolute URL on the trusted base for a relative URL (`index.php?...`, `/path`) or for an absolute one (only its path, query and fragment are
	 * kept: whatever host it carried is discarded). Empty string if there is no trusted base or the URL is not a plain web address.
	 */
	public static function absolute($url): string
	{
		$base = self::current();
		$url  = is_scalar($url) ? trim((string) $url) : '';

		if ($base === null || $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f"\'<>\\\\`]/', $url) === 1)
		{
			return '';
		}

		if (preg_match('~^https?://~i', $url) === 1)
		{
			$p = parse_url($url);

			if (!is_array($p) || !isset($p['host']) || isset($p['user']) || isset($p['pass']))
			{
				return '';
			}

			$url = (string) ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');

			return $base['origin'] . ((($url[0] ?? '') === '/') ? $url : '/' . $url);
		}

		if (str_starts_with($url, '//') || preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $url) === 1)
		{
			return '';
		}

		if ($url[0] === '/')
		{
			return $base['origin'] . $url;
		}

		return $base['root'] . $url;
	}
}
