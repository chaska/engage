<?php
/** Comprobaciones web tras actualizar: los comentarios antiguos se muestran, se puede comentar y el panel lista todo. */
require __DIR__ . '/Cliente.php';
[$_, $WORK, $BASE, $IDSF] = $argv + [null, null, null, 'ids.json'];
$ids = json_decode(file_get_contents("$WORK/$IDSF"), true);
$AP  = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
$db  = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_upg');
$db->set_charset('utf8mb4');
$ok = 0; $ko = 0;
function t(bool $c, string $m): void { global $ok, $ko; $c ? $ok++ : $ko++; echo ($c ? '  OK    ' : '  FALLO ') . $m . "\n"; }

$g = new Cliente($BASE, "$WORK/tmp", 'hp-g');
$A = $ids['art_publico']; $url = "/index.php?option=com_content&view=article&id=$A&catid={$ids['cat_publica']}";
$p = $g->get($url . '&akengage_limit=50');
t($p['code'] === 200, 'articulo con comentarios antiguos: HTTP 200');
t(strpos($p['body'], 'María Ñandú') !== false && strpos($p['body'], 'áéíóú ñ') !== false && strpos($p['body'], '😀') !== false, 'acentos, eñe y emoji de los comentarios antiguos intactos');
t(strpos($p['body'], 'Comentario de usuario registrado') !== false, 'comentario del usuario registrado visible');
t(strpos($p['body'], 'Respuesta al primero') !== false && strpos($p['body'], 'nivel 2') !== false, 'respuestas anidadas visibles');
t(strpos($p['body'], 'Pendiente de moderacion') === false && strpos($p['body'], 'Esto es spam') === false, 'sin publicar y spam NO se muestran al publico');
t((bool) preg_match('/(\d+) comments/', $p['body'], $m) && (int) $m[1] === 4, 'el titulo cuenta 4 comentarios publicados (' . ($m[0] ?? '?') . ')');
t(substr_count($p['body'], 'akengage-comment-list--level2') >= 1 && substr_count($p['body'], 'akengage-comment-list--level3') >= 1, 'la jerarquia (niveles 2 y 3) se conserva');

// comentar de nuevo en el sitio actualizado
$t = Cliente::token($p['body']);
$n0 = (int) $db->query('SELECT COUNT(*) FROM jos_engage_comments')->fetch_row()[0];
$x = $g->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode($BASE . '/'), $t => 1, 'jform' => ['asset_id' => $ids['art_publico_asset'], 'parent_id' => 0, 'name' => 'Nuevo tras actualizar', 'email' => 'nuevo@example.test', 'body' => 'Comentario tras actualizar']]);
t($x['code'] === 303 && (int) $db->query('SELECT COUNT(*) FROM jos_engage_comments')->fetch_row()[0] === $n0 + 1, 'se puede comentar tras actualizar (HTTP ' . $x['code'] . ')');

// panel
$a = new Cliente($BASE, "$WORK/tmp", 'hp-a');
if ($a->loginAdmin('admintest', $AP)) {
	$b = $a->get('/administrator/index.php?option=com_engage&view=Comments&list[limit]=100');
	$c = preg_match_all('/name="cid\[\]"/', $b['body']);
	t($b['code'] === 200 && $c === (int) $db->query('SELECT COUNT(*) FROM jos_engage_comments')->fetch_row()[0], "panel: lista todos los comentarios (HTTP {$b['code']}, filas $c)");
	$o = $a->get('/administrator/index.php?option=com_config&view=component&component=com_engage');
	t($o['code'] === 200 && strpos($o['body'], 'name="jform[default_limit]"') !== false, 'panel: opciones del componente se abren (HTTP ' . $o['code'] . ')');
	t(preg_match('/name="jform\[default_limit\]"[^>]*value="5"|value="5"[^>]*name="jform\[default_limit\]"|<option value="5" selected/', $o['body']) === 1, 'panel: el ajuste default_limit=5 del administrador se conserva');
	$d = $a->get('/administrator/index.php?option=com_cpanel');
	t($d['code'] === 200, 'panel: escritorio sin error');
} else { t(false, 'no se pudo entrar en el panel'); }
echo "  -> $ok correctas, $ko fallidas\n";
exit($ko ? 1 : 0);
