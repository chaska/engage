<?php
/**
 * 0.9.14 contra el Joomla REAL: denunciar comentarios. Abuso y permisos por HTTP:
 *   invitado (403), sin token, GET, cross-site, usuario nuevo (antiguedad), comentario propio / sin publicar / spam / papelera / contenido restringido (misma
 *   respuesta), doble denuncia, motivo invalido, nota con HTML y larga, limite por hora y por dia, tope global de abiertas, opcion desactivada, una denuncia no
 *   cambia el comentario; cola de administracion (Registered 403, Manager ve y actua, sin token 403, descartar, despublicar); aviso agrupado (un correo por
 *   moderador con el total, sin datos del denunciante, una sola vez); borrado de la cuenta.
 * Restaura todo al acabar. Requiere el SMTP de captura ($WORK/mails).
 */
require __DIR__ . '/revision-082-comun.php';
$uid = ['reg' => usuario('r3reg', 2), 'a' => usuario('r3rep', 2), 'young' => usuario('r3joven', 2), 'c' => usuario('r3repc', 2), 'mgr' => usuario('r3mgr', 6), 'reg2' => usuario('r3reg2', 2)];
$C   = siembra($uid);
$mailDir = "$WORK/mails";
$aclOrig = (string) one("SELECT rules FROM jos_assets WHERE name='com_engage'");
$comOrig = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
register_shutdown_function(function () use ($comOrig, $aclOrig) {
	q("UPDATE jos_assets SET rules='" . esc($aclOrig) . "' WHERE name='com_engage'");
	q("UPDATE jos_extensions SET params='" . esc($comOrig) . "' WHERE element='com_engage' AND type='component'");
	q("DELETE FROM jos_engage_reports");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%' OR body LIKE '%R3B-%'");
	borraUsuarios();
});
$setCom = function (array $extra) { $p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: []; q("UPDATE jos_extensions SET params='" . esc(json_encode(array_merge($p, $extra))) . "' WHERE element='com_engage' AND type='component'"); usleep(300000); };
// El Manager (grupo 6) puede entrar en el componente y moderar (core.manage y core.edit.state); se restaura al acabar
$rules = json_decode($aclOrig, true) ?: []; $rules['core.manage'] = ['6' => 1]; $rules['core.edit.state'] = ['6' => 1]; $rules['core.create'] = ['1' => 1];
q("UPDATE jos_assets SET rules='" . esc(json_encode($rules)) . "' WHERE name='com_engage'");
q("DELETE FROM jos_engage_reports");
// cuentas antiguas (24 horas o mas) salvo la joven
foreach (['reg', 'a', 'c', 'mgr', 'reg2'] as $k) { q("UPDATE jos_users SET registerDate='2026-01-01 10:00:00' WHERE id={$uid[$k]}"); }
q("UPDATE jos_users SET registerDate=UTC_TIMESTAMP() WHERE id={$uid['young']}");
@mkdir("$WORK/tmp");
$mk = function (string $user) use ($BASE, $WORK, $PASS) { $c = new Cliente($BASE, "$WORK/tmp", 'd9' . $user); if ($user !== '') { $c->login($user, $PASS); } return $c; };
$g = new Cliente($BASE, "$WORK/tmp", 'd9g'); $a = $mk('r3rep'); $young = $mk('r3joven'); $cc = $mk('r3repc'); $reg = $mk('r3reg'); $mgr = new Cliente($BASE, "$WORK/tmp", 'd9mgr'); $mgr->loginAdmin('r3mgr', $PASS); $mgrSite = $mk('r3mgr');
$OP = '/index.php?option=com_engage';
$envia = function (Cliente $c, int $cid, $reason, string $note = '', array $extra = [], bool $conToken = true, array $cab = []) use ($PAGE, $OP) {
	$tk = Cliente::token($c->get($PAGE)['body']);
	$campos = ['comment_id' => $cid, 'reason' => $reason, 'note' => $note, 'returnurl' => base64_encode($PAGE)] + ($conToken ? [$tk => 1] : []);

	return $c->post("$OP&task=report.submit", $campos + $extra, $cab);
};
$n = fn() => (int) one('SELECT COUNT(*) FROM jos_engage_reports');
$enabled = fn(int $id) => (int) one("SELECT enabled FROM jos_engage_comments WHERE id=$id");
$pub = $C['publicado'];

// ---- 1) abuso basico
$antes = $n();
$x = $envia($g, $pub, 1);
r('DN-01', 'INVITADO con un token CSRF valido: 403 y ninguna denuncia', $x['code'] === 403 && $n() === $antes, "HTTP {$x['code']}");
$x = $envia($a, $pub, 1, '', [], false);
r('DN-02', 'sin token CSRF: 403 y ninguna denuncia', $x['code'] === 403 && $n() === $antes, "HTTP {$x['code']}");
$x = $a->get("$OP&task=report.submit&comment_id=$pub&reason=1");
r('DN-03', 'por GET: 403', $x['code'] === 403 && $n() === $antes, "HTTP {$x['code']}");
$x = $envia($a, $pub, 1, '', [], true, ['Sec-Fetch-Site: cross-site']);
r('DN-04', 'cabecera cross-site: 403', $x['code'] === 403 && $n() === $antes, "HTTP {$x['code']}");
foreach (['display', 'main', 'getModel', 'execute', 'foo', ''] as $t) { $x = $a->get("$OP&task=report.$t"); if ($x['code'] !== 403) { $mal[] = "$t={$x['code']}"; } }
r('DN-05', 'cualquier otra tarea del controlador report: 403', empty($mal ?? []), implode(' | ', $mal ?? []));

// ---- 2) caso normal y que no cambia el comentario
$x = $envia($a, $pub, 1, 'es publicidad');
$row = q("SELECT * FROM jos_engage_reports WHERE comment_id=$pub AND user_id={$uid['a']}")->fetch_assoc();
r('DN-06', 'usuario registrado antiguo denuncia un comentario publicado: se guarda (motivo 1, abierta) y se redirige', $row && (int) $row['reason'] === 1 && (int) $row['status'] === 0 && $row['note'] === 'es publicidad' && in_array($x['code'], [302, 303], true), "HTTP {$x['code']}");
r('DN-07', 'la denuncia NO oculta ni borra el comentario', $enabled($pub) === 1);
$envia($a, $pub, 2);
r('DN-08', 'doble denuncia de la misma persona al mismo comentario: una sola fila', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE comment_id=$pub AND user_id={$uid['a']}") === 1);
$envia($young, $C['delregistrado'], 1);
r('DN-09', 'cuenta nueva (menos de 24 horas): no se guarda', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['young']}") === 0);
$envia($reg, $C['delregistrado'], 1);
r('DN-10', 'comentario propio: no se guarda', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['reg']}") === 0);
$ro = [];
foreach (['spam', 'sinpublicar', 'papelera'] as $k) { $envia($cc, $C[$k], 1); $ro[$k] = (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE comment_id={$C[$k]}"); }
r('DN-11', 'comentarios en spam, sin publicar o en la papelera: no se pueden denunciar', array_sum($ro) === 0, json_encode($ro));
$envia($cc, 99999999, 1);
r('DN-12', 'comentario inexistente: no se guarda nada', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['c']}") === 0);
$xa = $envia($cc, $C['spam'], 1); $xb = $envia($cc, 99999999, 1);
r('DN-13', 'inexistente y no visible reciben la MISMA respuesta (sin oraculo de IDs)', $xa['code'] === $xb['code'] && $xa['loc'] === $xb['loc']);
foreach (['', '0', '9', 'spam', '1;DROP TABLE x', '-1'] as $bad) { $envia($cc, $pub, $bad); }
r('DN-14', 'motivo vacio, 0, 9, texto o SQL: no se guarda', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['c']}") === 0);
$envia($cc, $pub, 3, '<script>alert(1)</script><b>hola</b> ' . str_repeat('x', 400));
$nota = (string) one("SELECT note FROM jos_engage_reports WHERE user_id={$uid['c']}");
r('DN-15', 'la nota se guarda sin etiquetas y recortada a 200 caracteres', $nota !== '' && !str_contains($nota, '<') && mb_strlen($nota) === 200, mb_strlen($nota) . ' ' . substr($nota, 0, 30));

// ---- 3) limites
q("DELETE FROM jos_engage_reports WHERE user_id={$uid['c']}");
$extras = []; for ($i = 0; $i < 7; $i++) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B-LIM $i</p>','R3N-LIM$i','lim$i@r3p.invalid','198.51.100.50','R3UA',1,'2026-09-01 10:00:00',0)"); $extras[] = (int) $GLOBALS['db']->insert_id; }
foreach (array_slice($extras, 0, 6) as $id) { $envia($cc, $id, 1); }
$cnt = (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['c']}");
r('DN-16', 'limite por hora: 6 denuncias seguidas, solo 5 se guardan', $cnt === 5, "guardadas=$cnt");
q("DELETE FROM jos_engage_reports WHERE user_id={$uid['c']}");
for ($i = 0; $i < 20; $i++) { q("INSERT INTO jos_engage_reports (comment_id,user_id,reason,note,created,status) VALUES (" . (900000 + $i) . ",{$uid['c']},1,'',DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 HOUR),0)"); }
$envia($cc, $extras[6], 1);
r('DN-17', 'limite por dia: con 20 denuncias en las ultimas 24 horas (fuera de la ultima hora), la siguiente se rechaza', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE comment_id={$extras[6]}") === 0);
q("DELETE FROM jos_engage_reports WHERE comment_id>=900000");
$setCom(['reports_open_max' => '50']);
for ($i = 0; $i < 50; $i++) { q("INSERT INTO jos_engage_reports (comment_id,user_id,reason,note,created,status) VALUES (" . (800000 + $i) . ",999,1,'',UTC_TIMESTAMP(),0)"); }
$envia($cc, $extras[5], 1);
r('DN-18', 'tope global de denuncias abiertas (50): con 50 abiertas no se acepta otra', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE comment_id={$extras[5]}") === 0);
q("DELETE FROM jos_engage_reports WHERE comment_id>=800000"); $setCom(['reports_open_max' => '500']);
$setCom(['reports_enabled' => '0']); $envia($cc, $extras[4], 1);
r('DN-19', 'opcion desactivada: no se acepta (y el boton no sale)', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE comment_id={$extras[4]}") === 0 && !str_contains($cc->get($PAGE)['body'], 'akengage-report'));
$setCom(['reports_enabled' => '1']);
$setCom(['reports_per_hour' => '99999']); q("DELETE FROM jos_engage_reports WHERE user_id={$uid['c']}");
foreach (array_slice($extras, 0, 6) as $id) { $envia($cc, $id, 1); }
r('DN-20', 'un limite guardado fuera del maximo (99999) no abre el grifo: vuelve al defecto 5', (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['c']}") === 5);
$setCom(['reports_per_hour' => '5']);
$h = $g->get($PAGE)['body']; $hr = $a->get($PAGE)['body'];
r('DN-21', 'la pagina de un INVITADO no lleva el boton ni el formulario de denuncia', !str_contains($h, 'akengage-report') && !str_contains($h, 'report.submit'));
r('DN-22', 'la pagina de un registrado lleva el boton en los comentarios que puede denunciar (no en los ya denunciados)', str_contains($hr, 'akengage-report-form') && !preg_match('#name="comment_id" value="' . $pub . '"#', $hr));

// ---- 4) cola de administracion
q("DELETE FROM jos_engage_reports");
$envia($a, $pub, 1, 'nota de a'); $envia($cc, $pub, 2); $envia($a, $extras[0], 3);
$q0 = $reg->get('/administrator/index.php?option=com_engage&view=reports');
r('DN-23', 'un Registrado sin acceso al componente no ve la cola (no 200 con datos)', !str_contains($q0['body'], 'nota de a') && !str_contains($q0['body'], 'r3rep'), "HTTP {$q0['code']}");
$qg = (new Cliente($BASE, "$WORK/tmp", 'd9g2'))->get('/administrator/index.php?option=com_engage&view=reports');
r('DN-24', 'un invitado tampoco', !str_contains($qg['body'], 'nota de a') && !str_contains($qg['body'], 'r3rep'));
$qm = $mgr->get('/administrator/index.php?option=com_engage&view=reports');
r('DN-25', 'un Manager ve la cola con el denunciante, el motivo y la nota', $qm['code'] === 200 && str_contains($qm['body'], 'r3rep') && str_contains($qm['body'], 'nota de a') && str_contains($qm['body'], 'R3B-PUBLICADO'), "HTTP {$qm['code']}");
$tkm = Cliente::token($qm['body']);
$rid = (int) one("SELECT id FROM jos_engage_reports WHERE user_id={$uid['a']} AND comment_id=$pub");
$x = $mgr->post('/administrator/index.php?option=com_engage&task=reports.dismiss', ['report_id' => $rid, 'comment_id' => $pub]);
r('DN-26', 'descartar sin token: 403 y sin cambios', $x['code'] === 403 && (int) one("SELECT status FROM jos_engage_reports WHERE id=$rid") === 0, "HTTP {$x['code']}");
$x = $mgr->get("/administrator/index.php?option=com_engage&task=reports.dismiss&report_id=$rid&comment_id=$pub&$tkm=1");
r('DN-27', 'descartar por GET: 403 y sin cambios', $x['code'] === 403 && (int) one("SELECT status FROM jos_engage_reports WHERE id=$rid") === 0, "HTTP {$x['code']}");
$x = $mgr->post('/administrator/index.php?option=com_engage&task=reports.dismiss', ['report_id' => $rid, 'comment_id' => $pub, $tkm => 1]);
r('DN-28', 'descartar con token (Manager): la denuncia queda descartada y el comentario sigue publicado', (int) one("SELECT status FROM jos_engage_reports WHERE id=$rid") === 1 && $enabled($pub) === 1 && (int) one("SELECT handled_by FROM jos_engage_reports WHERE id=$rid") === $uid['mgr'], "HTTP {$x['code']}");
$x = $reg->post('/administrator/index.php?option=com_engage&task=reports.unpublish', ['report_id' => (int) one("SELECT id FROM jos_engage_reports WHERE comment_id={$extras[0]}"), 'comment_id' => $extras[0], Cliente::token($reg->get('/')['body']) => 1]);
r('DN-29', 'un Registrado no puede despublicar por la cola (403) y el comentario sigue igual', $enabled($extras[0]) === 1, "HTTP {$x['code']}");
$rid2 = (int) one("SELECT id FROM jos_engage_reports WHERE comment_id={$extras[0]}");
$x = $mgr->post('/administrator/index.php?option=com_engage&task=reports.unpublish', ['report_id' => $rid2, 'comment_id' => $extras[0], $tkm => 1]);
r('DN-30', 'despublicar con token (Manager): el comentario deja de estar publicado y la denuncia queda atendida', $enabled($extras[0]) === 0 && (int) one("SELECT status FROM jos_engage_reports WHERE id=$rid2") === 2, "HTTP {$x['code']}");
$qa = $mgr->get('/administrator/index.php?option=com_engage&view=reports&status=0&reason=2');
r('DN-31', 'los filtros de la cola (estado y motivo) funcionan', $qa['code'] === 200 && str_contains($qa['body'], 'r3repc') && !str_contains($qa['body'], 'nota de a'));
$cp = $mgr->get('/administrator/index.php?option=com_engage&view=controlpanel');
r('DN-32', 'el panel de control enlaza a la cola', str_contains($cp['body'], 'view=reports'));

// ---- 5) aviso agrupado
q("DELETE FROM jos_engage_reports"); q("UPDATE jos_engage_reports SET notified_on=NULL");
foreach (array_slice($extras, 1, 3) as $i => $id) { q("UPDATE jos_engage_comments SET enabled=1 WHERE id=$id"); }
$envia($a, $extras[1], 1); $envia($a, $extras[2], 2); $envia($cc, $extras[1], 3);
foreach (glob("$mailDir/*.eml") ?: [] as $f) { @unlink($f); }
$setCom(['reports_notify' => '1', 'retention_lastRun' => 0]); $g->get($PAGE); usleep(2500000);
$mails = glob("$mailDir/*.eml") ?: []; $dest = []; $todos = '';
foreach ($mails as $f) { $raw = (string) file_get_contents($f); $todos .= $raw; $dest[] = preg_match('/^To:\s*(.+)$/mi', $raw, $m) ? strtolower(trim($m[1])) : '?'; }
$porDest = array_count_values($dest);
r('DN-33', 'con 3 denuncias nuevas se envia UN correo por moderador (no uno por denuncia) con el total', $mails && max($porDest) === 1 && preg_match('/\b3\b/', $todos) === 1, count($mails) . ' correos: ' . json_encode($porDest));
r('DN-34', 'el correo no lleva el nombre de quien denuncia, ni la nota, ni el texto del comentario', !str_contains($todos, 'r3rep') && !str_contains($todos, 'nota de a') && !str_contains($todos, 'R3B-LIM'));
foreach (glob("$mailDir/*.eml") ?: [] as $f) { @unlink($f); }
$setCom(['retention_lastRun' => 0]); $g->get($PAGE); usleep(2000000);
r('DN-35', 'una segunda pasada no vuelve a avisar de las mismas denuncias', !(glob("$mailDir/*.eml") ?: []));
$setCom(['reports_notify' => '0']);

// ---- 6) cuenta borrada
$bef = (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['c']}");
shell_exec('cd ' . escapeshellarg($SITE) . ' && php cli/joomla.php user:delete --username=r3repc -n 2>&1');
r('DN-36', 'al borrar la cuenta se borran sus denuncias', $bef > 0 && (int) one("SELECT COUNT(*) FROM jos_engage_reports WHERE user_id={$uid['c']}") === 0, "antes=$bef");
resumen('denuncias-0914');
