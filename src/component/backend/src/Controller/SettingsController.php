<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\SettingsException;
use Akeeba\Component\Engage\Administrator\Helper\SettingsService;
use Akeeba\Component\Engage\Administrator\Helper\SettingsStorage;
use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Pantalla de opciones modernas (0.6.25) y su guardado instantaneo por AJAX.
 *
 * SEGURIDAD de save(): solo POST; token CSRF de Joomla; permiso core.admin o core.options del componente (o edicion de plugins
 * para los plugins de Engage); ambito, clave y valor validados contra los manifiestos; fusion con los parametros existentes;
 * respuestas 4xx genericas. Nada de lo que llega se guarda sin pasar por SettingsService.
 *
 * @since 0.6.25
 */
class SettingsController extends BaseController
{
	use ControllerEventsTrait;

	/** @inheritdoc */
	protected $default_view = 'settings';

	/** Guarda un ajuste: POST scope, key, value + token. Responde JSON. */
	public function save(): void
	{
		$app = Factory::getApplication();

		try
		{
			if (strtoupper((string) $this->input->getMethod()) !== 'POST')
			{
				$this->respond(405, ['ok' => false, 'error' => 'method']);
			}

			if (!Session::checkToken('post'))
			{
				$this->respond(403, ['ok' => false, 'error' => 'denied']);
			}

			$user = UserFetcher::getUser();

			if ($user->guest)
			{
				$this->respond(403, ['ok' => false, 'error' => 'denied']);
			}

			$post  = $this->input->post;
			$scope = $post->get('scope', null, 'raw');
			$key   = $post->get('key', null, 'raw');
			$value = $post->get('value', null, 'raw');

			$db      = Factory::getContainer()->get(DatabaseInterface::class);
			$storage = new SettingsStorage($db);
			$service = new SettingsService(
				SettingsStorage::schema(),
				static fn(string $s): bool => SettingsStorage::authorise($user, $s),
				[$storage, 'load'],
				[$storage, 'save'],
				[SettingsStorage::class, 'filter'],
				fn(): array => ['captcha' => $storage->captchaPlugins()],
				static function (string $s, string $k) use ($user): void { SettingsStorage::log($user, $s, $k); }
			);

			$r = $service->apply($scope, $key, $value);

			$this->respond(200, ['ok' => true, 'scope' => $r['scope'], 'key' => $r['key'], 'value' => $r['value'], 'changed' => $r['changed']]);
		}
		catch (SettingsException $e)
		{
			// 4xx genericos: el cuerpo no repite lo recibido ni explica que campo fallo
			$this->respond($e->getCode(), ['ok' => false, 'error' => $e->getCode() === 403 ? 'denied' : ($e->getCode() === 422 ? 'invalid' : 'failed')]);
		}
		catch (Throwable $e)
		{
			if ($e instanceof \Joomla\CMS\Application\ExitException)
			{
				throw $e;
			}

			$this->respond(500, ['ok' => false, 'error' => 'failed']);
		}
	}

	private function respond(int $status, array $data): void
	{
		$app = Factory::getApplication();

		if (!headers_sent())
		{
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store, max-age=0');
			header('X-Content-Type-Options: nosniff');
		}

		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$app->close();
	}
}
