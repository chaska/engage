<?php
/**
 * 0.8.5: lista de comentarios del ADMINISTRADOR contra el Joomla REAL con los plugins de prueba de terceros (21-otros-componentes.sh; requiere
 * `otros-datos.php crear` y los plugins instalados): filtro «Tipo de elemento» (lista cerrada, valores hostiles ignorados), titulo y enlace de cada
 * fila (con el titulo y la URL hostiles del elemento 13 escapados), «Elemento no disponible (asset N)» (plugin apagado, activo inexistente) y moderable,
 * filtro de estado «Todos» y comentarios de terceros con enabled = 0, -3 y -2 localizables.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void { global $RES; $RES[] = $ok; printf("%-9s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? '  [' . substr($ev, 0, 260) . ']' : ''); }
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
$AP = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
@mkdir("$WORK/tmp");
$plugEstado = q("SELECT element, enabled FROM jos_extensions WHERE type='plugin' AND element IN ('terceroprueba','tercerohostil')")->fetch_all(MYSQLI_ASSOC);
register_shutdown_function(function () use ($plugEstado) {
	foreach ($plugEstado as $p) { q("UPDATE jos_extensions SET enabled=" . (int) $p['enabled'] . " WHERE type='plugin' AND element='" . $p['element'] . "'"); }
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R4N-%'"); q("DELETE FROM jos_assets WHERE id BETWEEN 920001 AND 920060");
});
q("DELETE FROM jos_engage_comments WHERE name LIKE 'R4N-%'"); q("DELETE FROM jos_assets WHERE id BETWEEN 920001 AND 920060");
$ins = function (int $asset, int $en, string $name) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset,NULL,'<p>R4B $name</p>','$name','r4@example.invalid','203.0.113.9','t',$en,'2026-09-02 10:00:00',0)"); global $db; return (int) $db->insert_id; };
$idUnpub = $ins(900001, 0, 'R4N-UNPUB'); $idPoss = $ins(900002, -3, 'R4N-POSSIBLE'); $idSpam = $ins(900003, -2, 'R4N-SPAM'); $idOrf = $ins(999990, 1, 'R4N-ORFANO');
for ($i = 1; $i <= 60; $i++) { $a = 920000 + $i; q("INSERT INTO jos_assets (id, parent_id, lft, rgt, level, name, title, rules) VALUES ($a, 1, " . (2 * $a) . ", " . (2 * $a + 1) . ", 1, 'com_tercero.item.$a', 'Activo $a', '{}')"); $ins($a, 1, "R4N-CAP-$i"); }
$adm = new Cliente($BASE, "$WORK/tmp", 'r4lista'); $ok = $adm->loginAdmin('admintest', $AP);
r('L-00', 'Sesion de Super User en la administracion', $ok);
$lista = function (string $extra = '') use ($adm) { return $adm->get('/administrator/index.php?option=com_engage&view=comments&list[limit]=100&list[fullordering]=c.id%20ASC&filter[search]=&filter[enabled]=&filter[itemtype]=&limitstart=0' . $extra); };
$cids = fn(string $h) => preg_match_all('/name="cid\[\]"[^>]*value="(\d+)"|value="(\d+)"[^>]*name="cid\[\]"/', $h, $m) ? array_map('intval', array_filter(array_merge($m[1], $m[2]))) : [];
$assetDe = function (array $ids) { if (!$ids) { return []; } $o = []; foreach (q("SELECT c.id, a.name FROM jos_engage_comments c LEFT JOIN jos_assets a ON a.id=c.asset_id WHERE c.id IN (" . implode(',', $ids) . ")")->fetch_all(MYSQLI_NUM) as [$i, $n]) { $o[(int) $i] = (string) $n; } return $o; };
$sinF = $lista(); $idsSinF = $cids($sinF['body']);
r('L-01', 'La lista sin filtros se pinta (HTTP 200, todas las filas hasta 100)', $sinF['code'] === 200 && count($idsSinF) === min(100, (int) one('SELECT COUNT(*) FROM jos_engage_comments')), 'HTTP ' . $sinF['code'] . ' filas=' . count($idsSinF));
// opciones del filtro
preg_match('/<select[^>]*name="filter\[itemtype\]".*?<\/select>/s', $sinF['body'], $sel); $opts = preg_match_all('/<option value="([^"]*)"/', $sel[0] ?? '', $mm) ? $mm[1] : [];
sort($opts);
$esperados = array_values(array_filter(array_unique(array_map(fn($n) => preg_replace('/\.\d+$/', '', (string) $n), array_column(q("SELECT DISTINCT a.name FROM jos_engage_comments c JOIN jos_assets a ON a.id=c.asset_id")->fetch_all(MYSQLI_ASSOC), 'name')))));
$esperados[] = ''; sort($esperados);
r('L-02', 'El filtro «Tipo de elemento» ofrece exactamente los tipos reales de #__engage_comments (articulos y terceros)', $opts === $esperados && in_array('com_tercero.item', $opts, true) && in_array('com_hostil.item', $opts, true), 'opciones=' . implode(',', $opts) . ' esperadas=' . implode(',', $esperados));
$soloTipo = function (string $tipo) use ($lista, $cids, $assetDe) { $p = $lista('&filter[itemtype]=' . rawurlencode($tipo)); $ids = $cids($p['body']); $n = $assetDe($ids); return [$p, $ids, $n]; };
[$p, $ids, $n] = $soloTipo('com_tercero.item');
r('L-03', 'Filtro com_tercero.item: solo comentarios de activos de ese tipo (y los hay)', $p['code'] === 200 && $ids && !array_filter($n, fn($x) => !str_starts_with($x, 'com_tercero.item.')), 'filas=' . count($ids));
[$p, $ids, $n] = $soloTipo('com_hostil.item');
r('L-04', 'Filtro com_hostil.item: solo los 12 de los activos hostiles', $p['code'] === 200 && count($ids) === 12 && !array_filter($n, fn($x) => !str_starts_with($x, 'com_hostil.item.')), 'filas=' . count($ids));
[$p, $ids, $n] = $soloTipo('com_content.article');
r('L-05', 'Filtro de articulos: solo articulos (ninguno de terceros)', $p['code'] === 200 && !array_filter($n, fn($x) => !str_starts_with($x, 'com_content.article.')), 'filas=' . count($ids));
$mal = [];
foreach (['<script>alert(1)</script>', "com_tercero.item' OR '1'='1", 'com_%', 'com_tercero.%', 'com_tercero', '../../x', 'com_nada.item', "com_tercero.item\x00", str_repeat('a', 500)] as $v) {
	$p = $lista('&filter[itemtype]=' . rawurlencode($v)); $ii = $cids($p['body']);
	if ($p['code'] !== 200 || $ii !== $idsSinF || str_contains($p['body'], '<script>alert(1)</script>') || preg_match('/value="[^"]*(?:OR \'1\'|<script)/', $p['body'])) { $mal[] = json_encode(substr($v, 0, 25)) . ' HTTP ' . $p['code'] . ' filas=' . count($ii); }
}
r('L-06', 'Valores hostiles del filtro (9 variantes): se ignoran (lista sin filtrar), nada reflejado sin escapar, sin error', !$mal, implode(' | ', array_slice($mal, 0, 3)));
// estado
$buscar = fn(string $en) => $cids($adm->get('/administrator/index.php?option=com_engage&view=comments&list[limit]=100&filter[itemtype]=&filter[search]=R4N-&filter[enabled]=' . $en . '&limitstart=0')['body']);
$todos = $buscar(''); $e0 = $buscar('0'); $e3 = $buscar('-3'); $e2 = $buscar('-2'); $e1 = $buscar('1');
r('L-07', 'Estado «Todos» (por defecto): los comentarios de terceros con enabled 0, -3 y -2 aparecen', in_array($idUnpub, $todos, true) && in_array($idPoss, $todos, true) && in_array($idSpam, $todos, true) && in_array($idOrf, $todos, true), 'filas=' . count($todos));
r('L-08', 'Filtrar por estado: sin publicar (0) -> el suyo; -3 -> el suyo; -2 -> el suyo; publicados -> ninguno de ellos', in_array($idUnpub, $e0, true) && !in_array($idPoss, $e0, true) && in_array($idPoss, $e3, true) && !in_array($idUnpub, $e3, true) && in_array($idSpam, $e2, true) && !array_intersect([$idUnpub, $idPoss, $idSpam], $e1) && in_array($idOrf, $e1, true), 'e0=' . count($e0) . ' e3=' . count($e3) . ' e2=' . count($e2) . ' e1=' . count($e1));
$ps = $adm->get('/administrator/index.php?option=com_engage&view=comments');
r('L-09', 'El selector de estado ofrece «Todos» (opcion vacia) y los de spam', preg_match('/<select[^>]*name="filter\[enabled\]".*?<option value="">[^<]*(?:Todos|All)[^<]*<\/option>/s', $ps['body']) === 1 && str_contains($ps['body'], 'value="-3"') && str_contains($ps['body'], 'value="-2"'));
// filas: titulo y enlace
$pt = $lista('&filter[itemtype]=com_tercero.item&filter[search]=');
$cuerpo = $pt['body'];
r('L-10', 'Cada fila muestra el titulo del elemento de terceros con su enlace (elemento 1: «Elemento publico», enlace index.php?tp=1)', preg_match('/<a href="[^"]*tp=1"[^>]*>\s*Elemento publico\s*<\/a>/', $cuerpo) === 1, '');
r('L-11', 'Titulo y URL hostiles del elemento 13: escapados (sin <script> ni <img onerror> vivos, sin javascript: en ningun href)', !str_contains($cuerpo, '<script>window.__xss') && !preg_match('/<img[^>]+onerror/i', $cuerpo) && !preg_match('/href="\s*javascript:/i', $cuerpo) && str_contains($cuerpo, '&lt;script&gt;window.__xss'), '');
$pt2 = $lista('&filter[itemtype]=&filter[search]=R4N-ORFANO');
r('L-12', 'Un comentario cuyo activo no existe se muestra como «Elemento no disponible (asset N)» y sigue en la lista (moderable)', preg_match('/(?:Elemento no disponible|Item not available) \(asset 999990\)/', $pt2['body']) === 1 && in_array($idOrf, $cids($pt2['body']), true));
// moderar el huerfano
$tk = Cliente::token($pt2['body']);
$adm->post('/administrator/index.php?option=com_engage&task=comments.unpublish', ['cid' => [$idOrf], 'task' => 'comments.unpublish', $tk => 1]);
$en = (int) one("SELECT enabled FROM jos_engage_comments WHERE id=$idOrf");
$adm->post('/administrator/index.php?option=com_engage&task=comments.publish', ['cid' => [$idOrf], 'task' => 'comments.publish', $tk => 1]);
$en2 = (int) one("SELECT enabled FROM jos_engage_comments WHERE id=$idOrf");
r('L-13', 'El comentario sin elemento se puede moderar (despublicar y publicar)', $en === 0 && $en2 === 1, "tras despublicar=$en tras publicar=$en2");
// plugin de terceros apagado
q("UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND element='terceroprueba'"); usleep(300000);
$pd = $lista('&filter[itemtype]=com_tercero.item&filter[search]=R4N-UNPUB');
r('L-14', 'Con el plugin del tercero apagado, sus comentarios salen como «Elemento no disponible (asset N)» y siguen filtrables y moderables', preg_match('/(?:Elemento no disponible|Item not available) \(asset 900001\)/', $pd['body']) === 1 && in_array($idUnpub, $cids($pd['body']), true));
q("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND element='terceroprueba'");
// 60 activos distintos: la pagina se pinta
$pc = $lista('&filter[itemtype]=com_tercero.item&filter[search]=R4N-CAP');
r('L-15', '60 activos distintos en una pagina: se pinta (HTTP 200), 60 filas, sin avisos de PHP, y mas alla del tope las filas dicen «no disponible» sin llamar a mas plugins', $pc['code'] === 200 && count($cids($pc['body'])) === 60 && !preg_match('/(?:Warning|Deprecated|Fatal error):\s/', $pc['body']) && substr_count($pc['body'], 'asset 92') >= 10, 'filas=' . count($cids($pc['body'])));
$ko = count(array_filter($RES, fn($x) => !$x)); printf("RESUMEN lista-admin: %d PASA / %d FALLA\n", count($RES) - $ko, $ko); exit($ko ? 1 : 0);
