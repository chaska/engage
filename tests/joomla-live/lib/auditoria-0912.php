<?php
/**
 * 0.9.12 contra el Joomla REAL (correcciones de las auditorias):
 *   1) BBCode: un comentario de 60 KB de «[b]» se renderiza en segundos, no en decenas de segundos (DoS)
 *   2) tope duro de longitud: un comentario de mas de 20000 caracteres no se guarda aunque max_length sea 0; uno normal si
 *   3) baja de avisos: el token de formulario NO basta (invitado: 403 sin cambios, igual con un id que no existe); el enlace firmado funciona; un usuario con sesion
 *      solo se da de baja con SU correo
 *   4) un invitado que comenta con el correo de otra persona no la vuelve a suscribir; un registrado si (su propio correo)
 *   5) limitador de comment.save (30 por IP en 10 minutos): rechazo con mensaje, sin guardar; despues de limpiar, se guarda
 * Restaura todo al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
$uid = ['reg' => usuario('r3reg', 2), 'mgr' => usuario('r3mgr', 6)];
$C   = siembra($uid);
$secret = preg_match("/public \\\$secret = '([^']*)';/", file_get_contents("$SITE/configuration.php"), $m) ? $m[1] : '';
register_shutdown_function(function () { q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%' OR body LIKE '%R3B-%'"); q("DELETE FROM jos_engage_unsubscribe WHERE email LIKE '%@r3a.invalid' OR email LIKE 'r3reg@%'"); borraUsuarios(); });
@mkdir("$WORK/tmp");
$g = new Cliente($BASE, "$WORK/tmp", 'r9g'); $reg = new Cliente($BASE, "$WORK/tmp", 'r9reg'); $reg->login('r3reg', $PASS); $mgr = new Cliente($BASE, "$WORK/tmp", 'r9mgr'); $mgr->login('r3mgr', $PASS);
$OP = '/index.php?option=com_engage';
$post = function (Cliente $c, array $j) use ($ASSET_PUB, $PAGE, $OP) { $tk = Cliente::token($c->get($PAGE)['body']); return $c->post("$OP&task=comment.save", ['returnurl' => base64_encode('/'), $tk => 1, 'jform' => array_merge(['asset_id' => $ASSET_PUB, 'parent_id' => '0'], $j)]); };

// 1) BBCode
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'" . esc('R3B-BB ' . str_repeat('[b]', 20000) . 'hola') . "','R3N-BB','r3bb@r3a.invalid','198.51.100.99','R3UA',1,'2026-09-01 10:00:00',0)");
$t = microtime(true); $p = $g->get($PAGE); $d = microtime(true) - $t;
r('A0912-1', sprintf('pagina con un comentario de 60 KB de [b]: %.1f s (antes ~35 s)', $d), $p['code'] === 200 && $d < 8 && str_contains($p['body'], 'R3B-BB'), "HTTP {$p['code']}");
q("DELETE FROM jos_engage_comments WHERE name='R3N-BB'");

// 2) longitud
$big = 'R3B-BIG ' . str_repeat('x', 20500);
$post($g, ['name' => 'R3N-BIG', 'email' => 'big@r3a.invalid', 'body' => $big]);
r('A0912-2', 'comentario de 20500 caracteres con max_length=0: NO se guarda', (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE name='R3N-BIG'") === 0);
$post($g, ['name' => 'R3N-OK', 'email' => 'ok@r3a.invalid', 'body' => 'R3B-OK comentario normal']);
r('A0912-3', 'un comentario normal se guarda', (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE name='R3N-OK'") === 1);

// 3) baja de avisos
$id = $C['publicado']; $victima = 'victima@r3a.invalid';
$tkg = Cliente::token($g->get($PAGE)['body']);
$cuenta = fn(string $e) => (int) one("SELECT COUNT(*) FROM jos_engage_unsubscribe WHERE email='" . esc($e) . "'");
$x1 = $g->get("$OP&task=comments.unsubscribe&id=$id&email=" . urlencode($victima) . "&$tkg=1");
$x2 = $g->get("$OP&task=comments.unsubscribe&id=99999999&email=" . urlencode($victima) . "&$tkg=1");
r('A0912-4', 'invitado con solo el token de formulario: 403 y sin baja', $x1['code'] === 403 && $cuenta($victima) === 0, "HTTP {$x1['code']}");
r('A0912-5', 'mismo resultado con un id que no existe (sin oraculo de IDs)', $x2['code'] === $x1['code'] && normaliza($x1['body']) === normaliza($x2['body']));
$asset = (int) one("SELECT asset_id FROM jos_engage_comments WHERE id=$id"); $exp = time() + 3600;
$firma = hash_hmac('sha256', "comments.unsubscribe-$victima-$asset-$exp-$id", $secret);
$x3 = $g->get("$OP&task=comments.unsubscribe&id=$id&email=" . urlencode($victima) . "&expires=$exp&cid[]=$id&token=$firma");
r('A0912-6', 'enlace firmado valido (como el de los correos): la baja se registra', in_array($x3['code'], [200, 301, 302, 303], true) && $cuenta($victima) === 1, "HTTP {$x3['code']}");
$mal = $g->get("$OP&task=comments.unsubscribe&id=$id&email=" . urlencode('otra@r3a.invalid') . "&expires=$exp&cid[]=$id&token=$firma");
r('A0912-7', 'el enlace firmado para un correo no vale para otro', $mal['code'] === 403 && $cuenta('otra@r3a.invalid') === 0, "HTTP {$mal['code']}");
$tkr = Cliente::token($reg->get($PAGE)['body']);
$o1 = $reg->get("$OP&task=comments.unsubscribe&id=$id&email=" . urlencode('r3reg@example.invalid') . "&$tkr=1");
$o2 = $reg->get("$OP&task=comments.unsubscribe&id=$id&email=" . urlencode($victima . 'x') . "&$tkr=1");
r('A0912-8', 'usuario con sesion: se da de baja con SU correo (token de formulario)', in_array($o1['code'], [200, 301, 302, 303], true) && $cuenta('r3reg@example.invalid') === 1, "HTTP {$o1['code']}");
r('A0912-9', 'usuario con sesion: con el correo de otra persona, 403', $o2['code'] === 403 && $cuenta($victima . 'x') === 0, "HTTP {$o2['code']}");


// 3b) enlaces firmados de moderacion (los de los correos, con un Manager con sesion): funcionan, y no valen para otra tarea
$sp = $C['sinpublicar']; $sa = (int) one("SELECT asset_id FROM jos_engage_comments WHERE id=$sp"); $em = 'mod@r3a.invalid'; $ex = time() + 3600;
$fm = fn(string $task) => hash_hmac('sha256', "$task-$em-$sa-$ex-$sp", $secret);
$base = "$OP&id=$sp&email=" . urlencode($em) . "&expires=$ex&cid[]=$sp";
$d1 = $mgr->post("$base&task=comments.publish&token=" . $fm('comments.publish'), ['task' => 'comments.delete']);
r('A0912-15', 'enlace firmado de publicar usado con una tarea distinta en el POST (delete): no borra ni publica', (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE id=$sp AND enabled=0") === 1, "HTTP {$d1['code']}");
$d2 = $mgr->get("$base&task=comments.publish&token=" . $fm('comments.publish'));
r('A0912-16', 'enlace firmado de publicar (GET, como el correo): publica el comentario', (int) one("SELECT enabled FROM jos_engage_comments WHERE id=$sp") === 1, "HTTP {$d2['code']}");

// 4) re-suscripcion
$post($g, ['name' => 'R3N-RS', 'email' => $victima, 'body' => 'R3B-RS un invitado comenta con el correo de otro']);
r('A0912-10', 'un invitado que comenta con el correo de la victima NO anula su baja', $cuenta($victima) === 1 && (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE name='R3N-RS'") === 1);
$post($reg, ['body' => 'R3B-RS2 el propio usuario comenta']);
r('A0912-11', 'un usuario registrado que comenta vuelve a estar suscrito con su propio correo', $cuenta('r3reg@example.invalid') === 0);

// 5) limitador
q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-RL%'");
for ($i = 0; $i < 31; $i++) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'R3B-RL $i','R3N-RL$i','rl$i@r3a.invalid','127.0.0.1','R3UA',-3,UTC_TIMESTAMP(),0)"); }
$post($g, ['name' => 'R3N-LIM', 'email' => 'lim@r3a.invalid', 'body' => 'R3B-LIM demasiado deprisa']);
r('A0912-12', 'con 31 comentarios recientes desde la IP, el siguiente se rechaza y no se guarda', (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE name='R3N-LIM'") === 0);
q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-RL%'");
$post($g, ['name' => 'R3N-LIM', 'email' => 'lim@r3a.invalid', 'body' => 'R3B-LIM ahora si']);
r('A0912-13', 'sin los comentarios recientes, se guarda otra vez', (int) one("SELECT COUNT(*) FROM jos_engage_comments WHERE name='R3N-LIM'") === 1);
resumen('auditoria-0912');
