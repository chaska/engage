<?php

namespace Prueba\Plugin\Content\Tercerohostil\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Plugin de PRUEBA que se porta mal a proposito: devuelve tipos raros, texto con HTML y JavaScript, URLs de otro sitio...
 * Los asset_id 910001 a 910012 son suyos; para cualquier otro devuelve NULL. Engage tiene que tratar TODO esto como datos no
 * confiables: no inyectar nada y no abrir nada que no deba (tests/joomla-live/lib/otros-http.php y otros-navegador.js).
 */
class Tercerohostil extends CMSPlugin implements SubscriberInterface
{
	protected $allowLegacyListeners = false;

	protected $autoloadLanguage = true;

	public const XSS = '<script>window.__xss=1;alert(document.domain)</script><img src=x onerror="window.__xss=1;alert(1)">"\'>';

	public static function getSubscribedEvents(): array
	{
		return ['onAkeebaEngageGetAssetMeta' => 'onAkeebaEngageGetAssetMeta'];
	}

	public function onAkeebaEngageGetAssetMeta(Event $event): void
	{
		[$assetId] = array_values($event->getArguments());
		$result    = $event->getArgument('result', []);
		$event->setArgument('result', array_merge($result, [self::ficha((int) $assetId)]));
	}

	private static function ficha(int $id): ?array
	{
		if ($id < 910001 || $id > 910012)
		{
			return null;
		}

		$bien = [
			'type' => 'hostil', 'title' => 'Ficha hostil', 'category' => 'Cat', 'author_name' => 'Autor', 'author_email' => 'autor@example.invalid',
			'url' => 'index.php?tp_asset=' . $id, 'public_url' => '/index.php?tp_asset=' . $id, 'published' => true,
			'published_on' => new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC')), 'access' => 1, 'parent_access' => 1, 'category_published' => 1,
			'parameters' => new Registry(['comments_enabled' => 1, 'comments_close_after' => 0, 'default_publish' => 1]),
		];

		switch ($id)
		{
			case 910001: return array_replace($bien, ['access' => 'abc']);
			case 910002: return array_replace($bien, ['published' => 'yes']);
			case 910003: // texto con HTML/JS en todos los campos de texto y URLs peligrosas
				return array_replace($bien, ['title' => self::XSS, 'category' => self::XSS, 'author_name' => self::XSS, 'author_email' => 'a@b.c' . "\r\nBcc: x@y.z",
					'url' => 'javascript:window.__xss=1;alert(1)', 'public_url' => 'https://evil.test/x?' . self::XSS]);
			case 910004: return array_replace($bien, ['type' => 'article']);
			case 910005: return array_replace($bien, ['type' => 'Raro Tipo!']);
			case 910006: return array_replace($bien, ['parent_access' => ['1']]);
			case 910007: return array_replace($bien, ['category_published' => 'abc']);
			case 910008: return array_replace($bien, ['parameters' => ['comments_enabled' => 1]]);
			case 910009: return array_replace($bien, ['access' => null]);
			case 910010: return array_replace($bien, ['title' => ['no', 'es', 'texto'], 'author_name' => new \stdClass(), 'published_on' => 'no es una fecha', 'access' => 1.0]);
			case 910011: return array_replace($bien, ['title' => self::XSS, 'url' => '//evil.test/x', 'public_url' => 'data:text/html,<script>alert(1)</script>']); // titulo XSS en un hilo visible
			default:     return array_replace($bien, ['type' => 'unknown']);
		}
	}
}
