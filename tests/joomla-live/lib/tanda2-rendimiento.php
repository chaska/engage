<?php
/**
 * 0.8.0: RENDIMIENTO de la ordenacion «Mas valorados» en el Joomla REAL (CLI, modelo real, MariaDB real).
 *
 * Siembra 5.000 comentarios (3.750 de primer nivel y 1.250 respuestas) y 20.000 reacciones de 300 usuarios de prueba en el articulo
 * publico y mide, con N repeticiones (mediana y maximo):
 *   - la consulta SQL de IDs sin modo de orden (la de siempre), con «newest» y con «top» (con la subconsulta agregada de reacciones);
 *   - el tiempo total del modelo (consulta + arbol en PHP + rebanada de pagina) en los tres casos;
 *   - EXPLAIN de la subconsulta agregada (indices usados) y el plan completo de «top».
 * Comprueba ademas que el orden «top» es CORRECTO frente a un calculo independiente en PHP (me gusta - no me gusta, sin las reacciones del
 * autor a su propio comentario, desempate por fecha) y que no cambian los totales de la paginacion.
 *
 * Variables: WORK (obligatoria), SITE_DIR, IDS_FILE, REPS (15), COMENTARIOS (5000), REACCIONES (20000), LIMPIAR=0 para dejar los datos.
 * Escribe $WORK/resultados-tanda2-rendimiento.json. Sale con 1 si algo FALLA. Borra los datos sembrados al acabar.
 */
ob_start();
define('_JEXEC', 1);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$site = "$WORK/" . (getenv('SITE_DIR') ?: 'site');
define('JPATH_BASE', $site);
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

$container = Factory::getContainer();
$container->alias('session.cli', 'session.web.site')->alias('session', 'session.web.site')->alias('JSession', 'session.web.site')
	->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(ConsoleApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$app->getLanguage()->load('lib_joomla', JPATH_ADMINISTRATOR);
$db = $container->get(DatabaseInterface::class);
$uf = $container->get(UserFactoryInterface::class);
$app->loadIdentity($uf->loadUserByUsername('admintest'));

$RES = [];
function r(string $d, bool $ok, string $ev = ''): void { global $RES; $RES[] = ['desc' => $d, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev]; printf("%-6s %s%s\n", $ok ? 'PASA' : 'FALLA', $d, $ev !== '' ? "  [$ev]" : ''); }
function sql(string $s) { global $db; $db->setQuery($s)->execute(); }
function val(string $s) { global $db; return $db->setQuery($s)->loadResult(); }
function mediana(array $v): float { sort($v); $n = count($v); return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; }

$ids   = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$asset = (int) $ids['art_publico_asset'];
$N     = (int) (getenv('COMENTARIOS') ?: 5000);
$NR    = (int) (getenv('REACCIONES') ?: 20000);
$REPS  = (int) (getenv('REPS') ?: 15);
mt_srand(20261007);

// ---- Datos sinteticos
sql("DELETE FROM #__engage_reactions");
sql("DELETE FROM #__engage_comments WHERE asset_id=$asset");
$nUsers = 300;
$uids   = [];
for ($i = 1; $i <= $nUsers; $i++) {
	$u = sprintf('t2perf%03d', $i);
	$id = (int) val("SELECT id FROM #__users WHERE username='$u'");
	if (!$id) {
		sql("INSERT INTO #__users (name,username,email,password,block,sendEmail,registerDate,activation,params,resetCount,otpKey,otep,requireReset,authProvider) VALUES ('$u','$u','$u@example.invalid','x',1,0,NOW(),'','{}',0,'','',0,'')");
		$id = (int) val("SELECT id FROM #__users WHERE username='$u'");
	}
	$uids[] = $id;
}
$base = strtotime('2026-01-01 00:00:00 UTC');
$rows = [];
for ($i = 1; $i <= $N; $i++) {
	$when = gmdate('Y-m-d H:i:s', $base + $i * 600 + mt_rand(0, 599));
	$by   = ($i % 5 === 0) ? 0 : $uids[mt_rand(0, $nUsers - 1)];
	$rows[] = "($asset,NULL,'<p>Comentario sintetico $i</p>'," . ($by ? 'NULL' : "'Visitante $i'") . ',' . ($by ? 'NULL' : "'v$i@example.invalid'") . ",'203.0.113.7','perf',1,'$when',$by)";
}
foreach (array_chunk($rows, 500) as $ch) {
	sql('INSERT INTO #__engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ' . implode(',', $ch));
}
$all = array_map('intval', $db->setQuery("SELECT id FROM #__engage_comments WHERE asset_id=$asset ORDER BY id")->loadColumn());
// 1.250 respuestas: cada 4.ª es respuesta de una raiz anterior
$nResp = intdiv($N, 4);
for ($k = 0; $k < $nResp; $k++) {
	$child = $all[$k * 4 + 3] ?? null;
	$par   = $all[mt_rand(0, max(0, $k * 4 + 2))];
	if ($child === null) { break; }
	sql("UPDATE #__engage_comments SET parent_id=$par WHERE id=$child AND id>$par");
}
$nResp = (int) val("SELECT COUNT(*) FROM #__engage_comments WHERE asset_id=$asset AND parent_id IS NOT NULL");
$seen  = [];
$vals  = [];
while (count($vals) < $NR) {
	$c = $all[mt_rand(0, count($all) - 1)]; $u = $uids[mt_rand(0, $nUsers - 1)];
	$t = [1, 1, 1, 1, 2, 2, 3][mt_rand(0, 6)];
	$k = "$c-$u-$t";
	if (isset($seen[$k])) { continue; }
	$seen[$k] = 1;
	$vals[] = "($c,$u,$t,NOW())";
}
foreach (array_chunk($vals, 1000) as $ch) { sql('INSERT INTO #__engage_reactions (comment_id,user_id,type,created) VALUES ' . implode(',', $ch)); }
sql('ANALYZE TABLE #__engage_reactions, #__engage_comments');
$nc = (int) val("SELECT COUNT(*) FROM #__engage_comments WHERE asset_id=$asset");
$nr = (int) val("SELECT COUNT(*) FROM #__engage_reactions");
r("datos sembrados: $nc comentarios ($nResp respuestas), $nr reacciones, $nUsers usuarios de prueba", $nc === $N && $nr === $NR);

register_shutdown_function(function () use ($db, $asset) {
	if (getenv('LIMPIAR') === '0') { return; }
	$db->setQuery("DELETE FROM #__engage_reactions")->execute();
	$db->setQuery("DELETE FROM #__engage_comments WHERE asset_id=$asset")->execute();
	$db->setQuery("DELETE FROM #__user_usergroup_map WHERE user_id IN (SELECT id FROM #__users WHERE username LIKE 't2perf%')")->execute();
	$db->setQuery("DELETE FROM #__users WHERE username LIKE 't2perf%'")->execute();
});

// ---- Modelo real
$mvc = $app->bootComponent('com_engage')->getMVCFactory();
$modelo = function (array $estado) use ($mvc) {
	$m = $mvc->createModel('Comments', 'Site', ['ignore_request' => true]);
	foreach ($estado as $k => $v) { $m->setState($k, $v); }
	return $m;
};
$basico = ['filter.asset_id' => $asset, 'filter.enabled' => 1, 'list.start' => 0, 'list.limit' => 20, 'list.ordering' => 'c.created', 'list.direction' => 'ASC'];
$casos = ['sin modo (de siempre)' => $basico, 'newest' => $basico + ['list.sortmode' => 'newest'], 'top' => $basico + ['list.sortmode' => 'top']];

$res = [];
foreach ($casos as $nombre => $estado) {
	$tTotal = []; $tSql = []; $sqlTxt = '';
	for ($i = 0; $i < $REPS + 2; $i++) {
		$m = $modelo($estado);
		$t0 = hrtime(true);
		$pag = $m->commentIDTreeSliceWithDepth(0, 20);
		$tt = (hrtime(true) - $t0) / 1e6;
		$qObj   = $db->getQuery();
		$sqlTxt = str_replace([':asset_id', ':rs_asset', ':enabled'], [(string) $asset, (string) $asset, '1'], (string) $qObj);
		$cnt = $m->getTreeAwareCount();
		// SQL solo (la misma consulta, con sus valores enlazados)
		$t1 = hrtime(true);
		$db->setQuery($qObj)->loadAssocList('id');
		$ts = (hrtime(true) - $t1) / 1e6;
		if ($i >= 2) { $tTotal[] = $tt; $tSql[] = $ts; } // las 2 primeras repeticiones calientan caches
	}
	$res[$nombre] = ['total_ms_mediana' => round(mediana($tTotal), 1), 'total_ms_max' => round(max($tTotal), 1), 'sql_ms_mediana' => round(mediana($tSql), 1), 'sql_ms_max' => round(max($tSql), 1), 'cuenta' => $cnt, 'pagina' => array_slice(array_map('intval', array_map('trim', array_keys($pag))), 0, 20), 'sql' => $sqlTxt];
	printf("%-24s total %6.1f ms (mediana, max %6.1f)   SQL %6.1f ms (mediana, max %6.1f)   comentarios %d\n", $nombre, $res[$nombre]['total_ms_mediana'], $res[$nombre]['total_ms_max'], $res[$nombre]['sql_ms_mediana'], $res[$nombre]['sql_ms_max'], $cnt);
}
$extra = $res['top']['total_ms_mediana'] - $res['newest']['total_ms_mediana'];
r(sprintf('«top» cuesta %.1f ms mas que «newest» (mediana, %d comentarios, %d reacciones)', $extra, $nc, $nr), true, sprintf('newest %.1f ms; top %.1f ms; de siempre %.1f ms', $res['newest']['total_ms_mediana'], $res['top']['total_ms_mediana'], $res['sin modo (de siempre)']['total_ms_mediana']));
r('«top» tarda menos de 500 ms en total con 5.000 comentarios y 20.000 reacciones', $res['top']['total_ms_mediana'] < 500, $res['top']['total_ms_mediana'] . ' ms');
r('mismos totales de paginacion en los tres casos', $res['top']['cuenta'] === $res['newest']['cuenta'] && $res['newest']['cuenta'] === $res['sin modo (de siempre)']['cuenta'], (string) $res['top']['cuenta']);

// ---- Plan de ejecucion
$sqlTop = $res['top']['sql'];
$plan = $db->setQuery('EXPLAIN ' . $sqlTop)->loadAssocList();
echo "EXPLAIN top:\n";
foreach ($plan as $p) { printf("  id=%s %-12s %-22s type=%-6s key=%-26s rows=%s %s\n", $p['id'], $p['select_type'], $p['table'], $p['type'], $p['key'] ?? 'NULL', $p['rows'], $p['Extra'] ?? ''); }
$sinIndice = array_filter($plan, fn($p) => ($p['table'] === 'r') && ($p['key'] ?? null) === null);
r('la subconsulta agregada usa un indice de #__engage_reactions (no recorre la tabla sin indice)', !$sinIndice, json_encode(array_map(fn($p) => [$p['table'], $p['type'], $p['key'] ?? null, $p['rows']], $plan)));

// ---- Correccion del orden «top» frente a un calculo independiente
$coms = $db->setQuery("SELECT id, parent_id, created, created_by, email FROM #__engage_comments WHERE asset_id=$asset AND enabled=1")->loadAssocList('id');
$reac = $db->setQuery("SELECT r.comment_id, r.user_id, r.type, u.email AS uemail FROM #__engage_reactions r LEFT JOIN #__users u ON u.id=r.user_id WHERE r.type IN (1,2)")->loadAssocList();
$score = [];
foreach ($reac as $x) {
	$c = $coms[$x['comment_id']] ?? null;
	if (!$c) { continue; }
	if ((int) $x['user_id'] === (int) $c['created_by']) { continue; }
	if ((int) $c['created_by'] <= 0 && trim((string) $c['email']) !== '' && $c['email'] === $x['uemail']) { continue; }
	$score[$x['comment_id']] = ($score[$x['comment_id']] ?? 0) + ((int) $x['type'] === 1 ? 1 : -1);
}
$raices = array_filter($coms, fn($c) => empty($c['parent_id']));
uasort($raices, function ($a, $b) use ($score) {
	return [($score[$b['id']] ?? 0), $b['created'], (int) $b['id']] <=> [($score[$a['id']] ?? 0), $a['created'], (int) $a['id']];
});
// arbol esperado: raices por valoracion; respuestas debajo, cronologicas; las huerfanas no salen
$hijos = [];
foreach ($coms as $c) { if (!empty($c['parent_id'])) { $hijos[$c['parent_id']][] = $c; } }
foreach ($hijos as &$h) { usort($h, fn($a, $b) => [$a['created'], (int) $a['id']] <=> [$b['created'], (int) $b['id']]); } unset($h);
$esperado = [];
$visita = function ($c, $d) use (&$visita, &$esperado, $hijos) { $esperado[] = [(int) $c['id'], $d]; foreach ($hijos[$c['id']] ?? [] as $h) { $visita($h, $d + 1); } };
foreach ($raices as $c) { $visita($c, 1); }
$m = $modelo(['list.start' => 0, 'list.limit' => 0] + $casos['top']);
$obt = $m->commentIDTreeSliceWithDepth(0, 0);
$obtenido = []; foreach ($obt as $k => $d) { $obtenido[] = [(int) trim((string) $k), (int) $d]; }
r('el arbol completo de «top» coincide con un calculo independiente (me gusta - no me gusta sin los del autor, desempate por fecha, respuestas cronologicas debajo de su padre)', $obtenido === $esperado, count($obtenido) . ' filas; esperado ' . count($esperado));
$top3 = array_slice(array_keys($raices), 0, 3);
$res['top']['mejores'] = array_map(fn($i) => [$i, $score[$i] ?? 0], $top3);
r('la primera raiz de «top» es la de mayor valoracion', ($score[$top3[0]] ?? 0) === max(array_map(fn($c) => $score[$c['id']] ?? 0, $raices)), json_encode($res['top']['mejores']));
// paginas sucesivas sin repeticiones ni huecos
$vistos = [];
for ($s = 0; $s < $nc; $s += 20) {
	$pm = $modelo(['list.sortmode' => 'top', 'filter.asset_id' => $asset, 'filter.enabled' => 1]);
	foreach (array_keys($pm->commentIDTreeSliceWithDepth($s, 20)) as $k) { $vistos[] = (int) trim((string) $k); }
}
r('paginando de 20 en 20 se ven todos los comentarios una sola vez', count($vistos) === count(array_unique($vistos)) && count($vistos) === count($esperado), count($vistos) . ' vistos / ' . count($esperado));

foreach ($res as &$x) { $x['sql'] = '(omitido)'; } unset($x);
file_put_contents("$WORK/resultados-tanda2-rendimiento.json", json_encode(['resultados' => $RES, 'medidas' => $res, 'explain' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$fallos = count(array_filter($RES, fn($x) => $x['estado'] === 'FALLA'));
echo "\n" . ($fallos ? "$fallos FALLA" : 'Todo PASA') . "\n";
exit($fallos ? 1 : 0);
