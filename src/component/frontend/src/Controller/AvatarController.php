<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\Avatar;
use Akeeba\Component\Engage\Administrator\Helper\AvatarResolver;
use Akeeba\Component\Engage\Administrator\Helper\ReactionRateLimiter;
use Akeeba\Component\Engage\Administrator\Helper\Reactions;
use Akeeba\Component\Engage\Administrator\Helper\ReactionStore;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Helper\UserProfileKey;
use Joomla\CMS\Application\ExitException;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Gravatar photos after consent (0.8.3, H4): two AJAX tasks with a JSON answer. See AvatarResolver for the idea: the page only carries an opaque
 * reference; the real Gravatar URL (which holds the hash of the commenter's e-mail address) is only given here, after the visitor consents.
 *
 *  - avatar.token   (GET)  the CSRF token of the session (never cached: the HTML of the page may be, and it cannot carry it).
 *  - avatar.resolve (POST, CSRF token) field `c` = references `<id>.<size>.<token>` separated by commas.
 *
 * Every other task answers 403. All the logic is in AvatarResolver (tested with fake data); here only the inputs are read as raw strings, the method and
 * the token are checked and the answers are generic, never cached and never repeat anything received.
 *
 * @since 0.8.3
 */
class AvatarController extends BaseController
{
	/** @inheritdoc */
	protected $default_view = 'comments';

	/** Closed list of tasks: `display` (the default) and everything else of Joomla's controller answers 403. */
	public function execute($task)
	{
		if (!in_array(strtolower((string) $task), ['token', 'resolve', 'consent'], true))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		return parent::execute($task);
	}

	/** The CSRF token of the session (GET, no side effects). */
	public function token(): void
	{
		try
		{
			$this->refuseCrossSite();

			if (!in_array(strtoupper((string) $this->input->getMethod()), ['GET', 'HEAD'], true))
			{
				$this->respond(405, ['ok' => false, 'error' => 'method']);
			}

			$this->respond(200, ['ok' => true, 'token' => Session::getFormToken()]);
		}
		catch (Throwable $e)
		{
			if ($e instanceof ExitException)
			{
				throw $e;
			}

			$this->respond(500, ['ok' => false, 'error' => 'failed']);
		}
	}

	/** The Gravatar URLs of the comments of the page (POST + token). */
	public function resolve(): void
	{
		try
		{
			$this->refuseCrossSite();

			if (strtoupper((string) $this->input->getMethod()) !== 'POST')
			{
				$this->respond(405, ['ok' => false, 'error' => 'method']);
			}

			if (!Session::checkToken('post'))
			{
				$this->respond(403, ['ok' => false, 'error' => 'denied']);
			}

			$user = UserFetcher::getUser();
			$uid  = ($user === null || $user->guest) ? 0 : (int) $user->id;

			// 0.9.13: the SERVER decides. Nobody without a session ever gets a Gravatar address (a robot or a guest cannot collect hashes of e-mail addresses), and a
			// person with a session only after their consent, stored on the server against their account (avatar.consent).
			if ($uid <= 0)
			{
				$this->respond(403, ['ok' => false, 'error' => 'login']);
			}

			if (!self::hasConsent($uid))
			{
				$this->respond(403, ['ok' => false, 'error' => 'consent']);
			}

			$ctx  = [
				'store'        => new ReactionStore(Factory::getContainer()->get(DatabaseInterface::class)),
				'secret'       => (string) Factory::getApplication()->get('secret', ''),
				'canViewAsset' => static fn(int $assetId): bool => ReactionsController::canView($user, $assetId),
				'allowRead'    => $this->limiter($uid),
				'urlFor'       => static fn(array $c, int $size): string => Avatar::getDeferredURL((int) ($c['created_by'] ?? 0), $size, (string) ($c['email'] ?? '')),
			];

			$r = (new AvatarResolver())->resolve($ctx, $this->input->post->get('c', null, 'raw'));

			$this->respond($r['status'], $r['body']);
		}
		catch (Throwable $e)
		{
			if ($e instanceof ExitException)
			{
				throw $e;
			}

			$this->respond(500, ['ok' => false, 'error' => 'failed']);
		}
	}

	/** The consent (POST + CSRF token, logged in people only): field `grant` = 1 stores it against the account, 0 removes it. */
	public function consent(): void
	{
		try
		{
			$this->refuseCrossSite();

			if (strtoupper((string) $this->input->getMethod()) !== 'POST')
			{
				$this->respond(405, ['ok' => false, 'error' => 'method']);
			}

			if (!Session::checkToken('post'))
			{
				$this->respond(403, ['ok' => false, 'error' => 'denied']);
			}

			$user = UserFetcher::getUser();
			$uid  = ($user === null || $user->guest) ? 0 : (int) $user->id;

			if ($uid <= 0)
			{
				$this->respond(403, ['ok' => false, 'error' => 'login']);
			}

			$allow = $this->limiter($uid);

			if (!$allow())
			{
				$this->respond(429, ['ok' => false, 'error' => 'rate']);
			}

			$grant = (string) $this->input->post->get('grant', '', 'raw');

			if ($grant !== '0' && $grant !== '1')
			{
				$this->respond(400, ['ok' => false, 'error' => 'invalid']);
			}

			UserProfileKey::set(Factory::getContainer()->get(DatabaseInterface::class), $uid, UserProfileKey::GRAVATAR_CONSENT, $grant === '1' ? '1' : null);
			$this->respond(200, ['ok' => true, 'granted' => $grant === '1']);
		}
		catch (Throwable $e)
		{
			if ($e instanceof ExitException)
			{
				throw $e;
			}

			$this->respond(500, ['ok' => false, 'error' => 'failed']);
		}
	}

	/** Has this account consented (server side)? Any error is «no». */
	private static function hasConsent(int $uid): bool
	{
		try
		{
			return UserProfileKey::get(Factory::getContainer()->get(DatabaseInterface::class), $uid, UserProfileKey::GRAVATAR_CONSENT) === '1';
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/** A request that a modern browser marks as coming from ANOTHER site is never ours (and gets nothing). */
	private function refuseCrossSite(): void
	{
		if (strtolower((string) $this->input->server->get('HTTP_SEC_FETCH_SITE', '', 'cmd')) === 'cross-site')
		{
			$this->respond(403, ['ok' => false, 'error' => 'denied']);
		}
	}

	/**
	 * Rate limit: 240 requests per minute and person (per user, or per IP for a guest), in a bucket of its own; with Joomla's cache. If the cache fails
	 * the request is allowed (the CSRF token, the permission checks and the 100 references limit remain).
	 */
	private function limiter(int $uid): callable
	{
		try
		{
			$cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController('output', [
				'defaultgroup' => 'com_engage_reactions',
				'caching'      => true,
				'lifetime'     => 2,
			]);
		}
		catch (Throwable $e)
		{
			return static fn(): bool => true;
		}

		$limiter = new ReactionRateLimiter(
			static function (string $k) use ($cache) {
				$v = $cache->get('rl_' . $k, 'com_engage_reactions');

				return is_string($v) ? json_decode($v, true) : null;
			},
			static function (string $k, array $v) use ($cache): void {
				$cache->store(json_encode($v), 'rl_' . $k, 'com_engage_reactions');
			},
			Reactions::READ_LIMIT
		);
		$ip  = (string) $this->input->server->get('REMOTE_ADDR', '', 'raw');
		$key = ($uid > 0) ? ('sa' . $uid) : ('ia' . substr(md5($ip), 0, 20));

		return static fn(): bool => $limiter->allowKey($key);
	}

	private function respond(int $status, array $data): void
	{
		$app = Factory::getApplication();

		if (!headers_sent())
		{
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store, private, max-age=0');
			header('Vary: Cookie');
			header('X-Content-Type-Options: nosniff');
		}

		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$app->close();
	}
}
