<?php
/**
 * 0.8.3 (H6, H7): la ficha de un activo.
 *  H7: Meta::getAssetAccessMeta ya no toma «la primera ficha que llega»: un articulo (`com_content.article.N`) solo lo describe una ficha `article`;
 *      el resto de activos tienen que existir en #__assets con nombre `com_<componente>.…` y una ficha con `type` coherente; dos fichas validas = ambiguo = cerrado.
 *  H6: ReactionsController::canView usa Meta::isAssetViewable (fail-closed) para lo que no es un articulo, como el hilo.
 * Ejecuta el codigo REAL de Meta y del controlador con los stubs de tests/stubs.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/otros-componentes.php';
eval('namespace Joomla\CMS\MVC\Controller { class BaseController {} }');

use Akeeba\Component\Engage\Site\Controller\ReactionsController;
use Akeeba\Component\Engage\Site\Helper\Meta;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

$root = dirname(__DIR__);
require_once $root . '/src/component/frontend/src/Helper/Meta.php';
foreach (['Akeeba\Component\Engage\Administrator\Helper\ReactionRateLimiter', 'Akeeba\Component\Engage\Administrator\Helper\Reactions', 'Akeeba\Component\Engage\Administrator\Helper\ReactionStore'] as $c) { if (!class_exists($c)) { eval('namespace ' . substr($c, 0, strrpos($c, '\\')) . '; class ' . substr($c, strrpos($c, '\\') + 1) . ' {}'); } }
require_once $root . '/src/component/frontend/src/Controller/ReactionsController.php';

$GLOBALS['T_USER'] = new User();
$bueno = fn(array $o = []): array => array_replace([
	'type' => 'viaje', 'title' => 'Ruta', 'category' => 'Viajes', 'author_name' => 'Ana', 'author_email' => 'ana@example.test', 'url' => 'index.php?x=1', 'public_url' => 'https://sitio.test/r',
	'published' => true, 'published_on' => new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC')), 'access' => 1, 'parent_access' => 1, 'category_published' => 1,
	'parameters' => new Registry(['comments_enabled' => 1]),
], $o);
$sig = 5000;
/** $nombre: nombre en #__assets (null = no existe); $plugins: lista de respuestas (arrays o null), una por plugin */
$ver = function (?string $nombre, array $plugins) use (&$sig): array {
	$id = ++$sig;
	Meta::$assetNameResolver = fn(int $a) => ($a === $id) ? $nombre : null;
	$GLOBALS['T_PLUGINS_MULTI'] = array_map(fn($r) => fn(int $a, bool $c) => ($a === $id) ? $r : null, $plugins);

	return [Meta::getAssetAccessMeta($id, true), $id];
};
$tipo = fn(array $m) => $m['type'];

echo "A) assetFits\n";
foreach ([['article', 'com_content.article.12', true], ['viaje', 'com_viajes.ruta.3', true], ['viajes', 'com_viajes.ruta.3', true], ['mapaviajes', 'com_mapa.punto.3', true], ['mapa', 'com_mapaviajes.punto.3', true],
	['tercero', 'com_tercero.item.9', true], ['viaje', 'com_mapa.item.1', false], ['article', 'com_viajes.ruta.3', false], ['viaje', 'com_content.article.12', false], ['article', 'com_content.category.4', false],
	['content', 'com_content.category.4', false], ['viaje', 'com_content.featured.1', false], ['engage', 'com_engage.comment.1', false], ['viaje', 'root.1', false], ['viaje', null, false], ['viaje', '', false],
	['v', 'com_viajes.ruta.3', false], ['vi', 'com_viajes.ruta.3', false], ['viaje', 'com_viajes', false], ['viaje', 'COM_VIAJES.ruta.3', false], ['unknown', 'com_unknown.x.1', false],
	['article', 'com_content.article.0', false], ['article', 'com_content.article.12x', false], ['article', "com_content.article.12\n", false]] as [$t, $n, $esp]) {
	t_ok(Meta::assetFits($t, $n) === $esp, "assetFits($t, " . json_encode($n) . ") = " . json_encode($esp));
}

echo "B) Varias fichas para el mismo activo\n";
[$m] = $ver('com_content.article.7', [$bueno(['type' => 'viaje', 'access' => 1]), $bueno(['type' => 'article', 'access' => 2])]);
t_ok($tipo($m) === 'article' && $m['access'] === 2, 'un articulo: manda la ficha `article`, aunque otro plugin conteste antes con otra de acceso libre');
[$m] = $ver('com_content.article.7', [$bueno(['type' => 'viaje', 'access' => 1])]);
t_ok($tipo($m) === 'unknown', 'un articulo descrito SOLO por un plugin de terceros: desconocido');
[$m] = $ver('com_content.article.7', [$bueno(['type' => 'article', 'access' => 2]), $bueno(['type' => 'article', 'access' => 1])]);
t_ok($tipo($m) === 'unknown', 'dos fichas `article` para el mismo articulo: ambiguo = desconocido (fail-closed)');
[$m] = $ver('com_viajes.ruta.3', [$bueno(['type' => 'article'])]);
t_ok($tipo($m) === 'unknown', 'un plugin que se hace pasar por `article` en un activo de otro componente: desconocido');
[$m] = $ver('com_viajes.ruta.3', [$bueno(['type' => 'mapa'])]);
t_ok($tipo($m) === 'unknown', 'tipo incoherente con el componente del activo: desconocido');
[$m] = $ver('com_viajes.ruta.3', [null, $bueno(['type' => 'mapa']), $bueno()]);
t_ok($tipo($m) === 'viaje', 'entre fichas nulas e incoherentes se queda con la unica valida');
[$m] = $ver('com_viajes.ruta.3', [$bueno(), $bueno(['access' => 1, 'title' => 'otra'])]);
t_ok($tipo($m) === 'unknown', 'dos fichas validas distintas: ambiguo = desconocido');
[$m] = $ver(null, [$bueno()]);
t_ok($tipo($m) === 'unknown', 'el activo no existe en #__assets: desconocido (antes: se aceptaba cualquier numero)');
[$m] = $ver('com_content.category.4', [$bueno(['type' => 'article'])]);
t_ok($tipo($m) === 'unknown', 'activo de una categoria de com_content con ficha `article`: desconocido');
[$m] = $ver('com_viajes.ruta.3', [$bueno()]);
t_ok($tipo($m) === 'viaje' && $m['published'] === true, 'el caso normal sigue funcionando');
[$m] = $ver('com_viajes.ruta.3', ['texto', 7, null, [], $bueno()]);
t_ok($tipo($m) === 'viaje', 'basura entre las respuestas (texto, numeros, matrices vacias): se ignora');

echo "C) H6: ReactionsController::canView = la misma regla que el hilo\n";
$usr = function (array $niveles, bool $mod = false) { $u = new class extends User { public bool $mod = false; public function authorise($a, $b = null) { return $this->mod && $a === 'core.edit.state'; } }; $u->levels = $niveles; $u->mod = $mod; return $u; };
$casos = [
	['ficha sin `access`', $bueno(['access' => null]), false], ['access 0', $bueno(['access' => 0]), false], ['access texto', $bueno(['access' => 'x']), false],
	['access de otro nivel', $bueno(['access' => 5]), false], ['parent_access que no tiene', $bueno(['parent_access' => 6]), false], ['sin publicar', $bueno(['published' => false]), false],
	['categoria en la papelera', $bueno(['category_published' => -2]), false], ['todo en regla', $bueno(), true],
];
foreach ($casos as [$n, $ficha, $esp]) {
	[$m, $id] = $ver('com_viajes.ruta.3', [$ficha]);
	$hilo = Meta::isAssetViewable($m, $usr([1, 2]));
	t_ok(ReactionsController::canView($usr([1, 2]), $id) === $esp && $hilo === $esp, "$n: reacciones = hilo = " . json_encode($esp));
}
[$m, $id] = $ver('com_viajes.ruta.3', [$bueno(['published' => false])]);
t_ok(ReactionsController::canView($usr([1, 2], true), $id) === false, 'un moderador tampoco reacciona en un elemento de otro componente sin publicar (el hilo no se lo muestra)');
[$m, $id] = $ver('com_content.article.9', [$bueno(['type' => 'article', 'published' => false])]);
t_ok(ReactionsController::canView($usr([1, 2], true), $id) === true && ReactionsController::canView($usr([1, 2], false), $id) === false, 'el camino de los articulos no cambia (moderador si, el resto no)');
t_fin();
