<?php
/**
 * 0.6.9: topes de akengage_limit / akengage_limitstart (PS-13). ListLimits con datos de ataque y comprobación de que
 * el controlador del listado los usa y no divide por cero con el límite 0.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src/component';
require "$root/backend/src/Helper/ListLimits.php";
use Akeeba\Component\Engage\Administrator\Helper\ListLimits as L;

echo "A) limit()\n";
t_ok(L::MAX_LIMIT === 500, 'el máximo es 500 (la mayor opción de default_limit en config.xml)');
$cfg = file_get_contents("$root/backend/config.xml");
preg_match_all('/<option value="(\d+)">J\d+<\/option>/', $cfg, $m);
t_ok(max($m[1]) == L::MAX_LIMIT, 'coincide con la mayor opción numérica de config.xml: ' . max($m[1]));
foreach ([[20, 20, 20], ['50', 20, 50], [500, 20, 500], ['500', 20, 500], [501, 20, 500], ['999999999', 20, 500], [PHP_INT_MAX, 20, 500],
	['99999999999999999999', 20, 20], [0, 20, 20], ['0', 20, 20], [-1, 20, 20], ['-5', 20, 20], ['abc', 20, 20], ['', 20, 20], [null, 20, 20],
	[['1000'], 20, 20], [1.5, 20, 20], ['1e9', 20, 20], ['10; DROP', 20, 20], [true, 20, 20], [new stdClass, 20, 20], [0, 0, 0], [-1, -3, 0], [5, 9999, 5], [0, 9999, 500]] as [$v, $d, $esp]) {
	t_ok(L::limit($v, $d) === $esp, 'limit(' . json_encode($v) . ", $d) = $esp");
}
echo "B) start()\n";
foreach ([[0, 0], ['40', 40], [-1, 0], ['-1', 0], ['abc', 0], [null, 0], [[5], 0], [1.5, 0], ['99999999999999999999', 0], [PHP_INT_MAX, L::MAX_START], ['999999999', L::MAX_START], [L::MAX_START, L::MAX_START]] as [$v, $esp]) {
	t_ok(L::start($v) === $esp, 'start(' . json_encode($v) . ") = $esp");
}
echo "C) Controlador\n";
$c = file_get_contents("$root/frontend/src/Controller/CommentsController.php");
t_ok(str_contains($c, "ListLimits::limit(") && str_contains($c, "ListLimits::start("), 'CommentsController usa ListLimits para limit y limitstart');
t_ok(!preg_match("/\\\$(limit|start)\s*=\s*\\\$this->app->getUserStateFromRequest/", $c), 'ya no se usa el valor de la petición sin pasar por ListLimits');
t_ok(!preg_match('/intdiv\(\$index,\s*\$limit\)\s*\*\s*\$limit;/', $c) || str_contains($c, '($limit > 0)'), 'intdiv protegido contra límite 0 (división por cero)');
t_fin();
