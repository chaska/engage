<?php

namespace Prueba\Plugin\Content\Terceroprueba\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Ejemplo COMPLETO de plugin de terceros para los comentarios de Engage en el contenido de otro componente.
 * Lo documenta docs/COMENTARIOS-EN-OTROS-COMPONENTES.md y lo prueba tests/joomla-live/21-otros-componentes.sh.
 * Los datos salen de la tabla `#__tercero_prueba` (una fila por elemento, con su asset_id).
 */
class Terceroprueba extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $allowLegacyListeners = false;

	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [
			// Lo que Engage necesita saber del elemento (lo pregunta con el asset_id)
			'onAkeebaEngageGetAssetMeta' => 'onAkeebaEngageGetAssetMeta',
		];
	}

	/**
	 * Engage pregunta por un asset_id. Si es de este plugin se devuelve la ficha; si no, NULL (nunca una ficha inventada).
	 */
	public function onAkeebaEngageGetAssetMeta(Event $event): void
	{
		[$assetId] = array_values($event->getArguments());
		$result    = $event->getArgument('result', []);

		$event->setArgument('result', array_merge($result, [$this->cargarFicha((int) $assetId)]));
	}

	private function cargarFicha(int $assetId): ?array
	{
		if ($assetId <= 0)
		{
			return null;
		}

		$db    = $this->getDatabase();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__tercero_prueba'))
			->where($db->quoteName('asset_id') . ' = :asset')
			->bind(':asset', $assetId, ParameterType::INTEGER);
		$fila  = $db->setQuery($query)->loadObject();

		if (!is_object($fila))
		{
			return null;
		}

		$ahora      = Factory::getDate();
		$publicadoEn = new Date($fila->publish_up ?: 'now');
		$publicado  = ((int) $fila->published === 1) && ($publicadoEn <= $ahora);

		return [
			'type'               => 'tercero',                 // minusculas, cifras y «_», hasta 32; nunca «unknown» ni «article»
			'title'              => (string) $fila->title,     // texto plano: Engage lo escapa al mostrarlo
			'category'           => (string) $fila->category,
			'author_name'        => (string) $fila->author_name,
			'author_email'       => (string) $fila->author_email,
			'url'                => (string) $fila->url,       // relativa o del propio sitio
			'public_url'         => (string) $fila->public_url,
			'published'          => $publicado,                // bool estricto
			'published_on'       => $publicadoEn,
			'access'             => (int) $fila->access,       // nivel de acceso de Joomla (>= 1)
			'parent_access'      => ($fila->parent_access === null) ? null : (int) $fila->parent_access,
			'category_published' => ($fila->category_published === null) ? null : (int) $fila->category_published,
			'parameters'         => new Registry([
				'comments_enabled'     => (int) $fila->comments_enabled,
				'comments_close_after' => (int) $fila->close_after,
				'default_publish'      => 1,
				'comments_show'        => ($fila->comments_show === null) ? -1 : (int) $fila->comments_show,
			]),
		];
	}
}
