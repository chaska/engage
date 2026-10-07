<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.8.0): logica PURA (sin Joomla ni base de datos) de las herramientas de la lista de comentarios del sitio:
 * selector de orden, filtro «solo mis favoritos», copiar enlace e insignias. Todo lo que llega de la peticion pasa por listas
 * cerradas o comparaciones estrictas; las URL se tratan como cadenas y se validan antes de entrar en un atributo.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

final class CommentTools
{
	/** Parametros de la URL que pone este fork (y la paginacion propia): no forman parte del enlace «limpio» de un comentario. */
	public const PARAM_SORT = 'akengage_sort';
	public const PARAM_FAV  = 'akengage_fav';

	/** Parametros que se quitan de un enlace a un comentario o a la pagina canonica. */
	private const VOLATILE = ['akengage_sort', 'akengage_fav', 'akengage_limitstart', 'akengage_limit', 'akengage_cid'];

	/**
	 * Opciones del componente, normalizadas (cualquier valor ajeno vuelve al valor por defecto).
	 *
	 * @param   callable|array  $get  array clave => valor, o funcion (clave, defecto) => valor
	 *
	 * @return array{sort_selector:bool,default_sort:string,copy_link:bool,show_badges:bool}
	 */
	public static function options($get): array
	{
		$read = static function (string $k, $def) use ($get) {
			return is_callable($get) ? $get($k, $def) : ($get[$k] ?? $def);
		};
		$bool = static function ($v, bool $def): bool {
			if (is_bool($v)) { return $v; }
			if (is_int($v) || (is_string($v) && preg_match('/^[01]$/D', $v))) { return (int) $v === 1; }

			return $def;
		};

		return [
			'sort_selector' => $bool($read('sort_selector', 1), true),
			'default_sort'  => ListOrdering::defaultSort($read('default_sort', 'auto')),
			'copy_link'     => $bool($read('copy_link', 1), true),
			'show_badges'   => $bool($read('show_badges', 1), true),
		];
	}

	/**
	 * Decide que se aplica a la lista a partir de las opciones y de lo que pide el visitante.
	 *
	 * @param   array   $opts       options()
	 * @param   array   $reactions  Reactions::options(): solo se mira enabled y favorites
	 * @param   mixed   $sortParam  Valor crudo de `akengage_sort` (cualquier tipo)
	 * @param   mixed   $favParam   Valor crudo de `akengage_fav` (cualquier tipo)
	 * @param   string  $legacyDir  'ASC' o 'DESC': la opcion de siempre (comments_ordering)
	 * @param   bool    $loggedIn   El visitante tiene sesion
	 *
	 * @return array{selector:bool,modes:string[],sort:?string,explicit:?string,selected:string,favorites:bool,favButton:bool}
	 *   selector:  se pinta el selector de orden y el boton de favoritos (la barra);
	 *   modes:     ordenaciones que se ofrecen (newest, oldest y, con reacciones, top);
	 *   sort:      modo que se aplica al modelo (null = el orden de siempre);
	 *   explicit:  modo pedido en la URL y valido (null si no se pidio o no vale): es el unico que viaja en los enlaces;
	 *   selected:  cual de los modos se marca como actual;
	 *   favorites: el filtro «solo mis favoritos» esta activo;
	 *   favButton: se pinta el conmutador de favoritos (oculto hasta que el JavaScript confirme la sesion).
	 */
	public static function resolve(array $opts, array $reactions, $sortParam, $favParam, string $legacyDir, bool $loggedIn): array
	{
		$reactionsOn = !empty($reactions['enabled']);
		$favOn       = $reactionsOn && !empty($reactions['favorites']);
		$selector    = !empty($opts['sort_selector']);
		$modes       = ['newest', 'oldest'];

		if ($reactionsOn)
		{
			$modes[] = 'top';
		}

		$explicit = null;

		if ($selector)
		{
			$m        = ListOrdering::sortMode($sortParam);
			$explicit = ($m !== null && in_array($m, $modes, true)) ? $m : null;
		}

		$default = ListOrdering::defaultSort($opts['default_sort'] ?? 'auto');

		if ($default === 'auto' || !in_array($default, $modes, true))
		{
			$default = null;
		}

		$sort      = $explicit ?? $default;
		$legacy    = (strtoupper($legacyDir) === 'DESC') ? 'newest' : 'oldest';
		$favorites = $selector && $favOn && $loggedIn && $favParam === '1';

		return [
			'selector'  => $selector,
			'modes'     => $modes,
			'sort'      => $sort,
			'explicit'  => $explicit,
			'selected'  => $sort ?? $legacy,
			'favorites' => $favorites,
			'favButton' => $selector && $favOn,
		];
	}

	/**
	 * Quita y anade parametros de la parte de consulta de una URL SIN reinterpretarla (sin parse_str, que cambia los puntos y
	 * espacios de los nombres): las demas variables se conservan tal cual. Los valores nuevos se codifican.
	 *
	 * @param   string               $url     URL absoluta o relativa, con o sin fragmento
	 * @param   string[]             $remove  Nombres de parametro a quitar
	 * @param   array<string,string> $add     Nombre => valor a poner (al final)
	 * @param   string|null          $fragment  Fragmento nuevo (sin #); null = conservar; '' = quitar
	 */
	public static function withQuery(string $url, array $remove, array $add = [], ?string $fragment = null): string
	{
		$frag = '';
		$pos  = strpos($url, '#');

		if ($pos !== false)
		{
			$frag = substr($url, $pos + 1);
			$url  = substr($url, 0, $pos);
		}

		$query = '';
		$pos   = strpos($url, '?');

		if ($pos !== false)
		{
			$query = substr($url, $pos + 1);
			$url   = substr($url, 0, $pos);
		}

		$keep = [];

		foreach (($query === '') ? [] : explode('&', $query) as $part)
		{
			if ($part === '')
			{
				continue;
			}

			$name = rawurldecode(str_replace('+', ' ', explode('=', $part, 2)[0]));

			if (in_array($name, $remove, true) || in_array(preg_replace('/\[.*$/s', '', $name), $remove, true))
			{
				continue;
			}

			$keep[] = $part;
		}

		foreach ($add as $k => $v)
		{
			$keep[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
		}

		if ($fragment !== null)
		{
			$frag = $fragment;
		}

		return $url . ($keep ? '?' . implode('&', $keep) : '') . ($frag !== '' ? '#' . $frag : '');
	}

	/**
	 * Hace segura una URL RELATIVA (ruta y consulta) tomada de la peticion para ponerla en un enlace: la ruta debe empezar por UNA sola barra
	 * (una ruta que empieza por `//` o `/\` es una URL relativa al protocolo y llevaria a otro sitio), sin barras invertidas, sin caracteres de control
	 * ni espacios. Si la ruta no es segura se descarta y queda solo la consulta (`?a=b`), que el navegador resuelve contra la pagina actual.
	 */
	public static function safeRelative(string $url): string
	{
		$pos   = strpos($url, '?');
		$path  = ($pos === false) ? $url : substr($url, 0, $pos);
		$query = ($pos === false) ? '' : substr($url, $pos);

		if (!preg_match('~^/(?![/\\\\])[\x21-\x7E]*$~D', $path) || strpbrk($path, "\\\"'<>`") !== false)
		{
			$path = '';
		}

		if ($query !== '' && (!preg_match('~^\?[\x21-\x7E]*$~D', $query) || strpbrk($query, "\\\"'<>`") !== false))
		{
			$query = '';
		}

		return $path . $query;
	}

	/** La URL sin ninguno de los parametros de este fork (orden, favoritos, paginacion propia, akengage_cid): la pagina «canonica». */
	public static function cleanUrl(string $url): string
	{
		return self::withQuery($url, self::VOLATILE, [], '');
	}

	/**
	 * Enlace permanente a un comentario: la URL de la pagina (sin orden, favoritos ni paginacion) mas `akengage_cid` y el ancla
	 * `akengage-comment-<id>`. Devuelve una cadena vacia si el resultado no es una URL web segura: esquema http o https, anfitrion
	 * con solo letras, cifras, guiones y puntos (o IPv6 entre corchetes), puerto numerico, sin usuario ni contrasena, sin caracteres
	 * de control, espacios, comillas ni angulos. Nunca devuelve algo que el navegador pueda interpretar como codigo.
	 *
	 * @param   string  $pageUrl    URL absoluta de la pagina actual
	 * @param   int     $commentId
	 */
	public static function permalink(string $pageUrl, int $commentId): string
	{
		if ($commentId <= 0 || strlen($pageUrl) > 2000 || !self::isSafeAbsoluteUrl($pageUrl))
		{
			return '';
		}

		$url = self::withQuery($pageUrl, self::VOLATILE, ['akengage_cid' => (string) $commentId], 'akengage-comment-' . $commentId);

		return self::isSafeAbsoluteUrl($url) ? $url : '';
	}

	/** ¿Es una URL absoluta http(s) sin nada que no se pueda poner tal cual entre comillas en un atributo? */
	public static function isSafeAbsoluteUrl(string $url): bool
	{
		// Solo ASCII imprimible sin espacio, comillas, angulos, acento grave ni barra invertida
		if ($url === '' || !preg_match('/^[\x21\x23-\x26\x28-\x3B\x3D\x3F-\x5B\x5D-\x5F\x61-\x7E]+$/D', $url))
		{
			return false;
		}

		if (!preg_match('~^(https?)://([^/?#@]+)([/?#].*)?$~Di', $url, $m))
		{
			return false;
		}

		$authority = $m[2];

		if (!preg_match('/^(\[[0-9A-Fa-f:.]{2,45}\]|[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?)(?::([0-9]{1,5}))?$/D', $authority, $a))
		{
			return false;
		}

		if (isset($a[2]) && $a[2] !== '' && ((int) $a[2] < 1 || (int) $a[2] > 65535))
		{
			return false;
		}

		return true;
	}

	/**
	 * Insignias de un comentario. Devuelve la lista de claves de insignia, en orden fijo: 'author' (quien comenta es el autor del
	 * contenido) y 'moderator' (puede moderar los comentarios). Solo claves, nunca nombres de grupo, permisos ni identificadores.
	 *
	 * @param   int       $commentUserId   created_by del comentario (0 = invitado)
	 * @param   int       $authorUserId    created_by del contenido (0 = desconocido)
	 * @param   callable  $isModerator     fn(int $userId): bool, con cache por peticion
	 *
	 * @return string[]
	 */
	public static function badges(int $commentUserId, int $authorUserId, callable $isModerator): array
	{
		$out = [];

		if ($commentUserId <= 0)
		{
			return $out;
		}

		if ($authorUserId > 0 && $commentUserId === $authorUserId)
		{
			$out[] = 'author';
		}

		if ($isModerator($commentUserId))
		{
			$out[] = 'moderator';
		}

		return $out;
	}
}
