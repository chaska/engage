<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\ReactionRateLimiter;
use Akeeba\Component\Engage\Administrator\Helper\Reactions;
use Akeeba\Component\Engage\Administrator\Helper\ReactionStore;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Site\Helper\Meta;
use Joomla\CMS\Application\ExitException;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Reacciones a los comentarios (0.7.0): me gusta, no me gusta y favorito. Dos tareas AJAX con respuesta JSON:
 *
 *  - reactions.toggle (POST, token CSRF de Joomla): alterna una reaccion. Parametros: comment_id, type (like|dislike|favorite).
 *  - reactions.state  (GET, sin efectos): contadores y estado propio de hasta 100 comentarios (ids=1,2,3). Devuelve ademas el
 *    token CSRF del usuario con sesion, porque el HTML de la pagina puede estar en cache y no puede llevarlo.
 *
 * SEGURIDAD: toda la logica esta en Reactions (probada con datos simulados) y todo el SQL en ReactionStore (parametros
 * enlazados). Aqui solo se leen las entradas como cadenas crudas, se comprueban metodo y token y se contesta con 4xx genericos,
 * sin cache y sin repetir nada de lo recibido.
 *
 * @since 0.7.0
 */
class ReactionsController extends BaseController
{
	/** @inheritdoc */
	protected $default_view = 'comments';

	/** Alterna una reaccion (POST + token). */
	public function toggle(): void
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

			$ctx = $this->context();
			$ctx['allowRate'] = $this->rateLimiter();
			$ctx['now']       = gmdate('Y-m-d H:i:s');

			$r = $this->logic()->toggle($ctx, $this->input->post->get('comment_id', null, 'raw'), $this->input->post->get('type', null, 'raw'));

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

	/** Contadores y estado propio (GET, sin efectos secundarios). */
	public function state(): void
	{
		try
		{
			$this->refuseCrossSite();

			if (!in_array(strtoupper((string) $this->input->getMethod()), ['GET', 'HEAD'], true))
			{
				$this->respond(405, ['ok' => false, 'error' => 'method']);
			}

			$ctx              = $this->context();
			$ctx['allowRead'] = $this->readLimiter((int) $ctx['userId']);
			$r                = $this->logic()->state($ctx, $this->input->get->get('ids', null, 'raw'));
			$b   = $r['body'];

			// El token solo se entrega a quien tiene sesion y puede reaccionar (nunca en cache: ver respond())
			if ($r['status'] === 200 && !empty($b['can']))
			{
				$b['token'] = Session::getFormToken();
			}

			$this->respond($r['status'], $b);
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

	/**
	 * Defensa en profundidad: un navegador moderno marca con Sec-Fetch-Site: cross-site las peticiones que inicia OTRO sitio. Esas nunca
	 * son del JavaScript de esta pagina, asi que se rechazan (y no reciben el token). Los clientes que no envian la cabecera (curl, pruebas)
	 * siguen dependiendo del token CSRF y de la cookie de sesion, que son la defensa principal.
	 */
	private function refuseCrossSite(): void
	{
		if (strtolower((string) $this->input->server->get('HTTP_SEC_FETCH_SITE', '', 'cmd')) === 'cross-site')
		{
			$this->respond(403, ['ok' => false, 'error' => 'denied']);
		}
	}

	private function logic(): Reactions
	{
		return new Reactions(new ReactionStore(Factory::getContainer()->get(DatabaseInterface::class)));
	}

	/**
	 * Usuario, opciones y permisos de la peticion.
	 *
	 * @return array{userId:int,userEmail:string,canReact:bool,opts:array,canViewAsset:callable}
	 */
	private function context(): array
	{
		$user = UserFetcher::getUser();
		$p    = ComponentHelper::getParams('com_engage');
		$opts = Reactions::options(static fn(string $k, $d) => $p->get($k, $d));
		$uid  = $user->guest ? 0 : (int) $user->id;
		$can  = ($uid > 0) && ($opts['who'] === 'commenters' ? (bool) $user->authorise('core.create', 'com_engage') : true);

		return [
			'userId'       => $uid,
			// 0.7.1: email de la cuenta con sesion (el de la sesion de Joomla, no el de la peticion), para saber si un comentario de invitado es «propio»
			'userEmail'    => ($uid > 0) ? (string) $user->email : '',
			'canReact'     => $can,
			'opts'         => $opts,
			'canViewAsset' => static fn(int $assetId): bool => self::canView($user, $assetId),
		];
	}

	/** El usuario puede ver el contenido al que pertenece el comentario (publicado y con nivel de acceso). */
	private static function canView(User $user, int $assetId): bool
	{
		if ($assetId <= 0)
		{
			return false;
		}

		$meta = Meta::getAssetAccessMeta($assetId, false);

		if (($meta['type'] ?? 'unknown') === 'unknown')
		{
			return false;
		}

		if (empty($meta['published']) && !$user->authorise('core.edit.state', 'com_engage'))
		{
			return false;
		}

		// 0.7.1: the category must be published (or archived) for EVERYONE, moderators included: that is what Joomla's own
		// article page does (it answers 404 for unpublished and trashed categories whoever asks). Only the article's own category
		// is evaluated, like Joomla does.
		if (!Meta::isCategoryPublished($meta))
		{
			return false;
		}

		$levels = $user->getAuthorisedViewLevels();

		foreach (['access', 'parent_access'] as $k)
		{
			$v = (int) ($meta[$k] ?? 0);

			if ($v > 0 && !in_array($v, $levels, true))
			{
				return false;
			}
		}

		return true;
	}

	/** Limitador por usuario: cache de Joomla (clave por usuario) y, si falla, la sesion. */
	private function rateLimiter(): callable
	{
		$cache = null;

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
			$cache = null;
		}

		$session = Factory::getApplication()->getSession();
		$read    = static function (string $k) use ($cache, $session) {
			if ($cache !== null)
			{
				$v = $cache->get('rl_' . $k, 'com_engage_reactions');

				return is_string($v) ? json_decode($v, true) : null;
			}

			return $session->get('com_engage.rl.' . $k);
		};
		$write = static function (string $k, array $v) use ($cache, $session): void {
			if ($cache !== null)
			{
				$cache->store(json_encode($v), 'rl_' . $k, 'com_engage_reactions');

				return;
			}

			$session->set('com_engage.rl.' . $k, $v);
		};

		$limiter = new ReactionRateLimiter($read, $write);

		return static fn(int $uid): bool => $limiter->allow($uid);
	}

	/**
	 * 0.7.1: limite de frecuencia de las CONSULTAS (state): 240 por minuto y persona. Para quien tiene sesion cuenta por usuario; para un
	 * invitado, por IP (REMOTE_ADDR; detras de un proxy inverso sin configurar de Joomla todos comparten IP: el limite es generoso, una visita
	 * de pagina hace una consulta). Usa la misma cache de Joomla que el limite de escritura, en otra cubeta; si falla, se permite.
	 */
	private function readLimiter(int $uid): callable
	{
		$cache = null;

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
			$cache = null;
		}

		if ($cache === null)
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
		$key = ($uid > 0) ? ('s' . $uid) : ('i' . substr(md5($ip), 0, 20));

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
