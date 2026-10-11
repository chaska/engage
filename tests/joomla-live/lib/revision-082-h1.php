<?php
/**
 * 0.8.3, H1 y H5 de la revision independiente de la 0.8.2 contra el Joomla REAL (curl): un invitado leia cualquier comentario por ID (texto, nombre y
 * CORREO del autor; tambien sin publicar, spam, de contenido restringido) con `view=comment&id=N` o con cualquier tarea desconocida; `task=comments.display`
 * daba 500. Heredado de Akeeba Engage 3.4.2 (afecta tambien a las versiones ya publicadas).
 *   1) Matriz: 13 URLs/formas del PoC x invitado, Registered ajeno, Registered autor, Manager x 7 comentarios (publicado, spam, sin publicar, papelera,
 *      de contenido restringido, de articulo sin publicar, de un registrado). Sin rastros personales para quien no puede editar; el 403 del comentario que
 *      EXISTE es identico al del que NO existe (sin fugas de existencia); controles positivos (Manager y autor ven lo que deben).
 *   2) Barrido de tareas: todos los metodos publicos de los controladores del sitio (y nombres de Joomla) x GET/POST x 4 actores: nunca 5xx, ni avisos de PHP;
 *      solo las tareas de la lista cerrada responden algo distinto de 403.
 *   3) H5: task=comments.display, task=display&view=comments, task=comments.* sin guard: 403, nunca 500.
 *   4) Flujo legitimo intacto: comentar como invitado, editar el propio comentario (registrado autor), guardado fallido y reenvio del formulario.
 *   5) Panel (administrator): invitado/Registered sin acceso; Manager sin 5xx en todas las vistas y tareas publicas.
 */
require __DIR__ . '/revision-082-comun.php';

$uid = ['reg' => usuario('r3reg', 2), 'reg2' => usuario('r3reg2', 2), 'mgr' => usuario('r3mgr', 6), 'adm' => usuario('r3adm', 7)];
$C   = siembra($uid);
register_shutdown_function(function () { q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%' OR body LIKE '%R3B-%'"); borraUsuarios(); });
@mkdir("$WORK/tmp");
$g   = new Cliente($BASE, "$WORK/tmp", 'r3g');
$reg2 = new Cliente($BASE, "$WORK/tmp", 'r3reg2'); $okr2 = $reg2->login('r3reg2', $PASS);
$reg = new Cliente($BASE, "$WORK/tmp", 'r3reg'); $okr = $reg->login('r3reg', $PASS);
$mgr = new Cliente($BASE, "$WORK/tmp", 'r3mgr'); $okm = $mgr->login('r3mgr', $PASS);
r('H1-00', 'Sesiones de prueba abiertas (Registered ajeno, Registered autor, Manager)', $okr2 && $okr && $okm);
$actores = ['invitado' => $g, 'registrado-ajeno' => $reg2, 'registrado-autor' => $reg, 'manager' => $mgr];
$log0 = logPhp();
$OP = '/index.php?option=com_engage';

// ------------------------------------------------------------------ 1) Matriz
$formas = [
	'view=comment&id=%d', 'view=comment&layout=edit&id=%d', 'view=Comment&id=%d', 'controller=comment&id=%d', 'task=comment.display&id=%d',
	'task=comment.loadhistory&id=%d', 'task=comment.runTransition&id=%d', 'task=comment.foo&id=%d', 'task=comment.main&id=%d', 'view=comment&id=%d&format=json',
	'view=comment&id=%d&format=raw', 'view=comment&id[]=%d', 'view=comment&id=%d&cid[]=%d',
];
$fugas = []; $nofugaEx = 0; $total = 0; $difieren = []; $positivos = 0; $negCtl = [];
foreach ($C as $clave => $id) {
	$rast = rastros($clave, $C);
	foreach ($formas as $f) {
		$url = $OP . '&' . (substr_count($f, '%d') === 2 ? sprintf($f, $id, $id) : sprintf($f, $id));
		$noex = $OP . '&' . (substr_count($f, '%d') === 2 ? sprintf($f, 999000 + $id, 999000 + $id) : sprintf($f, 999000 + $id));
		foreach ($actores as $nom => $c) {
			$x = $c->get($url); $total++;
			$esDueno = ($nom === 'registrado-autor' && $clave === 'delregistrado');
			$puede = ($nom === 'manager') || $esDueno;
			$mal = sinRastros($x['body'], $rast);
			if (!$puede && $mal) { $fugas[] = "$nom $clave " . substr($f, 0, 40) . ' -> ' . implode(',', $mal); }
			if (!$puede && $x['code'] >= 500) { $fugas[] = "$nom $clave " . substr($f, 0, 40) . ' -> HTTP ' . $x['code']; }
			if (!$puede) {
				$y = $c->get($noex);
				if ($x['code'] !== $y['code'] || normaliza($x['body']) !== normaliza($y['body'])) { $difieren[] = "$nom $clave " . substr($f, 0, 40) . " {$x['code']} vs {$y['code']}"; }
			}
		}
	}
	// Control positivo: el Manager y el autor SI ven el formulario de edicion con el texto
	$m = $mgr->get("$OP&view=comment&id=$id");
	$positivos += ($m['code'] === 200 && stripos($m['body'], 'R3B-' . strtoupper($clave)) !== false) ? 1 : 0;
}
r('H1-01', "Matriz $total peticiones (13 formas x 7 comentarios x 4 actores): invitado, Registered ajeno y autor-de-otro NO reciben texto, nombre, correo, IP ni navegador de ningun comentario", !$fugas, count($fugas) . ' fugas: ' . implode(' | ', array_slice($fugas, 0, 4)));
r('H1-02', 'El 403 de un comentario que EXISTE es identico (codigo y cuerpo normalizado) al de uno que NO existe: sin fugas de existencia', !$difieren, count($difieren) . ' diferencias: ' . implode(' | ', array_slice($difieren, 0, 4)));
r('H1-03', 'Control positivo: el Manager (core.edit) abre el formulario de edicion de los 7 comentarios, con su texto', $positivos === count($C), "$positivos de " . count($C));
$mine = $reg->get("$OP&view=comment&id={$C['delregistrado']}"); $ajeno = $reg->get("$OP&view=comment&id={$C['publicado']}");
r('H1-04', 'Control positivo: el autor con core.edit.own abre SU comentario (200 con su texto) y no el ajeno (403)', $mine['code'] === 200 && str_contains($mine['body'], 'R3B-DELREGISTRADO') && $ajeno['code'] === 403, "propio={$mine['code']} ajeno={$ajeno['code']}");
foreach (['invitado' => $g, 'registrado-ajeno' => $reg2] as $nom => $c) {
	$vacio = $c->get("$OP&view=comment"); $vacio2 = $c->get("$OP&view=comment&layout=edit&id=0");
	r("H1-05-$nom", "$nom: view=comment sin id (formulario vacio) = 403 (antes 200 para todos)", $vacio['code'] === 403 && $vacio2['code'] === 403, "{$vacio['code']}/{$vacio2['code']}");
}
// id mal formados: nunca se «interpretan» como otro comentario
$mal = [];
foreach (['id[]=%d', 'id=0%d', 'id=%d.0', 'id=%d%%20', 'id=%%2B%d', 'id=0x%X', 'id=%de0', 'id=-%d', 'id[a]=%d', 'id=%d%%00', 'id=%d,1'] as $f) {
	$u = $OP . '&view=comment&' . sprintf($f, $C['publicado']);
	foreach ($actores as $nom => $c) { if ($nom === 'manager') { continue; } $x = $c->get($u); if ($x['code'] !== 403 || sinRastros($x['body'], rastros('publicado', $C))) { $mal[] = "$nom $f -> {$x['code']}"; } }
}
r('H1-06', 'ID mal formados (array, ceros a la izquierda, decimales, espacios, signos, hex, notacion cientifica, NUL, listas) = 403 sin rastros', !$mal, implode(' | ', array_slice($mal, 0, 4)));

// ------------------------------------------------------------------ 2) Barrido de tareas
$tareas = ['main', 'display', 'edit', 'add', 'save', 'apply', 'save2new', 'save2copy', 'cancel', 'reload', 'batch', 'loadhistory', 'runTransition', 'editAssociations',
	'publish', 'unpublish', 'archive', 'trash', 'report', 'delete', 'checkin', 'reorder', 'saveorder', 'saveOrderAjax', 'orderup', 'orderdown', 'possiblespam',
	'reportham', 'reportspam', 'unsubscribe', 'toggle', 'state', 'getModel', 'getView', 'execute', 'redirect', 'setRedirect', 'checkToken', 'registerTask', 'getTask',
	'getTasks', 'setMessage', 'addModelPath', 'addViewPath', 'createModel', 'createView', 'holdEditId', 'releaseEditId', 'allowAdd', 'allowEdit', 'allowSave',
	'getRedirectToItemAppend', 'getRedirectToListAppend', 'postSaveHook', 'onBeforeMain', 'onAfterSave', '__default', '__construct', 'foo', 'x.y', '..'];
$permitidas = ['comment' => ['main', 'edit', 'save'], 'comments' => ['main', 'publish', 'unpublish', 'delete', 'possiblespam', 'reportham', 'reportspam', 'unsubscribe'], 'reactions' => ['toggle', 'state']];
$cinco = []; $abiertas = []; $n = 0;
foreach ($permitidas as $ctl => $ok) {
	foreach ($tareas as $t) {
		foreach ($actores as $nom => $c) {
			foreach (['GET', 'POST'] as $met) {
				$u = "$OP&task=$ctl." . rawurlencode($t) . "&id={$C['publicado']}&cid[]={$C['publicado']}&asset_id=$ASSET_PUB&ids={$C['publicado']}";
				$x = $met === 'GET' ? $c->get($u) : $c->post($u, ['id' => $C['publicado'], 'x' => 1]);
				$n++;
				if ($x['code'] >= 500) { $cinco[] = "$nom $met $ctl.$t {$x['code']}"; }
				if (!in_array(strtolower($t), $ok, true) && $x['code'] !== 403 && $x['code'] !== 404) { $abiertas[] = "$nom $met $ctl.$t {$x['code']}"; }
				if ($nom !== 'manager' && !($nom === 'registrado-autor') && sinRastros($x['body'], rastros('publicado', $C))) { $cinco[] = "FUGA $nom $met $ctl.$t"; }
			}
		}
	}
}
r('H1-07', "Barrido de $n peticiones (3 controladores x " . count($tareas) . ' tareas x GET/POST x 4 actores): ninguna 5xx ni fuga', !$cinco, count($cinco) . ': ' . implode(' | ', array_slice($cinco, 0, 5)));
r('H1-08', 'Las tareas FUERA de la lista cerrada (getModel, redirect, execute, loadhistory, display, __default...) responden 403/404 siempre', !$abiertas, count($abiertas) . ': ' . implode(' | ', array_slice($abiertas, 0, 5)));
// view= sin tarea para cada vista y controlador conocidos (incluidos los del panel pedidos por el sitio)
$vistas = []; foreach (['comment', 'comments', 'reactions', 'controlpanel', 'emailtemplates', 'settings', 'updates', 'upgrade', 'x'] as $v) { foreach (['view', 'controller'] as $k) {
	foreach ($actores as $nom => $c) { $x = $c->get("$OP&$k=$v&asset_id=$ASSET_PUB&id={$C['publicado']}"); if ($x['code'] >= 500 || ($nom !== 'manager' && $nom !== 'registrado-autor' && sinRastros($x['body'], rastros('publicado', $C)))) { $vistas[] = "$nom $k=$v {$x['code']}"; } }
} }
r('H1-09', 'Cada vista/controlador por URL (comment, comments, reactions, y los del panel) como los 4 actores: sin 5xx ni rastros personales', !$vistas, implode(' | ', array_slice($vistas, 0, 5)));

// ------------------------------------------------------------------ 3) H5
$h5 = [];
foreach (["$OP&task=comments.display&asset_id=$ASSET_PUB", "$OP&task=display&view=comments&asset_id=$ASSET_PUB", "$OP&task=display&view=comments", "$OP&view=comments&task=display", "$OP&task=comments.display", "$OP&task=comments.main", "$OP&task=comments.main&asset_id=$ASSET_PUB&format=json", "$OP&view=comments&asset_id=$ASSET_PUB&format=raw"] as $u) {
	foreach ($actores as $nom => $c) { $x = $c->get($u); if ($x['code'] !== 403) { $h5[] = "$nom " . substr($u, 25) . " -> {$x['code']}"; } }
}
r('H5-01', 'task=comments.display / task=display&view=comments / task=comments.main sin pasar por el plugin: 403 para TODOS (incluido Manager), nunca 500', !$h5, implode(' | ', array_slice($h5, 0, 4)));
$pagina = $g->get($PAGE);
r('H5-02', 'La pagina del articulo sigue mostrando la lista de comentarios (el plugin si puede)', $pagina['code'] === 200 && str_contains($pagina['body'], 'R3B-PUBLICADO'), "HTTP {$pagina['code']}");

// ------------------------------------------------------------------ 4) Flujo legitimo
$tk = Cliente::token($pagina['body']);
$post = $g->post("$OP&task=comment.save", ['returnurl' => base64_encode($BASE . '/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'name' => 'R3N-NUEVO', 'email' => 'r3nuevo@example.invalid', 'body' => 'R3B-NUEVO desde el flujo legitimo']]);
$nuevo = (int) one("SELECT id FROM jos_engage_comments WHERE body LIKE '%R3B-NUEVO%'");
r('H1-10', 'Flujo legitimo: un invitado comenta con el formulario del articulo (303 y el comentario queda guardado)', $post['code'] === 303 && $nuevo > 0, "HTTP {$post['code']} id=$nuevo");
// Guardado fallido: el formulario vuelve con los datos (id 0 con datos en sesion) y se puede reenviar
$fallo = $g->post("$OP&task=comment.save", ['returnurl' => base64_encode($BASE . '/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'name' => 'R3N-FALLO', 'email' => 'no-es-un-correo', 'body' => 'R3B-FALLO texto']]);
$sig = $fallo['loc'] !== '' ? $g->get($fallo['loc']) : ['code' => 0, 'body' => ''];
r('H1-11', 'Flujo legitimo: guardado fallido (correo invalido) con returnurl vuelve a la pagina del articulo (200) y deja el formulario con los datos del visitante', $fallo['code'] === 303 && $sig['code'] === 200, "HTTP {$fallo['code']} -> {$sig['code']} loc=" . substr($fallo['loc'], 0, 90));
$g2 = new Cliente($BASE, "$WORK/tmp", 'r3g2'); $tkb = Cliente::token($g2->get($PAGE)['body']);
$fallo2 = $g2->post("$OP&task=comment.save", [$tkb => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'name' => 'R3N-FALLO2', 'email' => 'no-es-un-correo', 'body' => 'R3B-FALLO2 texto']]);
$sig2 = $fallo2['loc'] !== '' ? $g2->get($fallo2['loc']) : ['code' => 0, 'body' => ''];
$solo = $g->get("$OP&view=comment");
r('H1-11b', 'Guardado fallido SIN returnurl: Joomla redirige a view=comments (sin asset_id: 403, igual que en la 0.8.2); view=comment sin datos de formulario en sesion = 403; nada de 5xx', $fallo2['code'] === 303 && in_array($sig2['code'], [200, 403], true) && $solo['code'] === 403, "HTTP {$fallo2['code']} -> {$sig2['code']} loc=" . substr($fallo2['loc'], 0, 90) . " otro={$solo['code']}");
// Edicion del propio comentario por el autor registrado (Editar -> pantalla -> guardar)
$tk2 = Cliente::token($reg->get($PAGE)['body']);
$ed = $reg->get("$OP&task=comment.edit&id={$C['delregistrado']}&$tk2=1&returnurl=" . base64_encode('/'));
$pant = $ed['loc'] !== '' ? $reg->get($ed['loc']) : ['code' => 0, 'body' => ''];
r('H1-12', 'Flujo legitimo: el autor pulsa Editar (303) y ve la pantalla de edicion de SU comentario (200)', $ed['code'] === 303 && $pant['code'] === 200 && str_contains($pant['body'], 'R3B-DELREGISTRADO'), "edit={$ed['code']} pantalla={$pant['code']}");
$sv = $reg->post("$OP&task=comment.save&id={$C['delregistrado']}", ['returnurl' => base64_encode($BASE . '/'), Cliente::token($pant['body']) ?: $tk2 => 1, 'jform' => ['id' => $C['delregistrado'], 'body' => 'R3B-DELREGISTRADO editado por su autor']]);
r('H1-13', 'Flujo legitimo: guardar la edicion propia (303) cambia el texto en la base de datos', $sv['code'] === 303 && str_contains((string) one("SELECT body FROM jos_engage_comments WHERE id={$C['delregistrado']}"), 'editado por su autor'), "HTTP {$sv['code']}");
$ajena = $reg2->post("$OP&task=comment.save&id={$C['delregistrado']}", ['returnurl' => base64_encode($BASE . '/'), Cliente::token($reg2->get($PAGE)['body']) => 1, 'jform' => ['id' => $C['delregistrado'], 'body' => 'R3B-HACKEADO']]);
r('H1-14', 'Un Registered ajeno NO puede guardar sobre el comentario del autor', !str_contains((string) one("SELECT body FROM jos_engage_comments WHERE id={$C['delregistrado']}"), 'HACKEADO'), "HTTP {$ajena['code']}");
// Moderacion por el Manager desde el articulo (enlace con token de sesion)
$tkm = Cliente::token($mgr->get($PAGE)['body']);
$pub = $mgr->get("$OP&task=comments.unpublish&cid[]={$C['publicado']}&$tkm=1&returnurl=" . base64_encode('/'));
$en = (int) one("SELECT enabled FROM jos_engage_comments WHERE id={$C['publicado']}");
$pub2 = $mgr->get("$OP&task=comments.publish&cid[]={$C['publicado']}&$tkm=1&returnurl=" . base64_encode('/'));
r('H1-15', 'Flujo legitimo: el Manager despublica y vuelve a publicar con el token de su sesion', $pub['code'] === 303 && $en === 0 && (int) one("SELECT enabled FROM jos_engage_comments WHERE id={$C['publicado']}") === 1, "HTTP {$pub['code']}/{$pub2['code']} enabled tras despublicar=$en");
$sinTk = $g->get("$OP&task=comments.delete&cid[]={$C['sinpublicar']}");
r('H1-16', 'Moderar sin token ni firma sigue sin hacer nada (el comentario sigue ahi)', (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id={$C['sinpublicar']}") === 1, "HTTP {$sinTk['code']}");
// Reacciones: siguen funcionando
$st = $g->get("$OP&task=reactions.state&ids={$C['publicado']}&format=json");
r('H1-17', 'reactions.state sigue respondiendo JSON a un invitado (tarea de la lista)', $st['code'] === 200 && str_starts_with(trim($st['body']), '{'), "HTTP {$st['code']}");

// ------------------------------------------------------------------ 5) Panel
$adm = new Cliente($BASE, "$WORK/tmp", 'r3adm'); $okA = $adm->loginAdmin('r3adm', $PASS); // Administrator (grupo 7): tiene core.manage de com_engage; el Manager no
$mgrA = new Cliente($BASE, "$WORK/tmp", 'r3mgradm'); $okMA = $mgrA->loginAdmin('r3mgr', $PASS); $mA = $okMA ? $mgrA->get('/administrator/index.php?option=com_engage&view=comments') : ['code' => 0];
r('H1-18b', 'Panel: el Manager (sin core.manage de com_engage) no entra (403) y el Administrator si (200)', $okA && $okMA && $mA['code'] === 403 && $adm->get('/administrator/index.php?option=com_engage&view=comments')['code'] === 200, "manager={$mA['code']}");
$gA = $g->get('/administrator/index.php?option=com_engage&view=comments'); $rA = $reg->get('/administrator/index.php?option=com_engage&view=comment&id=' . $C['publicado']);
r('H1-18', 'Panel: invitado y Registered (sin acceso al panel) no reciben ningun dato de comentarios', !sinRastros($gA['body'] . $rA['body'], rastros('publicado', $C)) && $gA['code'] < 500 && $rA['code'] < 500, "invitado={$gA['code']} registrado={$rA['code']}");
$cincoA = []; $nA = 0;
if ($okA) {
	$vistasA = ['controlpanel', 'comments', 'emailtemplates', 'settings', 'comment&id=' . $C['publicado'], 'comment&layout=edit&id=0', 'comment&id=999999', 'comments&filter[search]=R3&list[limit]=5'];
	foreach ($vistasA as $v) { $x = $adm->get("/administrator/index.php?option=com_engage&view=$v"); $nA++; if ($x['code'] >= 500) { $cincoA[] = "view=$v {$x['code']}"; } }
	foreach (['comments' => ['display', 'publish', 'unpublish', 'delete', 'possiblespam', 'reportham', 'reportspam', 'checkin', 'getModel', 'execute', 'foo'], 'comment' => ['add', 'edit', 'cancel', 'save', 'batch', 'loadhistory', 'runTransition', 'foo'], 'controlpanel' => ['display', 'foo', 'getModel'], 'emailtemplates' => ['display', 'updateEmails', 'resetEmails', 'foo'], 'settings' => ['display', 'save', 'foo']] as $ctl => $ts) {
		foreach ($ts as $t) { foreach (['GET', 'POST'] as $met) { $u = "/administrator/index.php?option=com_engage&task=$ctl.$t&cid[]={$C['sinpublicar']}&id={$C['sinpublicar']}"; $x = $met === 'GET' ? $adm->get($u) : $adm->post($u, ['x' => 1]); $nA++; if ($x['code'] >= 500) { $cincoA[] = "$met $ctl.$t {$x['code']}"; } } }
	}
}
r('H1-19', "Panel como Administrator: $nA peticiones (vistas y tareas publicas de los 5 controladores) sin 5xx", $okA && !$cincoA, ($okA ? '' : 'no se pudo iniciar sesion; ') . count($cincoA) . ': ' . implode(' | ', array_slice($cincoA, 0, 6)));
// Las tareas del panel que SI usa la interfaz (lista cerrada del panel) siguen funcionando para el Super User
$sup = new Cliente($BASE, "$WORK/tmp", 'r3sup'); $okS = $sup->loginAdmin('admintest', 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt")));
$tkS = $okS ? Cliente::token($sup->get('/administrator/index.php?option=com_engage&view=comments')['body']) : '';
// Con comentarios propios de esta comprobacion (los de la matriz se siguen necesitando intactos mas abajo)
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B-ADMIN</p>','R3N-ADMIN','admin-r3@privado.invalid','198.51.100.99','R3UA-ADMIN',0,'2026-09-01 10:00:00',0)");
$idp = (int) $db->insert_id; $pasos = [];
$idRs = (function () use ($ASSET_PUB, $db) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B-ADMIN2</p>','R3N-ADMIN2','admin2-r3@privado.invalid','198.51.100.98','R3UA-ADMIN2',-3,'2026-09-01 10:00:00',0)"); return (int) $db->insert_id; })();
$idDel = (function () use ($ASSET_PUB, $db) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B-ADMIN3</p>','R3N-ADMIN3','admin3-r3@privado.invalid','198.51.100.97','R3UA-ADMIN3',1,'2026-09-01 10:00:00',0)"); return (int) $db->insert_id; })();
foreach (['publish' => 1, 'unpublish' => 0, 'possiblespam' => -3, 'reportham' => 1] as $t => $esperado) {
	$sup->post("/administrator/index.php?option=com_engage&task=comments.$t", ['cid' => [$idp], $tkS => 1]);
	$pasos[] = "$t=" . (int) one("SELECT enabled FROM jos_engage_comments WHERE id=$idp"); if ((int) one("SELECT enabled FROM jos_engage_comments WHERE id=$idp") !== $esperado) { $pasos[] = 'MAL'; }
}
$sup->post("/administrator/index.php?option=com_engage&task=comments.reportspam", ['cid' => [$idRs], $tkS => 1]);
$edit = $sup->get("/administrator/index.php?option=com_engage&view=comment&task=edit&id=$idp"); $pant = $edit['loc'] !== '' ? $sup->get($edit['loc']) : ['code' => 0, 'body' => ''];
$sup->post("/administrator/index.php?option=com_engage&task=comments.delete", ['cid' => [$idDel], $tkS => 1]);
r('H1-23', 'Panel (Super User): las tareas que usa la interfaz siguen funcionando con la lista cerrada (publicar, despublicar, posible spam, no es spam, spam, editar, borrar)', $okS && !in_array('MAL', $pasos, true) && (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$idRs") === 0 && (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$idDel") === 0 && $pant['code'] === 200 && str_contains($pant['body'], 'R3B-ADMIN'), implode(',', $pasos) . " editar={$pant['code']}");
// Los 5xx y avisos de PHP del barrido
$nuevoLog = logNuevo($log0);
$graves = array_filter(explode("\n", $nuevoLog), fn($l) => preg_match('/(Fatal|TypeError|Deprecated|Warning|Notice|Uncaught|ArgumentCountError)/', $l));
r('H1-20', 'El registro de PHP (error_reporting=-1) no recibe errores fatales, TypeError, avisos ni deprecations durante todo el barrido', !$graves, count($graves) . ': ' . implode(' | ', array_map(fn($l) => substr($l, 0, 160), array_slice($graves, 0, 3))));
// La pagina publica del articulo: ni correo, ni IP ni navegador para quien no modera; ni spam ni sin publicar
$pg = $g->get($PAGE)['body']; $pr = $reg2->get($PAGE)['body']; $pm = $mgr->get($PAGE)['body'];
$pii = []; foreach (['publicado', 'spam', 'sinpublicar', 'papelera', 'delregistrado'] as $k) { $n = strtoupper($k); foreach (["r3mail-$k@privado.invalid", "R3UA-$n", '198.51.100.'] as $m) { if (stripos($pg, $m) !== false) { $pii[] = "invitado $m"; } if (stripos($pr, $m) !== false) { $pii[] = "registrado $m"; } } }
$ocultos = array_filter(['SPAM', 'SINPUBLICAR', 'PAPELERA'], fn($n) => str_contains($pg, "R3B-$n") || str_contains($pr, "R3B-$n"));
r('H1-21', 'Pagina publica del articulo: invitado y Registered no ven correo, IP ni navegador de nadie, ni comentarios spam, sin publicar o en la papelera', !$pii && !$ocultos, implode(' | ', array_merge($pii, $ocultos)));
r('H1-22', 'Control positivo: el Manager ve en la misma pagina el correo del invitado y los comentarios sin publicar/spam', str_contains($pm, 'r3mail-publicado@privado.invalid') && str_contains($pm, 'R3B-SPAM'), '');
resumen('h1');
