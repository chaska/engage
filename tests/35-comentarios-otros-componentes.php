<?php
/**
 * 0.8.2: comentarios en el contenido de CUALQUIER componente. Ejecuta el codigo REAL de Meta y del plugin de contenido con stubs
 * minimos (tests/stubs/otros-componentes.php); el Joomla real, Chromium y la cache de pagina estan en tests/joomla-live/21-otros-componentes.sh.
 *  A) Meta::parseAssetId: solo enteros positivos
 *  B) Meta::sanitiseMeta / getAssetAccessMeta: la respuesta de un plugin de terceros es DATO NO CONFIABLE (tipos, rangos, URLs, texto)
 *  C) Meta::isAssetViewable: published, categoria, access y parent_access; fail-closed
 *  D) areCommentsClosedAfterTime ya no mueve la fecha guardada en la cache al llamarla varias veces
 *  E) el plugin: el activo manda, no el contexto; asset_id invalido o desconocido no muestra nada; no se toca #__content
 *  F) el articulo sigue el mismo camino que antes (mismo asset_id, mismas ordenes, mismo resultado)
 *  G) analisis estatico: el guard del backtrace, la vista sin URL directa, sin consultas de #__content en el camino generico
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/otros-componentes.php';

use Akeeba\Component\Engage\Site\Helper\Meta;
use Akeeba\Plugin\Content\Engage\Extension\Engage;
use Joomla\CMS\User\User;
use Joomla\Event\Event;
use Joomla\Registry\Registry;

$root = dirname(__DIR__);
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once $root . '/src/component/frontend/src/Helper/Meta.php';
require_once $root . '/src/plugins/content/engage/src/Extension/Engage.php';

$GLOBALS['T_USER']   = new User();
$GLOBALS['T_PARAMS'] = [];

/** Respuesta bien formada de un plugin de terceros (se cambia lo que cada caso necesita). */
$bueno = fn(array $o = []): array => array_replace([
	'type'               => 'viaje',
	'title'              => 'Ruta por Lisboa',
	'category'           => 'Viajes',
	'author_name'        => 'Ana',
	'author_email'       => 'ana@example.test',
	'url'                => 'index.php?option=com_viajes&view=ruta&id=3',
	'public_url'         => 'https://sitio.test/viajes/ruta-3',
	'published'          => true,
	'published_on'       => new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC')),
	'access'             => 1,
	'parent_access'      => 1,
	'category_published' => 1,
	'parameters'         => new Registry(['comments_enabled' => 1, 'comments_close_after' => 0, 'default_publish' => 1]),
], $o);
$sigId = 1000;
/** Registra un activo de un plugin de terceros y devuelve el meta que ve Engage (un id nuevo cada vez: Meta guarda una cache). */
$ver = function (array $respuesta) use (&$sigId): array {
	$id = ++$sigId;
	$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $respuesta : null;

	return Meta::getAssetAccessMeta($id, true);
};

// ------------------------------------------------------------------ A) parseAssetId
echo "A) Meta::parseAssetId solo acepta enteros positivos\n";
foreach ([[1, 1], [42, 42], ['42', 42], ['4294967295', 4294967295], [4294967295, 4294967295]] as [$in, $out]) {
	t_ok(Meta::parseAssetId($in) === $out, 'valido: ' . var_export($in, true));
}
foreach ([0, -1, -5, '0', '-3', '+5', '012', ' 12', '12 ', "12\n", '12abc', 'abc', '', '1.5', 1.5, 12.0, '1e3', '0x1A', '4294967296', 4294967296, 99999999999, '99999999999999999999', null, true, false, [], [12], new stdClass(), '１２'] as $mal) {
	t_ok(Meta::parseAssetId($mal) === 0, 'invalido -> 0: ' . json_encode($mal, JSON_PARTIAL_OUTPUT_ON_ERROR));
}

// ------------------------------------------------------------------ B) sanitiseMeta
echo "B) La respuesta del plugin de terceros se valida como dato no confiable\n";
$m = $ver($bueno());
t_ok($m['type'] === 'viaje' && $m['title'] === 'Ruta por Lisboa' && $m['published'] === true && $m['access'] === 1 && $m['parent_access'] === 1 && $m['category_published'] === 1, 'una respuesta bien formada llega tal cual');
t_ok($m['url'] === 'index.php?option=com_viajes&view=ruta&id=3' && $m['public_url'] === 'https://sitio.test/viajes/ruta-3', 'URL relativa y URL del propio sitio aceptadas');
t_ok($m['author_email'] === 'ana@example.test' && $m['parameters'] instanceof Registry && $m['parameters']->get('comments_enabled') === 1, 'correo y parametros intactos');
foreach (['unknown', 'Article', 'viaje ', 'via je', '1viaje', '', 'a-b', str_repeat('a', 33), "viaje\n", '<b>', null, 5, true, [], ['viaje']] as $tipo) {
	$m = $ver($bueno(['type' => $tipo]));
	t_ok($m['type'] === 'unknown' && $m['published'] === false && $m['title'] === '', 'tipo no valido -> activo desconocido y nada de lo demas se usa: ' . json_encode($tipo));
}
$x = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
$m = $ver($bueno(['title' => $x . "\x00\x07\x1B", 'category' => $x, 'author_name' => $x]));
t_ok($m['title'] === $x && $m['category'] === $x && $m['author_name'] === $x, 'titulo, categoria y autor se conservan como TEXTO (se escapan al mostrarlos) sin caracteres de control');
$m = $ver($bueno(['title' => str_repeat('é', 400)]));
t_ok(mb_strlen($m['title']) === 255, 'titulo recortado a 255 caracteres');
foreach ([['a'], new stdClass()] as $v) {
	$m = $ver($bueno(['title' => $v, 'category' => $v, 'author_name' => $v]));
	t_ok($m['title'] === '' && $m['category'] === '' && $m['author_name'] === '', 'titulo/categoria/autor que no son texto -> vacio');
}
foreach (["a@b.c\r\nBcc: x@y.z", 'no es correo', '<a@b.c>', str_repeat('a', 250) . '@example.test', ['a@b.c'], 5] as $e) {
	t_ok($ver($bueno(['author_email' => $e]))['author_email'] === null, 'correo invalido -> null: ' . substr(json_encode($e), 0, 40));
}
$url = [
	'javascript:alert(1)', 'JaVaScRiPt:alert(1)', " javascript:alert(1)", "java\nscript:alert(1)", 'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:x', 'file:///etc/passwd',
	'//evil.test/x', '///evil.test', '\\\\evil.test', '/\\evil.test', 'https://evil.test/', 'http://evil.test/x', 'https://sitio.test.evil.test/', 'https://sitio.test@evil.test/',
	'https://usuario:clave@sitio.test/', 'https://sitio.test:8443/x', 'ftp://sitio.test/x', '/x" onmouseover="alert(1)', "/x'onmouseover='a", '/x<script>', '/x`y', "/x\ty", 'a b', 'foo:bar', 'mailto:a@b.c',
	str_repeat('a', 2100), ['/x'], 5, true,
];
foreach ($url as $u) {
	$m = $ver($bueno(['url' => $u, 'public_url' => $u]));
	t_ok($m['url'] === null && $m['public_url'] === null, 'URL rechazada: ' . substr(json_encode($u), 0, 50));
}
foreach (['/viajes/3', 'index.php?option=com_x&view=y&id=3', 'index.php?a=b:c', 'viajes/ruta-3', '#ancla', '?pagina=2', 'https://sitio.test/a/b?x=1#y', 'HTTPS://SITIO.TEST/x', 'http://sitio.test/x', '/viajes/caf%C3%A9', '/viajes/café'] as $u) {
	t_ok($ver($bueno(['url' => $u]))['url'] === $u, 'URL aceptada: ' . $u);
}
t_ok($ver($bueno(['url' => '']))['url'] === '', 'URL vacia se conserva vacia (como un articulo sin URL)');
foreach (['si', 'true', 'yes', 2, -1, 0, '0', '', null, [], 1.0] as $p) {
	t_ok($ver($bueno(['published' => $p]))['published'] === false, 'published no es true -> false: ' . json_encode($p));
}
foreach ([true, 1, '1'] as $p) {
	t_ok($ver($bueno(['published' => $p]))['published'] === true, 'published verdadero: ' . json_encode($p));
}
foreach ([0, -1, '0', '-1', 'x', '', null, [], 1.5, 4294967296, true, '1 ', ' 1', '01'] as $a) {
	$m = $ver($bueno(['access' => $a]));
	t_ok($m['access'] === Meta::NO_LEVEL, 'access invalido -> nivel que nadie tiene: ' . json_encode($a));
}
t_ok($ver($bueno(['access' => '3']))['access'] === 3, 'access "3" -> 3');
foreach ([0, -1, 'x', '', [], 1.5] as $a) {
	t_ok($ver($bueno(['parent_access' => $a]))['parent_access'] === Meta::NO_LEVEL, 'parent_access invalido (no null) -> nivel que nadie tiene: ' . json_encode($a));
}
t_ok($ver($bueno(['parent_access' => null]))['parent_access'] === null, 'parent_access null = sin padre con nivel propio');
foreach (['x', 'abc', 99, -9, [], 1.5] as $c) {
	t_ok($ver($bueno(['category_published' => $c]))['category_published'] === 0, 'category_published invalido -> 0 (no publicada): ' . json_encode($c));
}
foreach ([[null, null], [1, 1], ['2', 2], [true, 1], [0, 0], [-2, -2]] as [$in, $out]) {
	t_ok($ver($bueno(['category_published' => $in]))['category_published'] === $out, 'category_published ' . json_encode($in) . ' -> ' . json_encode($out));
}
$m = $ver($bueno(['published_on' => 'no es una fecha']));
t_ok($m['published_on'] instanceof DateTimeInterface && $m['published_on']->getTimestamp() === 0, 'fecha invalida -> la epoca (con comments_close_after > 0 eso cierra)');
$m = $ver($bueno(['published_on' => 12345]));
t_ok($m['published_on']->getTimestamp() === 0, 'fecha que es un entero -> la epoca');
$m = $ver($bueno(['published_on' => new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC'))]));
t_ok($m['published_on']->format('Y-m-d H:i:s') === '2026-03-04 05:06:07', 'DateTimeInterface -> Date con la misma hora');
$p = fn(array $a) => $ver($bueno(['parameters' => new Registry($a)]))['parameters'];
t_ok($ver($bueno(['parameters' => ['comments_enabled' => 1]]))['parameters']->toArray() === [], 'parameters que no es un Registry -> vacio (comentarios cerrados)');
t_ok($p(['comments_enabled' => 'yes'])->get('comments_enabled') === 0 && $p(['comments_enabled' => '1'])->get('comments_enabled') === 1 && $p(['comments_enabled' => true])->get('comments_enabled') === 1, 'comments_enabled solo 0 o 1');
t_ok($p(['comments_close_after' => '30'])->get('comments_close_after') === 30 && $p(['comments_close_after' => -5])->get('comments_close_after', 'x') === 0 && $p(['comments_close_after' => 99999999])->get('comments_close_after') === 36500, 'comments_close_after entero 0..36500');
$q = $p(['comments_close_after' => 'abc', 'comments_enabled' => 1]);
t_ok($q->get('comments_enabled', 0) === 0, 'comments_close_after invalido cierra los comentarios');
t_ok($p(['default_publish' => 5])->get('default_publish', 'x') === 0 && $p(['default_publish' => '-1'])->get('default_publish') === -1 && $p(['default_publish' => 1])->get('default_publish') === 1, 'default_publish invalido -> 0 (moderacion)');
$q = $p(['comments_show' => ['x'], 'comments_ordering' => new stdClass(), 'intruso' => 'x', 'comments_notify_author' => str_repeat('a', 100)]);
t_ok($q->toArray() === [], 'matrices, objetos, claves desconocidas y textos largos se descartan');

// ------------------------------------------------------------------ C) isAssetViewable
echo "C) Meta::isAssetViewable\n";
$u = new User(); $u->levels = [1, 2];
$vis = fn(array $o) => Meta::isAssetViewable(array_replace(['type' => 'viaje', 'published' => true, 'access' => 1, 'parent_access' => 2, 'category_published' => 1], $o), $u);
t_ok($vis([]) === true, 'publicado, categoria publicada, niveles del usuario: visible');
t_ok($vis(['parent_access' => null]) === true && $vis(['category_published' => null]) === true, 'sin padre / sin categoria: visible');
t_ok($vis(['type' => 'unknown']) === false && $vis(['type' => '']) === false && $vis(['type' => null]) === false && $vis(['type' => ['a']]) === false, 'tipo desconocido, vacio o no texto: no');
t_ok($vis(['published' => false]) === false && $vis(['published' => 1]) === false && $vis(['published' => '1']) === false && $vis(['published' => null]) === false, 'published tiene que ser true');
t_ok($vis(['category_published' => 0]) === false && $vis(['category_published' => -2]) === false && $vis(['category_published' => 2]) === true, 'categoria sin publicar o en la papelera: no; archivada: si (como Joomla)');
t_ok($vis(['access' => 3]) === false && $vis(['parent_access' => 3]) === false, 'sin el nivel del activo o del padre: no');
t_ok($vis(['access' => 0]) === false && $vis(['access' => null]) === false && $vis(['access' => '1']) === false && $vis(['access' => Meta::NO_LEVEL]) === false, 'access 0, null, texto o invalido NO es «sin restriccion»: no');
t_ok($vis(['parent_access' => 0]) === false && $vis(['parent_access' => '2']) === false, 'parent_access 0 o texto: no');
$u2 = new User(); $u2->levels = ['1', '2'];
t_ok(Meta::isAssetViewable(['type' => 'viaje', 'published' => true, 'access' => 2, 'parent_access' => null, 'category_published' => null], $u2) === true, 'los niveles del usuario como texto tambien valen');
t_ok(Meta::isAssetViewable([], $u) === false, 'meta vacio: no');

// ------------------------------------------------------------------ D) comments_close_after estable
echo "D) comments_close_after no cambia entre llamadas\n";
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno(['published_on' => new DateTime('-40 days', new DateTimeZone('UTC')), 'parameters' => new Registry(['comments_enabled' => 1, 'comments_close_after' => 30])]) : null;
$r = [Meta::areCommentsClosedAfterTime($id), Meta::areCommentsClosedAfterTime($id), Meta::areCommentsClosedAfterTime($id), Meta::areCommentsClosed($id)];
t_ok($r === [true, true, true, true], 'publicado hace 40 dias y se cierra a los 30: cerrado SIEMPRE (antes la 2.a llamada movia la fecha y lo reabria): ' . json_encode($r));
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno(['published_on' => new DateTime('-10 days', new DateTimeZone('UTC')), 'parameters' => new Registry(['comments_enabled' => 1, 'comments_close_after' => 30])]) : null;
t_ok(Meta::areCommentsClosed($id) === false && Meta::areCommentsClosed($id) === false, 'publicado hace 10 dias: abierto');
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno(['parameters' => new Registry(['comments_enabled' => 0])]) : null;
t_ok(Meta::areCommentsClosed($id) === true, 'comments_enabled = 0: cerrado');
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno(['parameters' => new Registry([])]) : null;
t_ok(Meta::areCommentsClosed($id) === true, 'parametros sin comments_enabled: cerrado (el tercero tiene que decir que si)');

// ------------------------------------------------------------------ E) el plugin
echo "E) El plugin de contenido con contenido de otros componentes\n";

/** Dispatcher falso: imprime lo que recibiria el controlador de comentarios. */
$GLOBALS['T_DESPACHOS'] = [];
$despachador = new class {
	public function createDispatcher($app, $input) {
		return new class($input) {
			public function __construct(private $i) {}
			public function setUseErrorHandler($b) { return $this; }
			public function dispatch() { $GLOBALS['T_APP_ASSET'][] = $GLOBALS['T_APP']->input->get('asset_id'); $GLOBALS['T_DESPACHOS'][] = $this->i->getArray(); echo 'HILO[' . $this->i->get('asset_id') . '|' . $this->i->get('akengage_order_Dir') . '|' . $this->i->get('view') . '/' . $this->i->get('task') . ']'; }
		};
	}
};
$GLOBALS['T_APP'] = new class {
	public $input;
	public bool $sitio = true;
	public $identidad;
	public function __construct() { $this->input = new Joomla\Input\Input(['asset_id' => '666', 'option' => 'com_hostil', 'task' => 'borrar', 'akengage_order_Dir' => 'DESC; DROP TABLE x']); $this->identidad = $GLOBALS['T_USER']; }
	public function isClient($c) { return $this->sitio && $c === 'site'; }
	public function getIdentity() { return $GLOBALS['T_USER']; }
	public function getDocument() { return new class { public function getWebAssetManager() { return new class { public function getRegistry() { return new class { public function addRegistryFile($f) {} }; } }; } }; }
};
$rc = new ReflectionClass(Engage::class);
$plugin = $rc->newInstanceWithoutConstructor();
$set = function (string $prop, $v) use ($rc, $plugin) { $p = $rc->getProperty($prop); $p->setAccessible(true); $p->setValue($plugin, $v); };
$set('comDispatcherFactory', $despachador);
$set('parametersKeys', ['comments_show', 'default_publish', 'comments_show_featured', 'comments_show_category', 'comments_show_article', 'comments_ordering', 'comments_enabled', 'comments_close_after', 'comments_notify_author', 'comments_notify_users']);
$set('parameterDefaults', ['comments_show' => 1, 'comments_ordering' => 'asc', 'comments_enabled' => 1]);

/** Lanza onContentAfterDisplay como lo hace Joomla y devuelve el HTML que aporta el plugin. */
$lanza = function ($context, $row, $params = null, array $extra = []) use ($plugin): string {
	$params = $params ?? new Registry();
	$ev = new Event(array_merge([$context, $row, $params, 0], $extra));
	$plugin->onContentAfterDisplay($ev);

	return implode('', (array) $ev->getArgument('result', []));
};
$item = fn($asset) => (object) ['title' => 'Cualquier cosa', 'asset_id' => $asset, 'access' => 1];
$vive = function (array $meta = [], $contexto = 'com_viajes.ruta', ?Registry $params = null) use ($bueno, &$sigId, $lanza, $item): string {
	$id = ++$sigId;
	$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno($meta) : null;
	$GLOBALS['T_PLUGIN_LLAMADAS'] = [];

	return $lanza($contexto, $item($id), $params);
};

$GLOBALS['T_DESPACHOS'] = [];
$html = $vive();
$id1  = $sigId;
t_ok($html === "HILO[$id1|asc|comments/main]", "un contenido de otro componente obtiene el hilo con SU asset_id (no el de la peticion: 666) y el orden validado: $html");
t_ok(($GLOBALS['T_DESPACHOS'][0]['asset_id'] ?? null) === $id1 && $GLOBALS['T_DESPACHOS'][0]['option'] === 'com_engage' && $GLOBALS['T_DESPACHOS'][0]['task'] === 'main', 'la peticion no decide el activo, ni la opcion, ni la tarea');
t_ok(($GLOBALS['T_DB_TOCADA'] ?? 0) === 0, 'NO se toco la base de datos (ni #__content ni nada) en todo el camino');
t_ok(($GLOBALS['T_APP_ASSET'][0] ?? null) === $id1 && $GLOBALS['T_APP']->input->get('asset_id') === '666', 'durante el despacho la peticion lleva el asset_id (clave de la cache de vistas de Joomla) y despues vuelve a su valor original (666)');
$GLOBALS['T_APP']->input->set('asset_id', null); $GLOBALS['T_APP_ASSET'] = [];
$vive();
t_ok(($GLOBALS['T_APP_ASSET'][0] ?? null) === $sigId && $GLOBALS['T_APP']->input->get('asset_id') === null, 'si la peticion no tenia asset_id, despues del despacho se lee como antes (nulo)');
$GLOBALS['T_APP']->input->set('asset_id', '666');
t_ok(str_starts_with($vive(['parameters' => new Registry(['comments_enabled' => 0])]), 'HILO['), 'comments_enabled = 0: el hilo se ve igual (el formulario lo oculta Meta::areCommentsClosed)');
t_ok($vive(['parameters' => new Registry(['comments_enabled' => 1, 'comments_ordering' => 'desc'])]) === 'HILO[' . $sigId . '|desc|comments/main]', 'comments_ordering desc de la meta');
t_ok(str_contains($vive(['parameters' => new Registry(['comments_enabled' => 1, 'comments_ordering' => 'x; DROP'])]), '|asc|'), 'comments_ordering invalido -> asc');
t_ok(str_starts_with($vive(['parameters' => new Registry(['comments_enabled' => 1, 'comments_show' => 1])]), 'HILO['), 'comments_show = 1: se muestra');
foreach ([0, '0', 2, 'no'] as $v) {
	t_ok($vive(['parameters' => new Registry(['comments_enabled' => 1, 'comments_show' => $v])]) === '', 'comments_show = ' . json_encode($v) . ': no se muestra nada');
}
t_ok(str_starts_with($vive(['parameters' => new Registry(['comments_enabled' => 1, 'comments_show' => -1])]), 'HILO['), 'comments_show = -1 (global) con el valor por omision del componente (1): se muestra');
$GLOBALS['T_PARAMS'] = ['comments_show' => 0];
t_ok($vive(['parameters' => new Registry(['comments_enabled' => 1])]) === '', 'comments_show sin definir y el componente lo tiene en «ocultar»: no se muestra');
t_ok(str_starts_with($vive(['parameters' => new Registry(['comments_enabled' => 1, 'comments_show' => 1])]), 'HILO['), 'comments_show = 1 en la meta manda sobre el componente');
$GLOBALS['T_PARAMS'] = [];
t_ok(str_starts_with($vive(['parameters' => new Registry(['comments_enabled' => 1])]), 'HILO['), 'comments_show sin definir y sin opcion en el componente: 1 por omision');

echo "   El activo manda: fail-closed\n";
$cierra = fn(string $que, array $meta) => t_ok($vive($meta) === '', "no se muestra nada: $que");
$cierra('sin publicar', ['published' => false]);
$cierra('categoria sin publicar', ['category_published' => 0]);
$cierra('categoria en la papelera', ['category_published' => -2]);
$cierra('nivel de acceso que el usuario no tiene', ['access' => 5]);
$cierra('nivel del padre que el usuario no tiene', ['parent_access' => 5]);
$cierra('access invalido', ['access' => 'x']);
$cierra('access 0', ['access' => 0]);
$cierra('access null', ['access' => null]);
$cierra('parent_access invalido', ['parent_access' => 'x']);
$cierra('tipo reservado `article`', ['type' => 'article']);
$cierra('tipo invalido', ['type' => 'Raro Tipo']);
$cierra('tipo unknown', ['type' => 'unknown']);
$cierra('published como texto "yes"', ['published' => 'yes']);
$GLOBALS['T_USER']->levels = [1, 5];
t_ok(str_starts_with($vive(['access' => 5, 'parent_access' => 5]), 'HILO['), 'con el nivel 5 el mismo contenido SI se ve');
$GLOBALS['T_USER']->levels = [1];

echo "   asset_id invalido: ni se pregunta a los plugins\n";
foreach ([0, -1, -5, 'abc', '12abc', '1e3', '0x1A', ' 12', 1.5, null, true, false, [], [12], new stdClass(), 99999999999, '99999999999999999999', ''] as $mal) {
	$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => $bueno(); // un plugin que respondería a CUALQUIER id
	$GLOBALS['T_PLUGIN_LLAMADAS'] = [];
	t_ok($lanza('com_viajes.ruta', $item($mal)) === '' && $GLOBALS['T_PLUGIN_LLAMADAS'] === [], 'asset_id ' . json_encode($mal, JSON_PARTIAL_OUTPUT_ON_ERROR) . ': nada y sin llamar a ningun plugin');
}
t_ok($lanza('com_viajes.ruta', (object) ['title' => 'sin asset']) === '', 'objeto sin asset_id: nada');
t_ok($lanza('com_viajes.ruta', ['asset_id' => 5]) === '' && $lanza('com_viajes.ruta', 'x') === '' && $lanza('com_viajes.ruta', null) === '', 'el item no es un objeto: nada');
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => null;
t_ok($lanza('com_viajes.ruta', $item(424242)) === '', 'asset_id valido que NINGUN plugin reconoce: nada');
$GLOBALS['T_PLUGINS'] = null;
t_ok($lanza('com_viajes.ruta', $item(424243)) === '', 'sin ningun plugin: nada');
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => 'texto';
t_ok($lanza('com_viajes.ruta', $item(424244)) === '', 'un plugin que devuelve basura (no una matriz): nada');

echo "   El contexto no concede nada\n";
$GLOBALS['T_DESPACHOS'] = [];
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno(['access' => 7, 'parent_access' => null]) : null; // lo reconoce el plugin B y B dice «nivel 7»
foreach (['com_viajes.ruta', 'com_otro.cosa', 'mod_algo.contenido', 'text', 'com_contact.contact', 'com_viajes'] as $ctx) {
	t_ok($lanza($ctx, $item($id)) === '', "contexto «{$ctx}» con un activo que otro plugin protege con el nivel 7: el usuario (nivel 1) no ve nada");
}
$GLOBALS['T_USER']->levels = [1, 7];
t_ok(str_starts_with($lanza('com_viajes.ruta', $item($id)), 'HILO[') && str_starts_with($lanza('com_otro.cosa', $item($id)), 'HILO['), 'con el nivel 7 se ve en cualquier contexto: manda el activo (lo mismo da el texto del contexto)');
$GLOBALS['T_USER']->levels = [1];
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => $bueno();
foreach (['com_content.category', 'com_content.featured', 'com_content.archive', 'COM_CONTENT.FEATURED', 'com_content.foo', "com_viajes.ruta\n", 'com viajes', '', '.x', 'x"><script>', str_repeat('a', 120), 'com_viajes.ruta;', null, 5, ['com_viajes.ruta'], new stdClass()] as $ctx) {
	t_ok($lanza($ctx, $item(515151)) === '', 'contexto no permitido: nada: ' . substr(json_encode($ctx, JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 50));
}
// Un tercero que pone el contexto de los articulos NO entra por el camino generico: sigue el camino del articulo (necesita un articulo de verdad)
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => $bueno();
t_ok($lanza('com_content.article', (object) ['asset_id' => 515151, 'access' => 1]) === '', 'contexto de articulo sin id de articulo: nada');

echo "   Newsflash, parametros y cliente\n";
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno() : null;
t_ok($lanza('com_viajes.ruta', $item($id), new Registry(['moduleclass_sfx' => ''])) === '', 'parametros de un modulo (moduleclass_sfx): nada');
t_ok($lanza('com_viajes.ruta', $item($id), (object) []) === '' && $lanza('com_viajes.ruta', $item($id), null, []) !== null, 'parametros que no son un Registry: nada');
$ev = new Event(['com_viajes.ruta', $item($id), null, 0]); $plugin->onContentAfterDisplay($ev);
t_ok(implode('', (array) $ev->getArgument('result', [])) === '', 'params null: nada');
$ev = new Event(['com_viajes.ruta', $item($id)]); $plugin->onContentAfterDisplay($ev);
t_ok(implode('', (array) $ev->getArgument('result', [])) === '', 'evento con menos argumentos de los esperados: nada y sin avisos de PHP');
$GLOBALS['T_APP']->sitio = false;
t_ok($lanza('com_viajes.ruta', $item($id)) === '', 'fuera del sitio (administracion): nada');
$GLOBALS['T_APP']->sitio = true;
t_ok(str_starts_with($lanza('com_viajes.ruta', $item($id)), 'HILO['), 'de nuevo en el sitio: hilo');

echo "   Contador: no soportado fuera de com_content (no sale nada, sin avisos)\n";
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno() : null;
foreach (['com_viajes.ruta', 'com_content.article', 'com_content.category'] as $ctx) {
	foreach ([$item($id), null, 'x', ['asset_id' => $id]] as $fila) {
		$ev = new Event([$ctx, $fila, new Registry(), 0]); $plugin->onContentBeforeDisplay($ev);
		$ev2 = new Event([$ctx, $fila, new Registry(), 0]); $plugin->onContentAfterDisplay($ev2);
		t_ok(trim(implode('', (array) $ev->getArgument('result', []))) === '' && !str_contains(implode('', (array) $ev2->getArgument('result', [])), 'COMENTARIOS'), "contador de $ctx con " . gettype($fila) . ': nada');
	}
}

echo "   Un item con el asset de un articulo en otro contexto\n";
$id = ++$sigId;
$GLOBALS['T_PLUGINS'] = fn(int $a, bool $c) => ($a === $id) ? $bueno(['type' => 'article']) : null; // el plugin de com_content lo reconoce
t_ok($lanza('com_viajes.ruta', $item($id)) === '' && $lanza('mod_articles.article', $item($id)) === '', 'el tipo `article` esta reservado para com_content: ningun otro contexto lo muestra');

// ------------------------------------------------------------------ F) articulos
echo "F) Los articulos siguen el mismo camino\n";
$GLOBALS['T_PLUGINS'] = null;
$todo = array_fill_keys(['comments_show', 'default_publish', 'comments_show_featured', 'comments_show_category', 'comments_show_article', 'comments_ordering', 'comments_enabled', 'comments_close_after', 'comments_notify_author', 'comments_notify_users'], 1);
$todo['comments_ordering'] = 'desc';
$art = fn(array $o = []) => (object) array_replace([
	'id' => 7, 'asset_id' => 7007, 'title' => 'Articulo', 'alias' => 'articulo', 'state' => 1, 'catid' => 2, 'attribs' => json_encode(['engage' => $todo]),
	'publish_up' => null, 'publish_down' => null, 'access' => 1, 'language' => '*', 'created_by' => 5, 'introtext' => 'x',
], $o);
$GLOBALS['T_DESPACHOS'] = [];
$h = $lanza('com_content.article', $art());
t_ok(preg_match('~^HILO\[7007\|[^|]*\|comments/main\]$~', $h) === 1, "articulo: mismo hilo, con su asset_id: $h");
t_ok(($GLOBALS['T_DESPACHOS'][0]['asset_id'] ?? null) === 7007, 'articulo: el despachador recibe el asset_id del articulo');
t_ok(end($GLOBALS['T_APP_ASSET']) === '666', 'articulo: la peticion NO se toca (asset_id sigue siendo el de la peticion)');
t_ok($lanza('com_content.article', $art(['access' => 3])) === '', 'articulo con un nivel de acceso que el usuario no tiene: nada (como antes)');
t_ok($lanza('com_content.article', $art(), new Registry(['moduleclass_sfx' => 'x'])) === '', 'articulo en un modulo Newsflash: nada (como antes)');
t_ok($lanza('com_content.article', $art(['id' => 0])) === '', 'articulo sin id: nada (como antes)');
t_ok($lanza('com_content.article', $art(['asset_id' => 0])) === '', 'articulo con asset_id 0: nada (como antes)');
$GLOBALS['T_APP']->sitio = false;
t_ok($lanza('com_content.article', $art()) === '', 'articulo en la administracion: nada (como antes)');
$GLOBALS['T_APP']->sitio = true;
t_ok($lanza('com_content.category', $art()) === '' && $lanza('com_content.featured', $art()) === '', 'listas de com_content: sin hilo (como antes)');
t_ok(($GLOBALS['T_DB_TOCADA'] ?? 0) === 0, 'ni siquiera el articulo toca la base de datos con su asset_id y su fila ya dados');

// ------------------------------------------------------------------ G) estatico
echo "G) Analisis estatico\n";
$eng = $lee($root . '/src/plugins/content/engage/src/Extension/Engage.php');
$ctl = $lee($root . '/src/component/frontend/src/Controller/CommentsController.php');
preg_match('/private function getForeignAsset\(.*?\n\t\}\n/s', $eng, $mm);
$gen = $mm[0] ?? '';
t_ok($gen !== '', 'getForeignAsset existe');
foreach (['#__content', 'attribs', 'getParametersForArticle', 'cacheArticleRow', 'getAssetIdByArticleId', 'loadArticleObject', 'getArticleByAssetId', 'CategoryModel', 'com_categories', '->input', 'getInt', 'JInput'] as $prohibido) {
	t_ok(!str_contains($gen, $prohibido), "el camino generico no usa: $prohibido");
}
t_ok(str_contains($gen, 'Meta::parseAssetId') && str_contains($gen, 'Meta::isAssetViewable') && str_contains($gen, 'Meta::getAssetAccessMeta'), 'usa parseAssetId, getAssetAccessMeta e isAssetViewable');
preg_match('/private function renderComments\(.*?\n\t\}\n/s', $eng, $mm);
t_ok(str_contains($mm[0] ?? '', 'getForeignAsset(') && str_contains($mm[0], "createDispatcher(") , 'renderComments llama a getForeignAsset y es quien despacha el componente');
t_ok(preg_match_all('/createDispatcher\(/', $eng) === 1, 'el plugin despacha el componente en UN solo sitio (renderComments)');
t_ok(str_contains($eng, 'private function renderComments('), 'renderComments sigue siendo privado');
t_ok(str_contains($ctl, '$info[\'class\'] === Engage::class && $info[\'function\'] === \'renderComments\'') && str_contains($ctl, "throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403)"), 'el guard del backtrace de CommentsController sigue donde estaba');
$pos = fn(string $n) => strpos($ctl, $n);
t_ok($pos('debug_backtrace') < $pos('Meta::isAssetViewable') && $pos('Meta::isAssetViewable') < $pos('$model->setState(\'filter.asset_id\''), 'el guard va primero y despues la comprobacion del activo, antes de pasar nada a la vista');
t_ok(str_contains($ctl, "!== 'article' && !Meta::isAssetViewable("), 'la vista exige que el activo (que no sea un articulo) lo pueda ver el usuario');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
$usos = [];
foreach ($it as $f) {
	if ($f->getExtension() !== 'php') { continue; }
	if (preg_match('/Engage::class|\\\\Engage\b.*renderComments|renderComments/', (string) file_get_contents($f->getPathname())) && !str_ends_with($f->getPathname(), 'Extension/Engage.php') && !str_ends_with($f->getPathname(), 'CommentsController.php')) { $usos[] = $f->getPathname(); }
}
t_ok($usos === [], 'nadie mas nombra renderComments: ' . implode(', ', $usos));
t_ok(preg_match('/deleteAssetData/', $eng) === 0 && preg_match('/deleteAssetData/', $ctl) === 0, 'el borrado por asset no esta al alcance de ningun controlador ni tarea');

// ------------------------------------------------------------------ H) vistas y layout
echo "H) La vista no sale de la cache estatica de Joomla y el layout tiene lista cerrada\n";
$tr = $lee($root . '/src/component/backend/src/Mixin/ControllerReusableModelsTrait.php');
preg_match('/public function getView\(.*?\n\t\}\n/s', $tr, $mm);
$gv = $mm[0] ?? '';
t_ok($gv !== '', 'getView existe en el rasgo');
t_ok(!str_contains($gv, 'parent::getView(') && !str_contains($gv, 'self::$views'), 'ya no usa BaseController::getView ni su cache estatica compartida (self::$views)');
t_ok(str_contains($gv, '$this->factory->createView(') && str_contains($gv, 'self::$engageViews'), 'crea la vista con la fabrica MVC de Engage y la guarda en su propia cache');
t_ok(str_contains($gv, "str_starts_with(get_class(\$created), 'Akeeba\\\\Component\\\\Engage\\\\')"), 'rechaza (404) toda vista que no sea una clase de Engage');
t_ok(str_contains($gv, "'/^[A-Za-z0-9]{1,32}$/D'") && substr_count($gv, "'/^[A-Za-z0-9]{1,32}$/D'") === 2, 'layout y nombre de la vista solo con letras y cifras (sin puntos, barras, NUL ni guion bajo)');
t_ok(str_contains($gv, "'layout', 'default', 'cmd'") && !str_contains($gv, "'string'"), 'el layout de la peticion se lee con el filtro CMD y no con `string`');
// Todo lo que lee `layout` o `view` de la peticion en el codigo de Engage
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
$lee2 = [];
foreach ($it as $f) {
	if ($f->getExtension() !== 'php') { continue; }
	foreach (preg_split('/\R/', (string) file_get_contents($f->getPathname())) as $n => $l) {
		if (preg_match('/->(?:get|getCmd|getString|getWord|getPath|getRaw)\(\s*[\'"](layout|tmpl|template|view)[\'"]/', $l) && !preg_match('/^\s*(\*|\/\/)/', $l)) { $lee2[] = substr($f->getPathname(), strlen($root) + 1) . ':' . ($n + 1); }
	}
}
sort($lee2);
t_ok(count(array_filter($lee2, fn($x) => !str_contains($x, 'ControllerReusableModelsTrait') && !str_contains($x, 'Dispatcher'))) === 0, 'solo el rasgo del controlador (y el dispatcher) leen layout/view de la peticion: ' . implode(', ', $lee2));
$plant = array_filter(glob($root . '/src/component/*/tmpl/*/*.php'), fn($f) => true);
$mal = [];
foreach ($plant as $f) { if (basename($f) !== 'errorhandler.php' && (preg_match('/\$_(GET|POST|REQUEST)\b|input->get[A-Za-z]*\(\s*[\'"]layout/', (string) file_get_contents($f)))) { $mal[] = basename($f); } }
t_ok($mal === [], 'ninguna plantilla lee layout de la peticion: ' . implode(',', $mal));
// Funcionamiento: el filtro de layout del rasgo, extraido y ejecutado con cargas de ataque
$permitido = fn(string $l): string => (preg_match('/^[A-Za-z0-9]{1,32}$/D', $l) === 1) ? $l : 'default';
foreach (['../../../../configuration', '..%2f..%2fconfiguration', '....//....//configuration', "default\0", "default\0.php", '../x', '..\\x', 'x/y', '_form', 'a.b', str_repeat('a', 33), '', "edit\n", 'default/../../configuration', '%00'] as $l) {
	t_ok($permitido($l) === 'default', 'layout hostil -> default: ' . json_encode($l));
}
t_ok($permitido('edit') === 'edit' && $permitido('default') === 'default', 'layouts normales intactos');

t_fin();
