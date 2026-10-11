<?php

namespace Prueba\Plugin\System\Tercerovista\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Hace de «vista» de un componente de terceros. Es un plugin de grupo SYSTEM porque tiene que escuchar siempre (los de grupo content
 * solo se cargan cuando un componente importa ese grupo y, con la cache de Joomla, la portada ni lo hace). Un componente real hace
 * lo mismo en su vista: construye un objeto con el asset_id y lanza onContentAfterDisplay.
 */
class Tercerovista extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $allowLegacyListeners = false;

	public static function getSubscribedEvents(): array
	{
		return ['onAfterRoute' => 'onAfterRoute', 'onAfterDispatch' => 'onAfterDispatch'];
	}

	/**
	 * Con ?tp_vista=1 deja en la cache ESTATICA de vistas de Joomla (la que comparten todos los controladores de la peticion) una vista
	 * `comments` de tipo html y prefijo `Site` como la de un componente de terceros con una vista del mismo nombre. Si Engage
	 * usara BaseController::getView() recibiria ESTA vista (y fallaria o mostraria «VISTA-AJENA»).
	 */
	public function onAfterRoute(Event $event): void
	{
		$app = $this->getApplication();

		if (!$app->isClient('site') || !$app->getInput()->exists('tp_vista'))
		{
			return;
		}

		$ajena = new class {
			public function __call($n, $a) { echo 'VISTA-AJENA'; return $this; }
			public function display() { echo 'VISTA-AJENA'; }
		};
		$p = new \ReflectionProperty(\Joomla\CMS\MVC\Controller\BaseController::class, 'views');
		$p->setAccessible(true);
		$vistas = (array) $p->getValue();
		$vistas['comments']['html']['Site'] = $ajena;
		$vistas['comment']['html']['Site']  = $ajena;
		$p->setValue(null, $vistas);
	}

	/**
	 * «Vista» del componente de prueba: ?tp=<id de fila> (o ?tp_asset=<asset_id en bruto>&tp_ctx=<contexto>) muestra el elemento y
	 * pide a Engage los comentarios con el evento estandar onContentAfterDisplay. Un componente real hace lo mismo en su vista.
	 */
	public function onAfterDispatch(Event $event): void
	{
		$app = $this->getApplication();

		$input = $app->getInput();

		if (!$app->isClient('site') || (!$input->exists('tp') && !$input->exists('tp_asset')))
		{
			return;
		}

		if ($input->exists('tp'))
		{
			$db   = $this->getDatabase();
			$id   = $input->getInt('tp', 0);
			$q    = $db->getQuery(true)->select('*')->from($db->quoteName('#__tercero_prueba'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
			$fila = $db->setQuery($q)->loadObject();

			if (!$fila)
			{
				return;
			}

			$asset = (string) $fila->asset_id;
			$titulo = (string) $fila->title;
		}
		else
		{
			$asset  = (string) $input->get('tp_asset', '', 'raw');
			$titulo = 'Elemento sin ficha';
		}

		$contexto = (string) $input->get('tp_ctx', 'com_terceroprueba.elemento', 'raw');

		// Lo que Engage espera: el contexto, un objeto con el asset_id y los parametros (un Registry)
		$item   = (object) ['asset_id' => ctype_digit($asset) ? (int) $asset : $asset, 'title' => $titulo];
		$params = new Registry();

		PluginHelper::importPlugin('content');
		$partes = $app->triggerEvent('onContentAfterDisplay', [$contexto, &$item, &$params, 0]);

		$html = '<div id="tp-elemento"><h1>' . htmlspecialchars($titulo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1></div>'
			. '<div id="tp-comentarios">' . implode('', (array) $partes) . '</div>';

		$app->getDocument()->setBuffer($html, ['type' => 'component']);
	}
}
