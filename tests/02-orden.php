<?php
/**
 * 0.4.0: lista blanca de columna y dirección de ordenación (S1 del informe).
 * Parte A: ListOrdering con datos de ataque. Parte B: CommentsModel::getListQuery real (con stubs de Joomla)
 * genera siempre un ORDER BY seguro.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__) . '/src/component';
require "$root/backend/src/Helper/ListOrdering.php";
use Akeeba\Component\Engage\Administrator\Helper\ListOrdering as L;

echo "A) ListOrdering\n";
$ataques = ['u.password', 'u.email', 'u.params', 'c.created; DROP TABLE x', 'c.created`, (SELECT 1)', "c.id\0", 'c.created -- ', '', ' c.created', 'C.CREATED', 'a.title', 'cat.params', 'c.*', '1', 'RAND()', 'c.id,u.password', 'c.id DESC', null, ['u.password'], 123, 1.5, true, new stdClass];
foreach ($ataques as $a) {
	$r = L::column($a);
	t_ok($r === 'c.created', 'column(' . json_encode($a) . ") -> $r (por defecto)");
	$r = L::frontendColumn($a);
	t_ok($r === 'c.created', 'frontendColumn(' . json_encode($a) . ") -> $r (por defecto)");
}
foreach (['c.id', 'c.created', 'c.enabled', 'user_name', 'c.name'] as $v) { t_ok(L::column($v) === $v, "column($v) se conserva"); }
foreach (['id' => 'c.id', 'created' => 'c.created', 'enabled' => 'c.enabled', 'name' => 'c.name', 'email' => 'c.email'] as $k => $v) { t_ok(L::column($k) === $v, "column($k) -> $v"); }
t_ok(L::frontendColumn('c.id') === 'c.id' && L::frontendColumn('c.created') === 'c.created', 'frontendColumn admite c.id y c.created');
t_ok(L::frontendColumn('user_name') === 'c.created' && L::frontendColumn('c.enabled') === 'c.created', 'frontendColumn rechaza user_name y c.enabled');
foreach (['asc' => 'ASC', 'ASC' => 'ASC', 'DESC' => 'DESC'] as $k => $v) { t_ok(L::direction($k) === $v, "direction('$k') -> $v"); }
foreach ([' asc ', 'ASC; DROP TABLE x', 'ASC, u.password', 'DESC--', '', 'RAND()', '1', "ASC\0", null, [], 5, false, 'ASCENDING', "ASC\n,(SELECT 1)"] as $d) {
	t_ok(L::direction($d) === 'DESC', 'direction(' . json_encode($d) . ') -> DESC');
}

echo "B) CommentsModel::getListQuery (real, con stubs)\n";
require "$root/backend/src/Mixin/ModelPopulateStateTrait.php";
require "$root/backend/src/Model/CommentsModel.php";
$rc = new ReflectionClass(\Akeeba\Component\Engage\Administrator\Model\CommentsModel::class);
$gl = $rc->getMethod('getListQuery'); $gl->setAccessible(true);
$casos = [
	['u.password', 'ASC', '`c`.`created` ASC, `c`.`id` DESC'],
	['c.created; DROP TABLE x', 'DESC; DROP', '`c`.`created` DESC, `c`.`id` DESC'],
	['c.id', 'asc', '`c`.`id` ASC'],
	['user_name', 'DESC', '`user_name` DESC, `c`.`id` DESC'],
	['created', 'ASC', '`c`.`created` ASC, `c`.`id` DESC'],
	[['x'], ['y'], '`c`.`created` DESC, `c`.`id` DESC'],
	[null, null, '`c`.`created` DESC, `c`.`id` DESC'],
];
foreach ($casos as [$col, $dir, $esperado]) {
	$m = $rc->newInstanceWithoutConstructor();
	$m->state = new FakeState(); $m->db = new FakeDb();
	$m->state->set('list.ordering', $col); $m->state->set('list.direction', $dir);
	$q = $gl->invoke($m);
	t_ok(($q->order[0] ?? null) === $esperado, 'ORDER BY ' . ($q->order[0] ?? '(ninguno)') . '   <- ' . json_encode([$col, $dir]));
	t_ok(!str_contains($q->order[0], 'password') && !str_contains($q->order[0], 'DROP'), '   sin password ni DROP');
}
t_fin();
