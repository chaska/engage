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
	 * @return array{sort_selector:bool,default_sort:string,copy_link:bool,show_badges:bool,crawl_reduce:bool}
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
			'crawl_reduce'  => $bool($read('crawl_reduce', 1), true),
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

	/**
	 * 0.8.1: lista blanca de parametros de la consulta que pueden acabar en un enlace de la pagina. Todo lo demas (`x=...`, `p=...`,
	 * lo que ponga el primer visitante) se descarta: la pagina que Joomla guarda en la cache la comparten todos los visitantes, y la
	 * clave de esa cache solo mira unos pocos parametros. El valor de cada uno ademas debe tener la forma esperada.
	 * Nombre => expresion que debe cumplir el valor YA decodificado.
	 */
	private const ALLOWED_PARAMS = [
		'option'              => '/^com_[a-z0-9_]{1,40}$/Di',
		'view'                => '/^[a-z0-9_.\-]{1,40}$/Di',
		'layout'              => '/^[a-z0-9_.:\-]{1,40}$/Di',
		'tmpl'                => '/^[a-z0-9_\-]{1,20}$/Di',
		'id'                  => '/^[0-9]{1,10}(:[a-z0-9_\-]{0,100})?$/Di',
		'catid'               => '/^[0-9]{1,10}(:[a-z0-9_\-]{0,100})?$/Di',
		'Itemid'              => '/^[0-9]{1,10}$/D',
		'lang'                => '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/Di',
		'print'               => '/^[01]$/D',
		'showall'             => '/^[01]$/D',
		'start'               => '/^[0-9]{1,7}$/D',
		'limitstart'          => '/^[0-9]{1,7}$/D',
		'limit'               => '/^[0-9]{1,3}$/D',
		'akengage_sort'       => '/^(newest|oldest|top)$/D',
		'akengage_fav'        => '/^1$/D',
		'akengage_limitstart' => '/^[0-9]{1,7}$/D',
		'akengage_limit'      => '/^[0-9]{1,3}$/D',
		'akengage_cid'        => '/^[0-9]{1,10}$/D',
	];

	/**
	 * Parte de consulta (con o sin `?`) reducida a los parametros de la lista blanca con valor valido, recodificados. Un nombre
	 * repetido solo cuenta la primera vez; los de matriz (`a[]=`) y cualquier otro se descartan.
	 */
	public static function sanitizeQuery(string $query): string
	{
		$query = ltrim($query, '?');
		$keep  = [];

		foreach (($query === '') ? [] : explode('&', $query) as $part)
		{
			if ($part === '')
			{
				continue;
			}

			$kv   = explode('=', $part, 2);
			$name = rawurldecode(str_replace('+', ' ', $kv[0]));

			if (!isset(self::ALLOWED_PARAMS[$name]) || isset($keep[$name]))
			{
				continue;
			}

			$value = rawurldecode(str_replace('+', ' ', $kv[1] ?? ''));

			if (preg_match(self::ALLOWED_PARAMS[$name], $value) === 1)
			{
				$keep[$name] = rawurlencode($name) . '=' . rawurlencode($value);
			}
		}

		return implode('&', $keep);
	}

	/**
	 * URL RELATIVA segura para poner en HTML a partir de la ruta y la consulta de la peticion (`/ruta?a=b`): ruta con safeRelative()
	 * y consulta con la lista blanca de sanitizeQuery(). Nunca lleva esquema ni anfitrion (nada de la cabecera Host acaba en HTML
	 * que se pueda cachear) ni nada de la consulta que no se haya validado.
	 */
	public static function relativeUrl(string $pathAndQuery): string
	{
		$pos   = strpos($pathAndQuery, '#');
		$clean = ($pos === false) ? $pathAndQuery : substr($pathAndQuery, 0, $pos);
		$pos   = strpos($clean, '?');
		$path  = self::safeRelative(($pos === false) ? $clean : substr($clean, 0, $pos));
		$query = ($pos === false) ? '' : self::sanitizeQuery(substr($clean, $pos + 1));

		return $path . ($query !== '' ? '?' . $query : '');
	}

	/** La URL (relativa o absoluta) sin ninguno de los parametros de este fork (orden, favoritos, paginacion propia, akengage_cid) y solo con la lista blanca. */
	public static function cleanUrl(string $url): string
	{
		$url = self::withQuery($url, self::VOLATILE, [], '');

		if (preg_match('~^https?://[^/?#]*~i', $url, $m))
		{
			return $m[0] . self::relativeUrl(substr($url, strlen($m[0])));
		}

		return self::relativeUrl($url);
	}

	/**
	 * Origen (`https://host[:puerto]`) en el que se puede confiar para un enlace absoluto: SOLO el `live_site` que el administrador puso
	 * en la configuracion de Joomla. Nunca se deriva de la cabecera Host. Cadena vacia si no hay uno valido.
	 */
	public static function trustedOrigin(string $liveSite): string
	{
		$liveSite = trim($liveSite);

		if ($liveSite === '' || !self::isSafeAbsoluteUrl($liveSite) || !preg_match('~^(https?://[^/?#]+)~i', $liveSite, $m))
		{
			return '';
		}

		return $m[1];
	}

	/**
	 * Enlace permanente RELATIVO a un comentario (ruta, consulta validada, `akengage_cid` y ancla `akengage-comment-<id>`) a partir de la
	 * ruta y la consulta de la peticion. Sin esquema ni anfitrion: es lo que se imprime en el HTML (que Joomla puede guardar en la
	 * cache para todos). tools.js lo vuelve absoluto con el origen real del visitante al copiarlo.
	 */
	public static function permalinkRelative(string $pathAndQuery, int $commentId): string
	{
		if ($commentId <= 0)
		{
			return '';
		}

		return self::withQuery(self::relativeUrl($pathAndQuery), self::VOLATILE, ['akengage_cid' => (string) $commentId], 'akengage-comment-' . $commentId);
	}

	/**
	 * Enlace permanente a un comentario: la URL de la pagina (sin orden, favoritos ni paginacion) mas `akengage_cid` y el ancla
	 * `akengage-comment-<id>`. Devuelve una cadena vacia si el resultado no es una URL web segura: esquema http o https, anfitrion
	 * con solo letras, cifras, guiones y puntos (o IPv6 entre corchetes), puerto numerico, sin usuario ni contrasena, sin caracteres
	 * de control, espacios, comillas ni angulos. Nunca devuelve algo que el navegador pueda interpretar como codigo.
	 *
	 * 0.8.1: la lista de comentarios ya no la usa (imprimia el anfitrion de la peticion en HTML cacheable): usa permalinkRelative().
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

	/**
	 * 0.9.4: ¿la peticion es una VARIANTE de la pagina (lleva algun parametro `akengage_*`: comentario, pagina, orden, favoritos, limite)?
	 * Solo mira los NOMBRES de la consulta; nada de ellos llega al HTML.
	 *
	 * @param   array  $keys  Nombres de los parametros de la consulta (valores de cualquier tipo: se ignoran los que no son texto)
	 */
	public static function isVariant(array $keys): bool
	{
		foreach ($keys as $k)
		{
			if (is_string($k) && strncasecmp($k, 'akengage_', 9) === 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * 0.9.4: valor de la meta `robots` (y de la cabecera X-Robots-Tag) de una variante. «Solo mis favoritos» es personal: `noindex, nofollow`
	 * siempre. Las demas variantes: `noindex, follow` (la pagina base sigue siendo alcanzable). Si el sitio ya puso `nofollow`, se respeta.
	 * Devuelve null cuando no hay nada que cambiar (la URL base queda tal cual: indexable).
	 *
	 * @param   string  $existing   Valor que ya tuviera la meta robots ('' = ninguno)
	 */
	public static function robotsValue(bool $variant, bool $favorites, bool $reduce, string $existing = ''): ?string
	{
		if ($favorites)
		{
			return 'noindex, nofollow';
		}

		if (!$variant || !$reduce)
		{
			return null;
		}

		return (stripos($existing, 'nofollow') !== false) ? 'noindex, nofollow' : 'noindex, follow';
	}

	/**
	 * 0.9.4: anade `nofollow` al `rel` de cada enlace `<a>` de un trozo de HTML que genera Joomla (la paginacion): conserva los valores que ya
	 * hubiera (noopener...), no duplica y es idempotente. No toca nada que no sea la etiqueta de apertura `<a ...>`.
	 */
	public static function addNofollow(string $html): string
	{
		return (string) preg_replace_callback('~<a\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~i', static function (array $m): string {
			$attrs = $m[1];

			if (preg_match('~\srel\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $attrs, $r, PREG_OFFSET_CAPTURE))
			{
				$value  = ($r[1][1] >= 0 ? $r[1][0] : ($r[2][1] >= 0 ? $r[2][0] : ($r[3][0] ?? '')));
				$tokens = array_values(array_filter(preg_split('/\s+/', strtolower(trim($value))) ?: [], 'strlen'));

				if (!in_array('nofollow', $tokens, true))
				{
					array_unshift($tokens, 'nofollow');
				}

				$attrs = substr($attrs, 0, $r[0][1]) . ' rel="' . htmlspecialchars(implode(' ', array_unique($tokens)), ENT_QUOTES, 'UTF-8') . '"' . substr($attrs, $r[0][1] + strlen($r[0][0]));

				return '<a' . $attrs . '>';
			}

			return '<a' . $attrs . ' rel="nofollow">';
		}, $html);
	}

	/**
	 * 0.9.4: cuantas URLs DISTINTAS rastreables ofrece la pagina de un elemento con $comments comentarios publicados: cada pagina de la lista en cada
	 * orden (sin orden + newest/oldest/top: 4 series), los enlaces de orden y de favoritos y, como maximo, un `?akengage_cid=ID` por respuesta ($replies:
	 * el enlace «en respuesta a» a un padre que esta en otra pagina). 0.9.5: la fecha de cada comentario ya es un ancla (`#akengage-comment-ID`) sobre la
	 * misma pagina y no cuenta. Cada URL carga el articulo entero y suma una visita en el contador de Joomla.
	 *
	 * @return array{cid:int,pages:int,series:int,pagination:int,tools:int,total:int}
	 */
	public static function crawlUrls(int $comments, int $pageSize, bool $reactions = true, bool $selector = true, int $replies = 0): array
	{
		$comments = max(0, $comments);
		$pageSize = max(1, $pageSize);
		$pages    = (int) ceil($comments / $pageSize);
		$series   = $selector ? ($reactions ? 4 : 3) : 1;
		$pagin    = ($pages > 1) ? $pages * $series : 0;
		// Enlaces de la barra: un orden por modo (pagina 1) y el conmutador de favoritos (con y sin orden)
		$tools    = ($selector && $comments >= 2) ? (($series - 1) + ($reactions ? $series : 0)) : 0;

		$cid = min($comments, max(0, $replies));

		return ['cid' => $cid, 'pages' => $pages, 'series' => $series, 'pagination' => $pagin, 'tools' => $tools, 'total' => $cid + $pagin + $tools];
	}

	/**
	 * 0.9.5: valor del `href` de la etiqueta canonical de la pagina de un hilo. Siempre la direccion LIMPIA de la peticion (la ruta amigable tal cual,
	 * sin ningun `akengage_*`, solo con la lista blanca de parametros). Con una base fiable ($origin: opcion, live_site o base aprendida de TrustedBase)
	 * es absoluta; SIN base fiable es RELATIVA a la raiz (`/ruta/limpia`), construida solo con la ruta ya validada: nunca sale de la cabecera Host.
	 * Cadena vacia si la ruta no es segura (no se imprime ninguna canonical).
	 *
	 * @param   string  $requestUri  Ruta y consulta de la peticion (REQUEST_URI)
	 * @param   string  $origin      `https://host[:puerto]` fiable o ''
	 */
	public static function canonicalHref(string $requestUri, string $origin = ''): string
	{
		if (strlen($requestUri) > 2000)
		{
			return '';
		}

		$clean = self::cleanUrl($requestUri);

		// Sin una ruta valida que empiece por una sola barra no hay canonical (la ruta se habria descartado y quedaria solo `?...`)
		if ($clean === '' || $clean[0] !== '/')
		{
			return '';
		}

		if ($origin === '')
		{
			return preg_match('/^[\x21\x23-\x26\x28-\x3B\x3D\x3F-\x5B\x5D-\x5F\x61-\x7E]+$/D', $clean) === 1 ? $clean : '';
		}

		$abs = $origin . $clean;

		return self::isSafeAbsoluteUrl($abs) ? $abs : '';
	}

	/**
	 * 0.9.5: URL (relativa, ruta amigable de la peticion) de una pagina de la lista: conserva el orden y los favoritos elegidos, sustituye la
	 * pagina y el comentario. La primera pagina es la direccion limpia (sin `akengage_limitstart`). Con el ancla de la seccion de comentarios.
	 */
	public static function pageUrl(string $requestUri, int $limitstart): string
	{
		$add = ($limitstart > 0) ? ['akengage_limitstart' => (string) $limitstart] : [];

		return self::withQuery(self::relativeUrl($requestUri), ['akengage_limitstart', 'akengage_limit', 'akengage_cid'], $add, 'akengage-comments-section');
	}

	/**
	 * 0.9.5: cambia el `href` de los enlaces de pagina de Joomla (`...akengage_limitstart=N...`, que Joomla construye sin la ruta amigable, p. ej.
	 * `/index.php?view=article&id=1:x&akengage_cid=&akengage_limitstart=20&catid=8`) por la URL de la pagina actual con esa pagina (`pageUrl`).
	 * Los enlaces sin numero de pagina no se tocan. $url recibe el numero de pagina y devuelve la URL ya segura (sin escapar).
	 */
	public static function rewritePageLinks(string $html, callable $url): string
	{
		return (string) preg_replace_callback('~(<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\shref=")([^"]*)(")~i', static function (array $m) use ($url): string {
			$href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

			if (preg_match('/[?&]akengage_limitstart=([0-9]{1,7})(?:&|$)/', $href, $n) !== 1)
			{
				return $m[0];
			}

			$new = (string) $url((int) $n[1]);

			return $new === '' ? $m[0] : $m[1] . htmlspecialchars($new, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[3];
		}, $html);
	}

	/**
	 * 0.9.10: the IP address of a comment to show to the moderators: only a valid public/private IPv4 or IPv6 address (filter_var); empty for «0», «0.0.0.0», «::»,
	 * empty and any other junk (comments imported from other systems often carry «0»). Never returns something that is not an IP address.
	 */
	public static function validIp($ip): string
	{
		$ip = is_scalar($ip) ? trim((string) $ip) : '';

		if ($ip === '' || strlen($ip) > 45 || filter_var($ip, FILTER_VALIDATE_IP) === false)
		{
			return '';
		}

		if (in_array($ip, ['0.0.0.0', '::', '0:0:0:0:0:0:0:0'], true) || preg_match('/^[0:.]+$/D', $ip) === 1)
		{
			return '';
		}

		return $ip;
	}
}
