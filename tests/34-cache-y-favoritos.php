<?php
/**
 * 0.8.1: correcciones de la revision de seguridad independiente de la 0.8.0 (sin Joomla; lo dinamico esta en tests/joomla-live/20-xss.sh y 19-tanda2.sh):
 *  A) BAJA  favoritos / reacciones: una respuesta publicada cuyo padre (o algun antecesor) no lo esta se trata como la lista normal
 *  B) BAJA  plugin engagecache: `akengage_sort`, `akengage_fav`, `akengage_limit`, `akengage_limitstart` y `akengage_cid` pasan por listas cerradas
 *           ANTES de formar la clave de cache (valores no permitidos = como si no se hubieran enviado: ninguna entrada de cache nueva)
 *  C) BAJA  semaforo: con la cache global de Joomla activa (aunque no sea el plugin de cache de paginas) y sin el plugin Engage Cache, aviso ambar
 *  D) MEDIA el cache no recibe el anfitrion ni parametros desconocidos: se comprueba el codigo (la parte dinamica, en el Joomla real)
 */
namespace Joomla\CMS\Plugin { class CMSPlugin { public function __construct(&$subject = null, $config = []) {} public function getApplication() { return $GLOBALS['T_APP']; } } class PluginHelper { public static function isEnabled($a, $b) { return false; } } }
namespace Joomla\Event { interface SubscriberInterface {} class Event {} }
namespace Joomla\CMS\Language { class Text { public static function script($k) {} } }
namespace Joomla\CMS\Uri { class Uri { public static $vars = []; public static $path = '/index.php/articulo'; public static function getInstance() { return new self(); } public function delVar($n) { unset(self::$vars[$n]); } public function getPath() { return self::$path; } public function getQuery($array = false) { return self::$vars; } } }
namespace Joomla\Database { interface DatabaseInterface {} class ParameterType { const INTEGER = 'int'; } }
namespace Joomla\CMS\Component { class ComponentHelper { public static function getParams($n) { return new class { public function get($k, $d = null) { return $GLOBALS['T_PARAMS'][$k] ?? $d; } }; } } }
namespace Joomla\CMS {
	class Factory
	{
		public static function getApplication() { return $GLOBALS['T_APP']; }
		public static function getContainer() { return new class { public function get($x) { return $GLOBALS['T_DB']; } }; }
	}
}
namespace {
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => (string) file_get_contents($f);
$lf   = fn(string $f): string => str_replace("\r\n", "\n", $lee($f));

// ------------------------------------------------------------------ A) cadena de padres
echo "A) Favoritos y reacciones exigen la misma cadena de padres publicados que la lista normal\n";
foreach (['Reactions', 'ReactionStoreInterface', 'ReactionStore', 'ReactionRateLimiter'] as $k) { require_once "$c/backend/src/Helper/$k.php"; }
require __DIR__ . '/stubs/reacciones.php';
use Akeeba\Component\Engage\Administrator\Helper\Reactions as R;

$opts = R::options([]);
$ctx  = fn(array $o = []): array => array_merge(['userId' => 7, 'userEmail' => 'siete@example.test', 'canReact' => true, 'opts' => $opts, 'canViewAsset' => fn(int $a): bool => $a !== 666, 'allowRate' => fn(int $u): bool => true, 'now' => '2026-10-07 10:00:00'], $o);
$mk   = function (array $filas): MemStore {
	$s = new MemStore();
	foreach ($filas as $id => [$padre, $enabled]) { $s->comment($id, 1, $enabled, 99); $s->comments[$id]['parent_id'] = $padre; }
	return $s;
};
//  1 publicado (raiz) <- 2 publicado <- 3 publicado        ;  10 sin publicar (raiz) <- 11 publicado <- 12 publicado
//  20 spam (-3) <- 21 publicado ; 30 publicado cuyo padre 99 no existe ; 40 <- 41 <- 42 con 40 sin publicar (abuelo)
$s = $mk([1 => [0, 1], 2 => [1, 1], 3 => [2, 1], 10 => [0, 0], 11 => [10, 1], 12 => [11, 1], 20 => [0, -3], 21 => [20, 1], 30 => [99, 1], 40 => [0, 0], 41 => [40, 1], 42 => [41, 1]]);
$r = new R($s);
foreach ([1, 2, 3] as $id) { t_ok($r->toggle($ctx(), $id, 'favorite')['status'] === 200, "favorito en $id (cadena publicada entera): 200"); }
$antes = $s->rows;
foreach ([11 => 'padre sin publicar', 12 => 'abuelo sin publicar', 21 => 'padre spam', 30 => 'padre inexistente', 41 => 'padre sin publicar', 42 => 'abuelo sin publicar'] as $id => $why) {
	$x = $r->toggle($ctx(), $id, 'favorite');
	t_ok($x['status'] === 404 && $x['body'] === ['ok' => false, 'error' => 'unavailable'], "favorito en $id ($why): 404 igual que un comentario inexistente");
	$y = $r->toggle($ctx(), $id, 'like');
	t_ok($y['status'] === 404 && $y['body'] === $x['body'], "me gusta en $id: la misma respuesta");
}
t_ok($s->rows === $antes, 'esos intentos no escribieron nada');
$inexistente = $r->toggle($ctx(), 555, 'favorite');
t_ok($r->toggle($ctx(), 11, 'favorite') === $inexistente, 'la respuesta es identica byte a byte a la de un comentario que no existe (sin pistas)');
$w = (array) $r->state($ctx(), '1,2,11,12,21,30,42')['body']['items'];
t_ok(array_map('strval', array_keys($w)) === ['1', '2'], 'state: omite sin pistas las respuestas de un padre sin publicar (' . implode(',', array_keys($w)) . ')');
// una cadena muy larga o con bucle no cuelga ni se da por buena
$s = $mk([1 => [2, 1], 2 => [1, 1]]); $r = new R($s);
t_ok($r->toggle($ctx(), 1, 'favorite')['status'] === 404, 'cadena con bucle (datos corruptos): 404, sin recorrido infinito');
$filas = []; for ($i = 1; $i <= 40; $i++) { $filas[$i] = [$i - 1, 1]; }
$s = $mk($filas); $r = new R($s);
t_ok($r->toggle($ctx(), 5, 'favorite')['status'] === 200 && $r->toggle($ctx(), 40, 'favorite')['status'] === 404, 'cadena de 40 niveles: la corta se admite, la que pasa del tope se rechaza');
$s = $mk([1 => [0, 1], 2 => [1, 1]]); $r = new R($s); $s->comments[2]['parent_id'] = 1;
$cuenta = 0; $sw = new class($s) extends MemStore { public $n = 0; public function __construct($o) { foreach (get_object_vars($o) as $k => $v) { $this->$k = $v; } } public function comments(array $ids): array { $this->n++; return parent::comments($ids); } };
(new R($sw))->state($ctx(), '2');
t_ok($sw->n <= 3, 'state con un hijo: pocas lecturas ' . $sw->n . ' (una por nivel, no una por comentario)');
$m = $lf("$c/backend/src/Model/CommentsModel.php");
t_ok(str_contains($m, 'withVisibleAncestors($allIDs)') && str_contains($m, "setState('filter.favorite_user', 0)") && str_contains($m, 'finally'), 'el modelo filtra los favoritos con la lista visible (mismos filtros, sin el JOIN) y restaura el estado');

// ------------------------------------------------------------------ B) engagecache
echo "B) engagecache: lista cerrada antes de la clave de cache\n";
require_once "$root/src/plugins/system/engagecache/src/Extension/Engagecache.php";
class FakeInput
{
	public $d = []; public $get;
	public function __construct(array $d) { $this->d = $d; $this->get = $this; }
	public function get($k, $def = null, $f = 'cmd') { return $this->d[$k] ?? $def; }
	public function getCmd($k) { return $this->d[$k] ?? ''; }
	public function getMethod() { return $GLOBALS['T_METHOD'] ?? 'GET'; }
	public function set($k, $v) { $this->d[$k] = $v; }
}
$GLOBALS['T_DB'] = new class implements \Joomla\Database\DatabaseInterface {
	public $ids = [5, 6, 77]; public $last = 0; public $lookups = 0;
	public function createQuery() { return new class { public $id = 0; public function __call($m, $a) { return $this; } public function bind($n, &$v, $t = null) { $this->id = $v; return $this; } }; }
	public function getQuery($n = false) { return $this->createQuery(); }
	public function quoteName($n) { return $n; }
	public function setQuery($q, $a = 0, $b = 0) { $this->last = $q->id; $this->lookups++; return $this; }
	public function loadResult() { return in_array($this->last, $this->ids, true) ? 1 : null; }
};
$GLOBALS['T_APP'] = new class { public $registeredurlparams; public function get($k, $d = null) { return $k === 'list_limit' ? 20 : $d; } public function isClient($c) { return true; } };
$GLOBALS['T_PARAMS'] = ['default_limit' => 20];
$pl = (new ReflectionClass(\Akeeba\Plugin\System\EngageCache\Extension\Engagecache::class))->newInstanceWithoutConstructor();
$norm = (new ReflectionClass($pl))->getMethod('normalizeRequest');
$run = function (array $q) use ($pl, $norm): array { $i = new FakeInput($q); $norm->invoke($pl, $i); return array_filter($i->d, fn($v) => $v !== null); };
$quitados = function (array $q) use ($pl, $norm): array { $i = new FakeInput($q); return $norm->invoke($pl, $i); };
t_ok($quitados(['akengage_sort' => 'x"><script>', 'akengage_fav' => '1', 'akengage_limit' => '7']) === ['akengage_sort', 'akengage_limit'], 'normalizeRequest devuelve los nombres quitados (sort y limit si; fav=1 es valido)');
t_ok($run(['akengage_sort' => 'top', 'akengage_fav' => '1']) === ['akengage_sort' => 'top', 'akengage_fav' => '1'], 'valores validos de orden y favoritos: intactos');
foreach (['newest', 'oldest', 'top'] as $v) { t_ok($run(['akengage_sort' => $v]) === ['akengage_sort' => $v], "sort=$v se conserva"); }
$malos = 0;
for ($i = 0; $i < 30; $i++) { if ($run(['akengage_sort' => "valor$i"]) !== []) { $malos++; } if ($run(['akengage_fav' => "v$i"]) !== []) { $malos++; } }
t_ok($malos === 0, '30 valores distintos de akengage_sort y 30 de akengage_fav: todos se descartan (cero entradas de cache nuevas, que antes eran +60 ficheros)');
t_ok($run(['akengage_sort' => ['a'], 'akengage_fav' => ['1']]) === [], 'matrices en sort/fav: descartadas');
t_ok($run(['akengage_fav' => '2']) === [] && $run(['akengage_fav' => 'true']) === [] && $run(['akengage_fav' => '01']) === [], 'fav solo vale 1');
t_ok($run(['akengage_sort' => 'NEWEST']) === [] && $run(['akengage_sort' => 'newest ']) === [] && $run(['akengage_sort' => 'new']) === [], 'sort distingue mayusculas y no admite variantes');
// paginacion
t_ok($run(['akengage_limitstart' => '40']) === ['akengage_limitstart' => '40'], 'limitstart multiplo del tamano de pagina (20): se conserva');
t_ok($run(['akengage_limitstart' => '41']) === [] && $run(['akengage_limitstart' => '10001']) === [] && $run(['akengage_limitstart' => '999999']) === [] && $run(['akengage_limitstart' => '-5']) === [] && $run(['akengage_limitstart' => 'abc']) === [], 'limitstart no multiplo, > 10000, enorme, negativo o texto: descartado');
t_ok($run(['akengage_limitstart' => '10000']) === ['akengage_limitstart' => '10000'], 'limitstart 10000 (el tope, multiplo de 20): se conserva');
t_ok($run(['akengage_limit' => '50', 'akengage_limitstart' => '100']) === ['akengage_limit' => '50', 'akengage_limitstart' => '100'], 'limit 50 + limitstart 100: se conservan');
t_ok($run(['akengage_limit' => '50', 'akengage_limitstart' => '60']) === ['akengage_limit' => '50'], 'limitstart debe ser multiplo del limit pedido (60 no lo es de 50)');
$n = 0; for ($i = 1; $i <= 600; $i++) { if ($run(['akengage_limit' => (string) $i]) !== []) { $n++; } }
t_ok($n === 10, "de los 600 valores de akengage_limit solo sobreviven los tamanos de pagina de Joomla (5,10,15,20,25,30,50,100,200,500; el por defecto, 20, ya esta entre ellos): $n");
t_ok($run(['akengage_limit' => '0']) === [] && $run(['akengage_limit' => '1000']) === [] && $run(['akengage_limit' => '7']) === [], 'limit 0, 1000, 7: descartado');
// cid
$ant = $GLOBALS['T_DB']->lookups;
t_ok($run(['akengage_cid' => '5']) === ['akengage_cid' => '5'] && $run(['akengage_cid' => '77']) === ['akengage_cid' => '77'], 'cid de un comentario que existe: se conserva');
t_ok($run(['akengage_cid' => '4']) === [] && $run(['akengage_cid' => '999999999']) === [] && $run(['akengage_cid' => '0']) === [] && $run(['akengage_cid' => '-1']) === [] && $run(['akengage_cid' => '5x']) === [] && $run(['akengage_cid' => '99999999999']) === [], 'cid que no existe, 0, negativo, con texto o enorme: descartado');
t_ok($GLOBALS['T_DB']->lookups - $ant === 4, 'una consulta por clave primaria solo cuando hay akengage_cid y la forma es valida (' . ($GLOBALS['T_DB']->lookups - $ant) . ')');
t_ok($run([]) === [] && $GLOBALS['T_DB']->lookups - $ant === 4, 'sin parametros no se consulta nada');
$pg = $lf("$root/src/plugins/system/engagecache/src/Extension/Engagecache.php");
t_ok(strpos($pg, '$this->normalizeRequest($app->getInput());') < strpos($pg, '$registeredurlparams->akengage_limitstart'), 'la normalizacion ocurre ANTES de registrar los parametros que forman la clave');
t_ok(!preg_match('/\$_(GET|SERVER|REQUEST)/', $pg), 'el plugin no lee superglobales: solo el objeto de entrada de Joomla');

// onAfterRoute completo: redireccion 301 a la misma URL sin los parametros no permitidos (solo GET/HEAD)
$GLOBALS['T_APP'] = new class { public $registeredurlparams; public $input; public $redirects = []; public function get($k, $d = null) { return $d; } public function isClient($c) { return $c === 'site'; } public function getInput() { return $this->input; } public function redirect($u, $c = 303) { $this->redirects[] = [$u, $c]; } };
$ruta = new ReflectionMethod($pl, 'onAfterRoute');
$llama = function (array $q, string $metodo = 'GET', array $uriVars = []) use ($pl, $ruta) {
	$GLOBALS['T_APP'] = new class { public $registeredurlparams; public $input; public $redirects = []; public function get($k, $d = null) { return $d; } public function isClient($c) { return $c === 'site'; } public function getInput() { return $this->input; } public function redirect($u, $c = 303) { $this->redirects[] = [$u, $c]; } };
	$GLOBALS['T_APP']->input = new FakeInput($q + ['option' => 'com_content']); $GLOBALS['T_METHOD'] = $metodo;
	\Joomla\CMS\Uri\Uri::$vars = $uriVars;
	$ruta->invoke($pl, new \Joomla\Event\Event());
	return $GLOBALS['T_APP'];
};
$a = $llama(['akengage_sort' => 'x"><script>'], 'GET', ['id' => '1', 'akengage_sort' => 'x"><script>', 'p' => 'ATACANTE']);
t_ok($a->redirects === [['/index.php/articulo?id=1&p=ATACANTE', 301]], 'GET con un orden inventado: 301 a la misma URL sin akengage_sort (' . json_encode($a->redirects) . ')');
$a = $llama(['akengage_fav' => 'x'], 'GET', ['id' => '1', 'akengage_fav' => 'x', 'x' => '"><script>alert(1)</script>', 'y' => ["a\r\nSet-Cookie: z=1"]]);
t_ok(count($a->redirects) === 1 && !preg_match('/["<>\r\n \']/', $a->redirects[0][0]) && str_contains($a->redirects[0][0], '%22%3E%3Cscript%3E'), 'el destino de la redireccion lleva TODOS los valores codificados (sin comillas, angulos ni saltos de linea): ' . ($a->redirects[0][0] ?? ''));
t_ok(!isset($a->registeredurlparams->akengage_sort), 'y no sigue registrando parametros para la clave de cache tras redirigir');
$a = $llama(['akengage_sort' => 'top'], 'GET', ['akengage_sort' => 'top']);
t_ok($a->redirects === [] && isset($a->registeredurlparams->akengage_sort) && $a->registeredurlparams->akengage_sort === 'CMD', 'GET con un orden valido: sin redireccion; se registran los parametros como siempre');
$a = $llama(['akengage_sort' => 'inventado'], 'POST', ['akengage_sort' => 'inventado']);
t_ok($a->redirects === [] && $a->input->get('akengage_sort') === null, 'POST con un orden inventado: no se redirige (se pierde el cuerpo) pero el valor se ignora');
$a = $llama([], 'GET', []);
t_ok($a->redirects === [], 'sin parametros: nada que hacer');
\Joomla\CMS\Uri\Uri::$path = '//atacante.test/x';
$a = $llama(['akengage_fav' => '9'], 'GET', ['akengage_fav' => '9']);
t_ok($a->redirects === [], 'una ruta que empieza por // no se usa como destino de la redireccion (solo se ignora el valor)');
\Joomla\CMS\Uri\Uri::$path = '/index.php/articulo';

// ------------------------------------------------------------------ C) semaforo
echo "C) Semaforo de la cache con la cache global de Joomla\n";
require_once "$c/backend/src/Helper/PanelHealth.php";
$H = \Akeeba\Component\Engage\Administrator\Helper\PanelHealth::class;
$ref = new ReflectionMethod($H, 'pluginCache'); $ref->setAccessible(true);
$pl0 = ['exists' => true, 'enabled' => false, 'id' => 1];
$base = ['plugins' => ['system/cache' => $pl0, 'system/engagecache' => ['exists' => true, 'enabled' => false, 'id' => 12]], 'can_edit_plugins' => true];
$r = $ref->invoke(null, $base + ['caching' => 0]);
t_ok($r['level'] === 'ok' && $r['args']['state'] === 'nocache', 'sin ninguna cache: verde');
$r = $ref->invoke(null, $base + ['caching' => 1]);
t_ok($r['level'] === 'warn' && $r['args']['state'] === 'disabled' && $r['fix']['id'] === 12, 'caching=1 (conservadora) sin el plugin Engage Cache: ambar con enlace al plugin');
$r = $ref->invoke(null, $base + ['caching' => 2]);
t_ok($r['level'] === 'warn', 'caching=2 (progresiva): ambar');
$conEngage = $base; $conEngage['plugins']['system/engagecache']['enabled'] = true;
t_ok($ref->invoke(null, $conEngage + ['caching' => 1])['level'] === 'ok' && $ref->invoke(null, $conEngage + ['caching' => 1])['args']['state'] === 'active', 'con el plugin activo: verde');
$sinPlug = $base; $sinPlug['plugins']['system/engagecache'] = ['exists' => false];
t_ok($ref->invoke(null, $sinPlug + ['caching' => 1])['args']['state'] === 'missing', 'plugin no instalado: missing');
t_ok($ref->invoke(null, $base)['level'] === 'ok', 'sin el hecho `caching` (panel antiguo): como antes');
foreach (['en-GB', 'es-ES'] as $l) {
	$ini = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW);
	t_ok(str_contains($ini['COM_ENGAGE_PANEL_H_PLUGIN_CACHE_DISABLED'], $l === 'es-ES' ? 'Con la caché de Joomla activada, active el plugin Engage Cache para que el orden funcione.' : 'sort order'), "$l: el aviso del semaforo explica que el orden no funciona sin el plugin");
}
$pd = $lf("$c/backend/src/Helper/PanelData.php");
t_ok(str_contains($pd, "'caching'          => (int) Factory::getApplication()->get('caching', 0)"), 'PanelData entrega el hecho caching');

// ------------------------------------------------------------------ D) la clave y el HTML cacheable
echo "D) Nada de la peticion ajena llega al HTML que Joomla guarda\n";
$ctl = $lf("$c/frontend/src/Controller/CommentsController.php");
t_ok(str_contains($lf("$c/backend/src/Mixin/ControllerReturnURLTrait.php"), "toString(['scheme', 'host', 'port'])"), 'el returnurl relativo se hace absoluto con el anfitrion de la peticion que lo recibe, no con el de la que pinto la pagina');
t_fin();
}
