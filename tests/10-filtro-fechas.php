<?php
/**
 * 0.5.4: el filtro de fechas de CommentsModel y el plugin Akismet usaban la columna/propiedad inexistente
 * `created_on` (la columna real de #__engage_comments se llama `created`, desde 3.0.0).
 * A) Esquema real: la tabla tiene `created` y no `created_on`.
 * B) CommentsModel::getListQuery real (con stubs): las cláusulas WHERE de fechas usan `c.created`, con parámetros enlazados.
 * C) Ningún PHP de src (sin vendor) vuelve a usar `created_on` como columna/propiedad.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__) . '/src';

echo "A) Esquema SQL\n";
$sql = file_get_contents("$root/component/backend/sql/install.mysql.utf8.sql");
t_ok(str_contains($sql, '`created`     datetime'), 'install.mysql.utf8.sql define la columna `created`');
t_ok(!preg_match('/^\s*`created_on`/m', $sql), 'no existe una columna `created_on`');

echo "B) CommentsModel::getListQuery\n";
require "$root/component/backend/src/Helper/ListOrdering.php";
require "$root/component/backend/src/Mixin/ModelPopulateStateTrait.php";
require "$root/component/backend/src/Model/CommentsModel.php";
$rc = new ReflectionClass(\Akeeba\Component\Engage\Administrator\Model\CommentsModel::class);
$gl = $rc->getMethod('getListQuery'); $gl->setAccessible(true);
foreach ([['2026-01-01 00:00:00', null], ['2026-01-01 00:00:00', '2026-02-01 00:00:00']] as [$from, $to]) {
	$m = $rc->newInstanceWithoutConstructor();
	$m->state = new FakeState(); $m->db = new FakeDb();
	$m->state->set('filter.from', $from); $m->state->set('filter.to', $to);
	$q = $gl->invoke($m);
	$fechas = array_values(array_filter($q->where, fn($w) => str_contains($w, ':from') || str_contains($w, ':to')));
	t_ok(count($fechas) === 1, 'una cláusula de fechas para ' . json_encode([$from, $to]) . ': ' . ($fechas[0] ?? '(ninguna)'));
	t_ok(isset($fechas[0]) && str_contains($fechas[0], '`c`.`created`') && !str_contains($fechas[0], 'created_on'), '   usa `c`.`created`, no created_on');
}

echo "B2) 0.6.12: 'hasta' solo, y desde > hasta (se intercambian) sin avisos de PHP\n";
set_error_handler(function ($n, $m) { throw new ErrorException($m, 0, $n); });
foreach ([[null, '2026-02-01 00:00:00', '<='], ['2026-03-01 00:00:00', '2026-02-01 00:00:00', 'BETWEEN'], ['2026-01-01 00:00:00', '2026-02-01 00:00:00', 'BETWEEN']] as [$from, $to, $op]) {
	$m = $rc->newInstanceWithoutConstructor();
	$m->state = new FakeState(); $m->db = new FakeDb();
	$m->state->set('filter.from', $from); $m->state->set('filter.to', $to);
	try {
		$q = $gl->invoke($m);
		$fechas = array_values(array_filter($q->where, fn($w) => str_contains($w, ':from') || str_contains($w, ':to')));
		t_ok(count($fechas) === 1 && str_contains($fechas[0], $op), 'desde=' . json_encode($from) . ' hasta=' . json_encode($to) . ' -> ' . ($fechas[0] ?? '(ninguna)'));
	} catch (ErrorException $e) { t_ok(false, 'aviso PHP: ' . $e->getMessage()); }
}
restore_error_handler();

echo "C) Sin created_on en el código PHP\n";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$malos = [];
foreach ($it as $f) {
	$p = $f->getPathname();
	if (!str_ends_with($p, '.php') || str_contains($p, '/vendor/')) { continue; }
	if (preg_match('/created_on/', file_get_contents($p))) { $malos[] = substr($p, strlen($root) + 1); }
}
t_ok($malos === [], 'ningún .php usa created_on' . ($malos ? ': ' . implode(', ', $malos) : ''));
t_fin();
