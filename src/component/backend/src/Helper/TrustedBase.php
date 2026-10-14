<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Throwable;

/**
 * The only base URL that Engage trusts to build ABSOLUTE links (0.8.3, redesigned in 0.8.4).
 *
 * Until 0.8.2 the signed moderation links of the notification emails (`SignedURL::getAbsoluteSignedURL`) and the links to the content were built with
 * the Host header of the request that caused the email: whoever posted a comment with a forged Host header made the email carry links (with the signed
 * token!) to a domain of their own. The Host header of a PUBLIC request is therefore never trusted.
 *
 * 0.8.3 only accepted `live_site` from configuration.php, which forced every installation to edit that file. 0.8.4 needs nothing from the administrator.
 * The sources, in order of priority:
 *  1. `option`  : the `email_base_url` option of Engage (Notifications group of the options), strictly validated;
 *  2. `live_site`: the one of Joomla's configuration.php, if any;
 *  3. `learned` : the base of the first AUTHENTICATED administration request (a user with core.admin on com_engage opening the Engage control
 *                 panel) while 1 and 2 are empty. It is stored once, never overwritten, never learned from a public/anonymous/AJAX/POST request.
 * Without any of them there is no trusted base: the emails carry no absolute links and the dashboard says how to fix it.
 *
 * The scheme is the one of the source, raised to https when the site forces SSL (`force_ssl = 2`, Joomla's TLS_FORCE); it is never lowered to http.
 *
 * @since 0.8.3
 */
final class TrustedBase
{
	/**
	 * Test hook. Replaces what is read from Joomla: [live_site, force_ssl, email_base_url, email_base_learned] (the last two optional).
	 *
	 * @var array|null
	 */
	public static $configOverride = null;

	/** Test hook: replaces what the validation of the option knows of the request: ['host' => the Host of the administration request]. */
	public static $requestHint = null;

	/** Test hook: when an array, learning stores here (['learned' => value]) instead of the database. */
	public static $learnStore = null;

	private const CTRL = '/[\x00-\x20\x7f"\'<>\\\\`]/';

	/**
	 * Parses and normalises a base URL.
	 *
	 * Modes: 'live' (the live_site of configuration.php: lenient about internal hosts), 'read' (a stored value being read: strict syntax, http is
	 * raised to https under force_ssl), 'save' (the option being written: like read, but http is REJECTED under force_ssl and an internal host is only
	 * accepted when it is the host of the administration request that writes it), 'learn' (like read, internal hosts never accepted).
	 *
	 * @return  array{origin:string, root:string, stored:string}|null
	 */
	public static function parse(string $url, int $forceSsl = 0, string $mode = 'live', string $allowHost = ''): ?array
	{
		$url = trim($url);

		if ($url === '' || strlen($url) > 255 || preg_match(self::CTRL, $url) === 1)
		{
			return null;
		}

		if ($mode !== 'live' && strpbrk($url, '?#@') !== false)
		{
			return null;
		}

		// Shape of the authority: host, optional port of 1 to 5 digits (parse_url alone swallows «host:» and «host:+443»)
		if (preg_match('~^https?://(?:[^/:\\[\\]]+|\\[[0-9a-fA-F:.]+\\])(?::[0-9]{1,5})?(?:/|$)~i', $url) !== 1)
		{
			return null;
		}

		$p = parse_url($url);

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

		$isIp = $host[0] === '[' || filter_var($host, FILTER_VALIDATE_IP) !== false;

		// A purely numeric last label is not a domain name: «127.1», «2130706433», «0x7f.1» are odd spellings of an IP address
		if (!$isIp && preg_match('/(?:^|\.)(?:\d+|0x[0-9a-f]*)$/', $host) === 1)
		{
			return null;
		}

		if ($host[0] === '[' && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
		{
			return null;
		}

		if ($mode === 'save' || $mode === 'learn')
		{
			$allowed = $mode === 'save' && $allowHost !== '' && strtolower(trim($allowHost, '[]')) === trim($host, '[]');

			if (!$allowed && self::isInternalHost($host))
			{
				return null;
			}
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

		if ($path !== '' && (preg_match('#^(?:/(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+)+$#D', $path) !== 1 || preg_match('#(?:^|/)\.\.?(?:/|$)|%2e|%2f|%5c#i', $path) === 1))
		{
			return null;
		}

		// Site-wide forced SSL (Joomla's force_ssl = 2, what TLS_FORCE means): https even if the source says http. Never the other way round.
		if ($forceSsl === 2 && $scheme === 'http')
		{
			if ($mode === 'save')
			{
				return null;
			}

			$scheme = 'https';

			if ($port === 80)
			{
				$port = null;
			}
		}

		$origin = $scheme . '://' . $host . ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) ? ':' . $port : '');

		return ['origin' => $origin, 'root' => $origin . $path . '/', 'stored' => $origin . $path];
	}

	/**
	 * Loopback, private, link-local, shared (CGNAT), documentation, benchmarking, multicast and other non-public addresses, «localhost» and
	 * single-label names (intranet): not valid as the public address of a site. IPv6 is normalised with inet_pton, and the IPv4 addresses embedded in
	 * IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::/96), NAT64 (64:ff9b::/96) and 6to4 (2002::/16) addresses are checked as IPv4; Teredo is refused.
	 */
	public static function isInternalHost(string $host): bool
	{
		$host = strtolower(trim($host, '[]'));

		if (filter_var($host, FILTER_VALIDATE_IP) === false)
		{
			return $host === 'localhost' || str_ends_with($host, '.localhost') || !str_contains($host, '.');
		}

		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return self::isInternalV4(ip2long($host));
		}

		$bin = @inet_pton($host);

		if ($bin === false || strlen($bin) !== 16)
		{
			return true;
		}

		$b = array_values(unpack('C*', $bin));
		$zero12 = !array_filter(array_slice($b, 0, 12));
		$v4 = static fn(int $o): int => ($b[$o] << 24) | ($b[$o + 1] << 16) | ($b[$o + 2] << 8) | $b[$o + 3];

		if ($zero12)
		{
			// ::/96 (unspecified, loopback and IPv4-compatible)
			return true;
		}

		if (!array_filter(array_slice($b, 0, 10)) && $b[10] === 0xff && $b[11] === 0xff)
		{
			return self::isInternalV4($v4(12));
		}

		if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xff && $b[3] === 0x9b)
		{
			// 64:ff9b::/96 NAT64 (embedded IPv4) and 64:ff9b:1::/48 (local use)
			return $b[4] === 0 && $b[5] === 1 ? true : self::isInternalV4($v4(12));
		}

		if ($b[0] === 0x20 && $b[1] === 0x02)
		{
			return self::isInternalV4($v4(2));
		}

		if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x00 && $b[3] === 0x00)
		{
			return true; // Teredo
		}

		if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x0d && $b[3] === 0xb8)
		{
			return true; // documentation
		}

		return ($b[0] & 0xfe) === 0xfc || ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80) || $b[0] === 0xff || ($b[0] & 0xe0) === 0x00 || ($b[0] === 0x01 && !array_filter(array_slice($b, 1, 7)));
	}

	/** Non-public IPv4 ranges (RFC 6890 and friends) for an address given as an integer. */
	private static function isInternalV4($ip): bool
	{
		if ($ip === false || $ip === null)
		{
			return true;
		}

		$ip = $ip & 0xFFFFFFFF;

		foreach ([
			['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
			['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
		] as [$net, $bits])
		{
			$mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

			if (($ip & $mask) === (ip2long($net) & $mask))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Origin and root of a configured live_site, or NULL when it cannot be trusted (empty, not http/https, odd host, credentials, query...).
	 *
	 * @return  array{origin:string, root:string}|null
	 */
	public static function fromConfig(string $liveSite, int $forceSsl = 0): ?array
	{
		return self::parse($liveSite, $forceSsl, 'live');
	}

	/**
	 * Validates the value of the `email_base_url` option when it is saved. Returns the normalised value (no trailing slash; empty = clear it).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function validateOption(string $value): string
	{
		$value = trim($value);

		if ($value === '')
		{
			return '';
		}

		$force = 0;
		$host  = '';

		if (is_array(self::$requestHint))
		{
			$force = (int) (self::$requestHint['force'] ?? 0);
			$host  = (string) (self::$requestHint['host'] ?? '');
		}
		else
		{
			try
			{
				$force = (int) Factory::getApplication()->get('force_ssl', 0);
				$host  = (string) Uri::getInstance()->getHost();
			}
			catch (Throwable $e)
			{
			}
		}

		$parsed = self::parse($value, $force, 'save', $host);

		if ($parsed === null)
		{
			throw new \InvalidArgumentException('base');
		}

		return $parsed['stored'];
	}

	/**
	 * The raw settings: [live_site, force_ssl, option, learned].
	 *
	 * @return  array{0:string,1:int,2:string,3:string}
	 */
	private static function settings(): array
	{
		if (is_array(self::$configOverride))
		{
			$o = array_values(self::$configOverride);

			return [(string) ($o[0] ?? ''), (int) ($o[1] ?? 0), (string) ($o[2] ?? ''), (string) ($o[3] ?? '')];
		}

		$app    = Factory::getApplication();
		$params = ComponentHelper::getParams('com_engage');

		return [(string) $app->get('live_site', ''), (int) $app->get('force_ssl', 0), (string) $params->get('email_base_url', ''), (string) $params->get('email_base_learned', '')];
	}

	/**
	 * The trusted base and where it comes from.
	 *
	 * @return  array{origin:string, root:string, source:string}|null
	 */
	public static function current(): ?array
	{
		try
		{
			[$live, $force, $option, $learned] = self::settings();

			foreach (['option' => [$option, 'read'], 'live_site' => [$live, 'live'], 'learned' => [$learned, 'read']] as $source => [$value, $mode])
			{
				$b = self::parse((string) $value, $force, $mode);

				if ($b !== null)
				{
					return $b + ['source' => $source];
				}
			}
		}
		catch (Throwable $e)
		{
		}

		return null;
	}

	/** Is there a trusted base? (the dashboard and the email plugin ask) */
	public static function available(): bool
	{
		return self::current() !== null;
	}

	/** Where the base comes from: 'option', 'live_site', 'learned' or '' (no base). */
	public static function source(): string
	{
		return self::current()['source'] ?? '';
	}

	/** The option is filled in but cannot be used (the dashboard warns: someone wrote it outside the validated form). */
	public static function optionIsInvalid(): bool
	{
		try
		{
			[, $force, $option] = self::settings();

			return trim($option) !== '' && self::parse($option, $force, 'read') === null;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/** `https://host[:port]/path/` or an empty string. Replaces Uri::base() / Uri::root() where an absolute URL is needed. */
	public static function root(): string
	{
		return self::current()['root'] ?? '';
	}

	/**
	 * Pure decision of the self-learning: the value to store, or NULL when nothing must be learned.
	 *
	 * @param   string  $root     Uri::root() of the authenticated administration request
	 * @param   bool    $ssl      The request came over HTTPS (as Joomla sees it)
	 * @param   int     $force    Joomla's force_ssl
	 * @param   string  $live     live_site of configuration.php
	 * @param   string  $option   Option email_base_url
	 * @param   string  $learned  Already learned base
	 */
	public static function learnCandidate(string $root, bool $ssl, int $force, string $live, string $option, string $learned): ?string
	{
		foreach ([[$option, 'read'], [$live, 'live'], [$learned, 'read']] as [$value, $mode])
		{
			if (self::parse($value, $force, $mode) !== null)
			{
				return null;
			}
		}

		// Uri::root() of an administration request is the root of the SITE, but be defensive: never keep a trailing /administrator
		$root = (string) preg_replace('~/administrator/?$~i', '/', trim($root), 1);

		if (preg_match('~^https?://~i', $root) !== 1)
		{
			return null;
		}

		if (($ssl || $force === 2) && stripos($root, 'https://') !== 0)
		{
			$root = 'https://' . (string) preg_replace('~^([^/:]+|\[[0-9a-f:.]+\]):80(?=/|$)~i', '$1', substr($root, 7), 1);
		}

		$parsed = self::parse($root, $force, 'learn');

		return $parsed === null ? null : $parsed['stored'];
	}

	/**
	 * Self-learning (0.8.4). To be called ONLY from the display of the Engage control panel in the administrator. Does nothing unless: administrator
	 * client, GET, not AJAX/JSON, a logged-in user with core.admin on com_engage and an active session. Stores the base of THIS authenticated request
	 * once, if there is no option, no (valid) live_site and nothing learned yet. Returns true when it stored something.
	 */
	public static function learnFromAdminRequest(?User $user = null): bool
	{
		try
		{
			$app = Factory::getApplication();

			if (!$app->isClient('administrator') || strtoupper((string) $app->getInput()->getMethod()) !== 'GET')
			{
				return false;
			}

			$input = $app->getInput();

			if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest' || strtolower((string) $input->getCmd('format', 'html')) !== 'html')
			{
				return false;
			}

			$user = $user ?? $app->getIdentity();

			if (!$user || $user->guest || (int) $user->id <= 0 || !$user->authorise('core.admin', 'com_engage'))
			{
				return false;
			}

			$session = $app->getSession();

			if ($session->getState() !== 'active')
			{
				return false;
			}

			[$live, $force, $option, $learned] = self::settings();
			$value = self::learnCandidate((string) Uri::root(), (bool) $app->isSslConnection(), $force, $live, $option, $learned);

			if ($value === null)
			{
				return false;
			}

			return self::storeLearned($value);
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/** Stores the learned base (re-reading the parameters first, so that it never overwrites a value written in the meantime). */
	public static function storeLearned(string $value): bool
	{
		if (is_array(self::$learnStore))
		{
			if (trim((string) (self::$learnStore['learned'] ?? '')) !== '' && self::parse((string) self::$learnStore['learned'], 0, 'read') !== null)
			{
				return false;
			}

			self::$learnStore['learned'] = $value;

			return true;
		}

		$db      = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$storage = new SettingsStorage($db);
		$params  = $storage->load('com_engage');
		$force   = (int) Factory::getApplication()->get('force_ssl', 0);

		if (self::parse((string) ($params['email_base_url'] ?? ''), $force, 'read') !== null || self::parse((string) ($params['email_base_learned'] ?? ''), $force, 'read') !== null)
		{
			return false;
		}

		$params['email_base_learned'] = $value;
		$storage->save('com_engage', $params);
		ComponentHelper::getParams('com_engage')->set('email_base_learned', $value);

		return true;
	}

	/** Forgets the learned base (the next administration visit to the control panel learns it again). */
	public static function forgetLearned(): bool
	{
		if (is_array(self::$learnStore))
		{
			self::$learnStore['learned'] = '';

			return true;
		}

		$db      = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$storage = new SettingsStorage($db);
		$params  = $storage->load('com_engage');

		$params['email_base_learned'] = '';
		$storage->save('com_engage', $params);
		ComponentHelper::getParams('com_engage')->set('email_base_learned', '');

		return true;
	}

	/**
	 * An absolute URL on the trusted base for a relative URL (`index.php?...`, `/path`) or for an absolute one (only its path, query and fragment are
	 * kept: whatever host it carried is discarded). Empty string if there is no trusted base or the URL is not a plain web address.
	 */
	public static function absolute($url): string
	{
		$base = self::current();
		$url  = is_scalar($url) ? trim((string) $url) : '';

		if ($base === null || $url === '' || strlen($url) > 2048 || preg_match(self::CTRL, $url) === 1)
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
