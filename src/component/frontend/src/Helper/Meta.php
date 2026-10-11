<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\Helper;

defined('_JEXEC') or die();

use Akeeba\Component\Engage\Administrator\Mixin\RunPluginsTrait;
use Akeeba\Component\Engage\Administrator\Model\CommentsModel;
use DateInterval;
use Exception;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Throwable;

/**
 * Helper class to get information about articles and their comments
 *
 * @since 1.0.0
 */
final class Meta
{
	use RunPluginsTrait;

	/**
	 * Cached results of resource metadata per asset ID
	 *
	 * @var  array
	 */
	private static $cachedMeta = [];

	/**
	 * IDs of comments per asset in the same order they are paginated in the front-end
	 *
	 * @var  array
	 */
	private static $commentIDsPerAsset = [];

	/**
	 * The MVC Factory object for com_engage
	 *
	 * @var   MVCFactoryInterface|null
	 * @since 3.0.0
	 */
	private static $mvcFactory;

	/**
	 * Number of comments per asset ID
	 *
	 * @var  int[]
	 */
	private static $numCommentsPerAsset = [];

	/**
	 * Are the comments closed for the specified resource for any reason?
	 *
	 * @param   int  $assetId  The asset ID of the resource
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	public static function areCommentsClosed(int $assetId = 0)
	{
		$meta = self::getAssetAccessMeta($assetId, true);

		/** @var Registry $params */
		$params = $meta['parameters'];

		if ($params->get('comments_enabled', 0) != 1)
		{
			return true;
		}

		return self::areCommentsClosedAfterTime($assetId);
	}

	/**
	 * Are the comments autoamtically closed for the specified resource because a certain amoutn of time elapsed?
	 *
	 * @param   int  $assetId  The asset ID of the resource
	 *
	 * @return  bool
	 * @since   3.0.7
	 */
	public static function areCommentsClosedAfterTime(int $assetId = 0): bool
	{
		$meta = self::getAssetAccessMeta($assetId, true);
		/** @var Registry $params */
		$params     = $meta['parameters'];
		$closeAfter = $params->get('comments_close_after', 0);

		if ($closeAfter <= 0)
		{
			return false;
		}

		// Check if the comments are auto-closed.
		/** @var Date $date */
		$date = clone $meta['published_on']; // 0.8.2: Date::add() modifica el objeto; sin clonar, cada llamada movia la fecha de la cache

		try
		{
			return ($date->add(new DateInterval(sprintf('P%dD', $closeAfter)))->toUnix() <= time());
		}
		catch (Exception $e)
		{
			return false;
		}
	}

	/**
	 * Is the category of the content published? (0.7.1)
	 *
	 * Joomla's own article page serves an article only when its category has `published > 0` (published or archived; unpublished
	 * and trashed categories answer 404) and the user has the category's access level. It looks at the article's OWN category only,
	 * never at the ancestors, and so do we (checked against a real Joomla). NULL (not applicable: the asset has no category) counts
	 * as published.
	 *
	 * @param   array  $meta  The result of getAssetAccessMeta()
	 *
	 * @return  bool
	 * @since   0.7.1
	 */
	public static function isCategoryPublished(array $meta): bool
	{
		$value = $meta['category_published'] ?? null;

		return ($value === null) || ((int) $value > 0);
	}

	/**
	 * Returns the metadata of an asset.
	 *
	 * This method goes through the onAkeebaEngageGetAssetMeta plugin event, allowing different plugins to return
	 * information about the asset IDs they recognize. The results are cached to avoid expensive roundtrips to the
	 * Joomla plugin event system and the database.
	 *
	 * @param   int   $assetId         The asset ID to load
	 * @param   bool  $loadParameters  Should I also load the asset's parameters?
	 *
	 * @return  array{type:string, title:string, category:string, author_name:string, author_email:string, url:string,
	 *     public_url:string, published:bool, published_on:Date, access:int, parent_access:int,
	 *     category_published:int|null, parameters:Registry}
	 * @since   1.0.0
	 */
	public static function getAssetAccessMeta(int $assetId = 0, bool $loadParameters = false): array
	{
		$metaKey    = hash('md5', $assetId . '_' . ($loadParameters ? 'with' : 'without') . '_parameters');
		$altMetaKey = hash('md5', $assetId . '_with_parameters');

		if (array_key_exists($metaKey, self::$cachedMeta))
		{
			return self::$cachedMeta[$metaKey];
		}

		if (array_key_exists($altMetaKey, self::$cachedMeta))
		{
			return self::$cachedMeta[$altMetaKey];
		}

		self::$cachedMeta[$metaKey] = [
			'type'          => 'unknown',
			'title'         => '',
			'category'      => null,
			'author_name'   => null,
			'author_email'  => null,
			'url'           => null,
			'public_url'    => null,
			'published'     => false,
			'published_on'  => clone Factory::getDate(),
			'access'        => 0,
			'parent_access' => null,
			// 0.7.1: published state of the content's category (NULL = unknown / not applicable); see isAssetViewable()
			'category_published' => null,
			'parameters'    => new Registry(),
		];

		PluginHelper::importPlugin('content');
		$pluginResults = self::triggerPluginEventStatic('onAkeebaEngageGetAssetMeta', [$assetId, $loadParameters]);

		$pluginResults = array_filter($pluginResults, function ($x) {
			return is_array($x);
		});

		if (empty($pluginResults))
		{
			return self::$cachedMeta[$metaKey];
		}

		/**
		 * 0.8.3: no se toma «la primera ficha que llega». Cada ficha es DATO NO CONFIABLE (0.8.2: sanitiseMeta) y ademas tiene que ser coherente con
		 * el activo (assetFits): un artículo (`com_content.article.N` en #__assets) solo lo describe una ficha de tipo `article`; el resto de activos
		 * existen en #__assets, con nombre `com_<componente>.…`, y su ficha lleva un `type` coherente con ese componente (nunca `article`, nunca
		 * `com_content`). Las fichas que no cumplen se descartan; si quedan DOS o mas validas el activo es ambiguo y se cierra (fail-closed).
		 */
		$assetName = self::assetName($assetId);
		$valid     = [];

		foreach ($pluginResults as $candidate)
		{
			$clean = self::sanitiseMeta($candidate);

			if ($clean !== null && self::assetFits($clean['type'], $assetName))
			{
				$valid[] = $clean;
			}
		}

		if (count($valid) !== 1)
		{
			return self::$cachedMeta[$metaKey];
		}

		$tempRet = $valid[0];

		foreach (self::$cachedMeta[$metaKey] as $k => $v)
		{
			if (!array_key_exists($k, $tempRet))
			{
				continue;
			}

			self::$cachedMeta[$metaKey][$k] = $tempRet[$k] ?? $v;
		}

		return self::$cachedMeta[$metaKey];
	}

	/**
	 * Resolves the name of an asset (`#__assets.name`) from its ID. Tests replace it with their own callable. NULL = the asset does not exist.
	 *
	 * @var   callable|null
	 * @since 0.8.3
	 */
	public static $assetNameResolver = null;

	/** @var array<int, string|null> */
	private static $assetNames = [];

	/**
	 * Name of the asset in `#__assets` (e.g. `com_content.article.12`), or NULL when it does not exist or cannot be read (fail-closed).
	 *
	 * @since 0.8.3
	 */
	public static function assetName(int $assetId): ?string
	{
		if ($assetId <= 0)
		{
			return null;
		}

		if (array_key_exists($assetId, self::$assetNames))
		{
			return self::$assetNames[$assetId];
		}

		try
		{
			if (is_callable(self::$assetNameResolver))
			{
				$name = (self::$assetNameResolver)($assetId);
			}
			else
			{
				$db    = Factory::getContainer()->get(DatabaseInterface::class);
				$query = $db->getQuery(true)
					->select($db->quoteName('name'))
					->from($db->quoteName('#__assets'))
					->where($db->quoteName('id') . ' = :id')
					->bind(':id', $assetId, ParameterType::INTEGER);
				$name  = $db->setQuery($query)->loadResult();
			}
		}
		catch (Throwable $e)
		{
			$name = null;
		}

		return self::$assetNames[$assetId] = (is_string($name) && $name !== '') ? $name : null;
	}

	/**
	 * Does a record of this type describe an asset with this name?
	 *
	 * - `com_content.article.<id>`: only the `article` type (that of Engage's content plugin).
	 * - `com_<component>.…` of any other component (never `com_content` or `com_engage`): a type different from `article` and coherent with the
	 *   component: equal to its name without `com_`, or one is the beginning of the other (at least 3 characters: `viaje` and `com_viajes`).
	 * - Anything else (no name, `root.1`, `com_content.category.3`...): no.
	 *
	 * @since 0.8.3
	 */
	public static function assetFits(string $type, ?string $assetName): bool
	{
		if ($assetName === null || $type === '' || $type === 'unknown')
		{
			return false;
		}

		if (preg_match('/^com_content\.article\.[1-9][0-9]*$/D', $assetName) === 1)
		{
			return $type === 'article';
		}

		if ($type === 'article' || preg_match('/^com_([a-z][a-z0-9_]{1,63})\.[^\s]+$/D', $assetName, $m) !== 1)
		{
			return false;
		}

		$component = $m[1];

		if (in_array($component, ['content', 'engage'], true))
		{
			return false;
		}

		if ($component === $type)
		{
			return true;
		}

		$short = (strlen($type) <= strlen($component)) ? $type : $component;
		$long  = (strlen($type) <= strlen($component)) ? $component : $type;

		return strlen($short) >= 3 && str_starts_with($long, $short);
	}

	/**
	 * Valor «nunca concedido»: un nivel de acceso que ningun usuario tiene (los niveles reales son enteros positivos pequenos).
	 * Se usa cuando el plugin devuelve un nivel invalido: el contenido queda cerrado para todos (fail-closed).
	 */
	public const NO_LEVEL = PHP_INT_MAX;

	/** Claves de los parametros por contenido que se aceptan (las de forms/engage.xml del plugin de contenido). */
	private const PARAM_KEYS = [
		'comments_show', 'default_publish', 'comments_show_featured', 'comments_show_category', 'comments_show_article',
		'comments_ordering', 'comments_enabled', 'comments_close_after', 'comments_notify_author', 'comments_notify_users',
	];

	/**
	 * Convierte un asset_id llegado de fuera en un entero valido, o 0 si no lo es.
	 *
	 * Solo se aceptan enteros PHP y cadenas de cifras decimales sin signo ni espacios ni ceros a la izquierda, de 1 a 4294967295
	 * (columna INT UNSIGNED de #__assets). Decimales, notacion cientifica, hexadecimal, booleanos, matrices y objetos dan 0.
	 *
	 * @param   mixed  $value
	 *
	 * @return  int
	 * @since   0.8.2
	 */
	public static function parseAssetId($value): int
	{
		if (is_int($value))
		{
			return ($value >= 1 && $value <= 4294967295) ? $value : 0;
		}

		if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1)
		{
			$int = (int) $value;

			return ($int <= 4294967295) ? $int : 0;
		}

		return 0;
	}

	/**
	 * Valida la respuesta de un plugin a onAkeebaEngageGetAssetMeta.
	 *
	 * Devuelve NULL si la respuesta no se puede aceptar (tipo invalido): el activo se trata como desconocido. Si no, devuelve la
	 * misma estructura con TODOS los valores del tipo y rango esperados. Un valor presente pero invalido nunca abre nada: los niveles
	 * de acceso invalidos pasan a NO_LEVEL, `published` a false, `category_published` a 0, `comments_enabled` a 0.
	 *
	 * @param   array  $raw  Lo que devolvio el plugin
	 *
	 * @return  array|null
	 * @since   0.8.2
	 */
	public static function sanitiseMeta(array $raw): ?array
	{
		// El tipo decide la clave de idioma del titulo: letras minusculas, cifras y guion bajo; 'unknown' no vale
		$type = $raw['type'] ?? null;

		if (!is_string($type) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $type) !== 1 || $type === 'unknown')
		{
			return null;
		}

		$out = ['type' => $type];

		foreach (['title', 'category', 'author_name'] as $k)
		{
			if (array_key_exists($k, $raw))
			{
				$out[$k] = self::cleanText($raw[$k]);
			}
		}

		if (array_key_exists('author_email', $raw))
		{
			$out['author_email'] = self::cleanEmail($raw['author_email']);
		}

		foreach (['url', 'public_url'] as $k)
		{
			if (array_key_exists($k, $raw))
			{
				$out[$k] = self::cleanUrl($raw[$k]);
			}
		}

		if (array_key_exists('published', $raw))
		{
			$p = $raw['published'];
			$out['published'] = ($p === true) || ($p === 1) || ($p === '1');
		}

		if (array_key_exists('published_on', $raw))
		{
			$out['published_on'] = self::cleanDate($raw['published_on']);
		}

		if (array_key_exists('access', $raw))
		{
			$out['access'] = self::cleanLevel($raw['access']);
		}

		if (array_key_exists('parent_access', $raw))
		{
			// NULL = el activo no tiene padre con nivel propio; cualquier otra cosa tiene que ser un nivel valido
			$out['parent_access'] = ($raw['parent_access'] === null) ? null : self::cleanLevel($raw['parent_access']);
		}

		if (array_key_exists('category_published', $raw))
		{
			$c = $raw['category_published'];

			if ($c === null)
			{
				$out['category_published'] = null;
			}
			elseif (is_bool($c))
			{
				$out['category_published'] = (int) $c;
			}
			elseif ((is_int($c) || (is_string($c) && preg_match('/^-?[0-9]{1,3}$/D', $c) === 1)) && (int) $c >= -2 && (int) $c <= 2)
			{
				$out['category_published'] = (int) $c;
			}
			else
			{
				$out['category_published'] = 0;
			}
		}

		if (array_key_exists('parameters', $raw))
		{
			$out['parameters'] = self::cleanParameters($raw['parameters']);
		}

		return $out;
	}

	/**
	 * ¿Puede este usuario ver el activo? Es lo que exige el camino generico de los comentarios (0.8.2).
	 *
	 * Fail-closed: el activo tiene que ser reconocido por algun plugin (tipo distinto de 'unknown'), estar publicado, su categoria
	 * publicada (o sin categoria) y el usuario tener el nivel de acceso del activo y el del padre. Un nivel de acceso ausente, cero o
	 * invalido NO equivale a «sin restriccion»: cierra el contenido.
	 *
	 * @param   array      $meta  El resultado de getAssetAccessMeta()
	 * @param   User|null  $user  El usuario; por omision el de la sesion
	 *
	 * @return  bool
	 * @since   0.8.2
	 */
	public static function isAssetViewable(array $meta, ?User $user = null): bool
	{
		$type = $meta['type'] ?? 'unknown';

		if (!is_string($type) || $type === 'unknown' || $type === '')
		{
			return false;
		}

		if (($meta['published'] ?? false) !== true)
		{
			return false;
		}

		if (!self::isCategoryPublished($meta))
		{
			return false;
		}

		$user   = $user ?? \Akeeba\Component\Engage\Administrator\Helper\UserFetcher::getUser();
		$levels = array_map('intval', (array) $user->getAuthorisedViewLevels());

		$access = $meta['access'] ?? null;

		if (!is_int($access) || $access <= 0 || !in_array($access, $levels, true))
		{
			return false;
		}

		$parent = $meta['parent_access'] ?? null;

		if ($parent !== null && (!is_int($parent) || $parent <= 0 || !in_array($parent, $levels, true)))
		{
			return false;
		}

		return true;
	}

	/** Texto sin caracteres de control y de un maximo de 255 caracteres. Se ESCAPA siempre al mostrarlo; aqui solo se limpia. */
	private static function cleanText($value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_string($value) && !is_int($value) && !is_float($value))
		{
			return '';
		}

		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value) ?? '';

		if (!mb_check_encoding($text, 'UTF-8'))
		{
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}

		return mb_substr($text, 0, 255, 'UTF-8');
	}

	/** Correo valido o NULL (vacio se conserva vacio). Sin saltos de linea: no puede inyectar cabeceras. */
	private static function cleanEmail($value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_string($value))
		{
			return null;
		}

		if ($value === '')
		{
			return '';
		}

		if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false)
		{
			return null;
		}

		return $value;
	}

	/**
	 * URL relativa o del propio sitio (http/https con el anfitrion de la raiz del sitio). Todo lo demas (javascript:, data:, //otro.host,
	 * usuario@, caracteres de control, comillas, ángulos, barras invertidas) da NULL. El vacio se conserva vacio.
	 *
	 * @param   mixed  $value
	 *
	 * @return  string|null
	 * @since   0.8.2
	 */
	public static function cleanUrl($value): ?string
	{
		if ($value === null || !is_string($value))
		{
			return null;
		}

		if ($value === '')
		{
			return '';
		}

		if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $value) === 1)
		{
			return null;
		}

		if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $value) !== 1)
		{
			// Relativa: no puede empezar por // (protocolo relativo) ni llevar ':' antes de la primera '/', '?' o '#'
			if (str_starts_with($value, '//'))
			{
				return null;
			}

			$cut = strcspn($value, '/?#');

			return (strpos(substr($value, 0, $cut), ':') === false) ? $value : null;
		}

		$parts = parse_url($value);

		if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']))
		{
			return null;
		}

		if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true))
		{
			return null;
		}

		$root = null;

		try
		{
			$root = parse_url((string) Uri::root());
		}
		catch (Throwable $e)
		{
			$root = null;
		}

		if (!is_array($root) || empty($root['host']) || strcasecmp($root['host'], $parts['host']) !== 0)
		{
			return null;
		}

		if (isset($parts['port']) && (int) $parts['port'] !== (int) ($root['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80)))
		{
			return null;
		}

		return $value;
	}

	/** Fecha de publicacion: una copia como Date; si no es una fecha, la epoca (con comments_close_after > 0 eso cierra). */
	private static function cleanDate($value): Date
	{
		try
		{
			if ($value instanceof \DateTimeInterface)
			{
				return new Date($value->format('Y-m-d H:i:s'), $value->getTimezone());
			}

			if (is_string($value) && $value !== '' && strlen($value) <= 40)
			{
				return new Date($value);
			}
		}
		catch (Throwable $e)
		{
		}

		return new Date('@0');
	}

	/** Nivel de acceso: entero positivo; cualquier otra cosa es NO_LEVEL (nadie lo tiene). */
	private static function cleanLevel($value): int
	{
		if (is_int($value))
		{
			return ($value >= 1 && $value <= 4294967295) ? $value : self::NO_LEVEL;
		}

		if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 && (int) $value <= 4294967295)
		{
			return (int) $value;
		}

		return self::NO_LEVEL;
	}

	/**
	 * Parametros del contenido: un Registry nuevo con solo las claves conocidas y valores escalares.
	 *
	 * comments_enabled → 0 o 1 (lo demas, 0); comments_close_after → entero 0..36500 (negativo = 0; invalido cierra el contenido);
	 * default_publish → -1, 0 o 1 (invalido = 0: en moderacion).
	 */
	private static function cleanParameters($value): Registry
	{
		$out = new Registry();

		if (!($value instanceof Registry))
		{
			return $out;
		}

		foreach (self::PARAM_KEYS as $k)
		{
			$v = $value->get($k, null);

			if ($v === null || is_array($v) || is_object($v) || (is_string($v) && strlen($v) > 64))
			{
				continue;
			}

			$out->set($k, is_string($v) ? preg_replace('/[\x00-\x1F\x7F]/', '', $v) : $v);
		}

		if ($out->exists('comments_enabled'))
		{
			$v = $out->get('comments_enabled');
			$out->set('comments_enabled', (($v === true) || ($v === 1) || ($v === '1')) ? 1 : 0);
		}

		if ($out->exists('comments_close_after'))
		{
			$v = $out->get('comments_close_after');

			if (is_int($v) || (is_string($v) && preg_match('/^-?[0-9]{1,6}$/D', $v) === 1))
			{
				$out->set('comments_close_after', max(0, min(36500, (int) $v)));
			}
			else
			{
				$out->set('comments_close_after', 0);
				$out->set('comments_enabled', 0);
			}
		}

		if ($out->exists('default_publish'))
		{
			$v = $out->get('default_publish');
			$out->set('default_publish', in_array($v, [-1, 0, 1, '-1', '0', '1'], true) ? (int) $v : 0);
		}

		return $out;
	}

	/**
	 * Returns the total number of comments for a specific asset ID
	 *
	 * @param   int  $asset_id  The asset ID to check for the total number of comments
	 *
	 * @return  int
	 */
	public static function getNumCommentsForAsset(int $asset_id): int
	{
		if (isset(self::$numCommentsPerAsset[$asset_id]))
		{
			return self::$numCommentsPerAsset[$asset_id];
		}

		/** @var CommentsModel $model */
		$model = self::getMVCFactory()->createModel('Comments', 'Administrator', ['ignore_request' => true]);

		$model->setState('filter.asset_id', $asset_id);

		self::$numCommentsPerAsset[$asset_id] = $model->getTotal() ?: 0;

		return self::$numCommentsPerAsset[$asset_id];
	}

	/**
	 * Returns the comments IDs for an asset, in the same order as they are paginated in the frontend
	 *
	 * @param   int   $asset_id   Asset ID
	 * @param   bool  $asManager  True to take into account unpublished comments
	 *
	 * @return  int[]
	 * @since   1.0.0
	 */
	public static function getPaginatedCommentIDsForAsset(int $asset_id, bool $asManager = false): array
	{
		if (isset(self::$commentIDsPerAsset[$asset_id]))
		{
			return self::$commentIDsPerAsset[$asset_id];
		}

		/** @var CommentsModel $model */
		$model = self::getMVCFactory()->createModel('Comments', 'Administrator', ['ignore_request' => true]);
		$model->setState('filter.asset_id', $asset_id);

		if (!$asManager)
		{
			$model->setState('filter.enabled', 1);
		}

		self::$commentIDsPerAsset[$asset_id] = array_keys($model->commentIDTreeSliceWithDepth(0, null));

		return self::$commentIDsPerAsset[$asset_id];
	}

	/**
	 * Borra TODO lo que Engage guarda de un contenido: sus comentarios, las reacciones de esos comentarios y las bajas de aviso por
	 * correo (0.8.2). Es para que el componente DUENO del contenido lo llame (por ejemplo desde su evento onContentAfterDelete);
	 * Engage no puede enterarse solo de que un contenido de otro componente se ha borrado. NO borra la fila de #__assets: eso es cosa
	 * de quien la creo. No hay ninguna ruta HTTP, tarea ni evento que llegue hasta aqui: solo se ejecuta si otro codigo PHP la llama.
	 * SQL con parametros ligados y una transaccion: o se borra todo o no se borra nada.
	 *
	 * @param   int  $assetId  El asset_id (entero positivo; si no lo es no se hace nada)
	 *
	 * @return  int  Numero de comentarios borrados, o -1 si el asset_id no es valido o hubo un error (no se cambio nada)
	 * @since   0.8.2
	 */
	public static function deleteAssetData(int $assetId): int
	{
		$assetId = self::parseAssetId($assetId);

		if ($assetId <= 0)
		{
			return -1;
		}

		/**
		 * 0.8.3: the content of com_content (articles, categories) is cleaned by Engage's own content plugin (onContentAfterDelete); another
		 * component's code must not be able to wipe the comments of an article by passing its asset ID (by mistake, or on purpose). If the
		 * asset still exists and belongs to com_content, nothing is changed.
		 */
		$assetName = self::assetName($assetId);

		if ($assetName !== null && str_starts_with($assetName, 'com_content.'))
		{
			return -1;
		}

		/** @var DatabaseDriver $db */
		$db       = Factory::getContainer()->get(DatabaseInterface::class);
		$newQuery = static function () use ($db) {
			return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
		};

		try
		{
			$db->transactionStart();

			$sub = $newQuery()
				->select($db->qn('c.id'))
				->from($db->qn('#__engage_comments', 'c'))
				->where($db->qn('c.asset_id') . ' = :asset');
			$q   = $newQuery()
				->delete($db->qn('#__engage_reactions'))
				->where($db->qn('comment_id') . ' IN (' . $sub . ')')
				->bind(':asset', $assetId, ParameterType::INTEGER);
			$db->setQuery($q)->execute();

			$q = $newQuery()
				->delete($db->qn('#__engage_comments'))
				->where($db->qn('asset_id') . ' = :asset')
				->bind(':asset', $assetId, ParameterType::INTEGER);
			$db->setQuery($q)->execute();
			$deleted = (int) $db->getAffectedRows();

			$q = $newQuery()
				->delete($db->qn('#__engage_unsubscribe'))
				->where($db->qn('asset_id') . ' = :asset')
				->bind(':asset', $assetId, ParameterType::INTEGER);
			$db->setQuery($q)->execute();

			$db->transactionCommit();
		}
		catch (Throwable $e)
		{
			try
			{
				$db->transactionRollback();
			}
			catch (Throwable $e2)
			{
			}

			return -1;
		}

		return $deleted;
	}

	/**
	 * Pseudonymises and removes the content of comments filed by a user.
	 *
	 * All comments filed either directly with their user ID or under their email address are affected.
	 *
	 * The following actions are taken:
	 *
	 * * The comment text is replaced with COM_ENGAGE_COMMENTS_LBL_DELETEDCOMMENT
	 * * The name (for guest comments filed under the user's email) is replaced with COM_ENGAGE_COMMENTS_LBL_DELETEDUSER
	 * * The email (for guest comments filed under the user's email) is replaced with deleted.<USER_ID>@<SITE_HOSTNAME>
	 * * 0.7.1: the IP address is emptied and the user agent too, and, when converting to guest, created_by becomes 0
	 * * All #__engage_unsubscribe records with that email address are removed
	 *
	 * This is similar to how other sites, e.g. Slashdot, treat user account deletion. If we were to completely delete a
	 * user's comments we would also be deleting the entire conversation below them since we can't have orphan comments.
	 * Deleting the comment's contents is less disruptive. Of course it doesn't do you much good if you were directly
	 * quoted by a different user but that's something you should have thought before saying something regrettable on
	 * the Internet...
	 *
	 * @param   User|null  $user            The user object whose comments will be nuked
	 *
	 * @param   bool       $convertToGuest  True to convert attributed comments to pseudonymized guest comments
	 *
	 * @return  int[]  The comment IDs affected
	 */
	public static function pseudonymiseUserComments(?User $user, bool $convertToGuest = false): array
	{
		if (empty($user))
		{
			return [];
		}

		if ($user->guest || ((int) $user->id <= 0))
		{
			return [];
		}

		/** @var DatabaseDriver $db */
		$db     = Factory::getContainer()->get(DatabaseInterface::class);
		$userId = (int) $user->id;
		$email  = (string) $user->email;
		$body   = Text::_('COM_ENGAGE_COMMENTS_LBL_DELETEDCOMMENT');
		$name   = Text::sprintf('COM_ENGAGE_COMMENTS_LBL_DELETEDUSER', $userId);

		// Host for the pseudonymised email address (0.7.1: also when there is no HTTP host, e.g. the Joomla console)
		$host = '';

		try
		{
			$host = (string) Uri::getInstance()->getHost();
		}
		catch (Throwable $e)
		{
			// Uri::getInstance() cannot parse the request URI under the Joomla console (cli/joomla.php with a fixed live_site)
		}

		if ($host === '')
		{
			try
			{
				$host = (string) parse_url((string) Uri::root(), PHP_URL_HOST);
			}
			catch (Throwable $e)
			{
				$host = '';
			}
		}

		$host = ($host !== '') ? $host : 'localhost';

		$newQuery = static function () use ($db) {
			return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
		};

		/**
		 * 0.7.1: everything happens in ONE transaction, so a failure half way leaves the comments as they were (the caller can
		 * retry or report) instead of half pseudonymised. The IP address and the browser (user agent) are personal data too:
		 * they are emptied together with the text (ip is nullable; user_agent is NOT NULL, so it becomes an empty string).
		 */
		$db->transactionStart();

		try
		{
			// Comments directly attributed to the user ID
			$q   = $newQuery()
				->select($db->qn('id'))
				->from($db->qn('#__engage_comments'))
				->where($db->qn('created_by') . ' = :userid')
				->bind(':userid', $userId, ParameterType::INTEGER);
			$cid = $db->setQuery($q)->loadColumn() ?: [];

			$q = $newQuery()
				->update($db->qn('#__engage_comments'))
				->set([
					$db->qn('body') . ' = :body',
					$db->qn('ip') . ' = NULL',
					$db->qn('user_agent') . " = ''",
				])
				->where($db->qn('created_by') . ' = :userid')
				->bind(':body', $body, ParameterType::STRING)
				->bind(':userid', $userId, ParameterType::INTEGER);
			$db->setQuery($q)->execute();

			// Comments attributed to the user's email address (guest comments filed with it)
			if ($email !== '')
			{
				$q   = $newQuery()
					->select($db->qn('id'))
					->from($db->qn('#__engage_comments'))
					->where($db->qn('email') . ' = :email')
					->bind(':email', $email, ParameterType::STRING);
				$cid = array_merge($cid, $db->setQuery($q)->loadColumn() ?: []);

				$deletedEmail = sprintf('deleted.%u@%s', $userId, $host);

				$q = $newQuery()
					->update($db->qn('#__engage_comments'))
					->set([
						$db->qn('body') . ' = :body',
						$db->qn('name') . ' = :name',
						$db->qn('email') . ' = :newemail',
						$db->qn('ip') . ' = NULL',
						$db->qn('user_agent') . " = ''",
					])
					->where($db->qn('email') . ' = :email')
					->bind(':body', $body, ParameterType::STRING)
					->bind(':name', $name, ParameterType::STRING)
					->bind(':newemail', $deletedEmail, ParameterType::STRING)
					->bind(':email', $email, ParameterType::STRING);
				$db->setQuery($q)->execute();
			}

			$cid = array_values(array_unique(array_map('intval', array_filter($cid, function ($id) {
				return is_numeric($id) && ($id > 0);
			}))));

			/**
			 * If converting the comments to guest comments (when the user record itself is deleted) we need to do some more
			 * post processing for these comments: name and email as above, and created_by = 0 (0.7.1), so that no user ID is
			 * left that a future account could inherit (some database servers reuse the highest ID after a restart).
			 */
			if ($convertToGuest && !empty($cid))
			{
				$deletedEmail = sprintf('deleted.%u@%s', $userId, $host);

				foreach (array_chunk($cid, 500) as $chunk)
				{
					$q = $newQuery()
						->update($db->qn('#__engage_comments'))
						->set([
							$db->qn('body') . ' = :body',
							$db->qn('name') . ' = :name',
							$db->qn('email') . ' = :newemail',
							$db->qn('created_by') . ' = 0',
							$db->qn('ip') . ' = NULL',
							$db->qn('user_agent') . " = ''",
						])
						->whereIn($db->qn('id'), $chunk, ParameterType::INTEGER)
						->bind(':body', $body, ParameterType::STRING)
						->bind(':name', $name, ParameterType::STRING)
						->bind(':newemail', $deletedEmail, ParameterType::STRING);
					$db->setQuery($q)->execute();
				}
			}

			// Remove #__engage_unsubscribe records
			if ($email !== '')
			{
				$q = $newQuery()
					->delete($db->qn('#__engage_unsubscribe'))
					->where($db->qn('email') . ' = :email')
					->bind(':email', $email, ParameterType::STRING);
				$db->setQuery($q)->execute();
			}

			$db->transactionCommit();
		}
		catch (Throwable $e)
		{
			$db->transactionRollback();

			throw $e;
		}

		return $cid;
	}

	/**
	 * Get the MVC factory for the component
	 *
	 * @return  MVCFactoryInterface|null
	 * @throws  Exception
	 * @since   3.0.0
	 */
	private static function getMVCFactory(): ?MVCFactoryInterface
	{
		if (!empty(self::$mvcFactory))
		{
			return self::$mvcFactory;
		}

		$component = Factory::getApplication()->bootComponent('com_engage');

		if (!$component instanceof MVCFactoryServiceInterface)
		{
			self::$mvcFactory = null;

			return null;
		}

		self::$mvcFactory = $component->getMVCFactory();

		return self::$mvcFactory;
	}
}