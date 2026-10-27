<?php
/** 0.9.14: datos para lib/denuncias-0914-navegador.js. `crear` | `borrar`. Cuentas r3rep (registrada antigua) y r3mgr (Manager con acceso al componente), y comentarios D9. */
require __DIR__ . '/revision-082-comun.php';
$f = "$WORK/denuncias-datos.json";
if (($argv[1] ?? '') === 'borrar') {
	$o = is_file($f) ? json_decode(file_get_contents($f), true) : [];
	if (!empty($o['tours'])) { foreach ($o['tours'] as $t) { q("UPDATE jos_guidedtours SET autostart=1 WHERE id=" . (int) $t); } }
	if (isset($o['acl'])) { q("UPDATE jos_assets SET rules='" . esc($o['acl']) . "' WHERE name='com_engage'"); }
	q("DELETE FROM jos_engage_reports"); q("DELETE FROM jos_engage_comments WHERE name LIKE 'D9N-%'"); borraUsuarios(); @unlink($f); echo "ok borrar\n"; exit;
}
$acl = (string) one("SELECT rules FROM jos_assets WHERE name='com_engage'");
$rules = json_decode($acl, true) ?: []; $rules['core.manage'] = ['6' => 1]; $rules['core.edit.state'] = ['6' => 1];
q("UPDATE jos_assets SET rules='" . esc(json_encode($rules)) . "' WHERE name='com_engage'");
$a = usuario('r3rep', 2); $m = usuario('r3mgr', 6);
q("UPDATE jos_users SET registerDate='2026-01-01 10:00:00' WHERE id IN ($a,$m)");
q("DELETE FROM jos_engage_comments WHERE name LIKE 'D9N-%'"); q("DELETE FROM jos_engage_reports");
foreach ([1, 2, 3] as $i) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>D9 texto $i del comentario</p>','D9N-$i','d9$i@r3a.invalid','198.51.100.4$i','D9UA',1,'2026-10-01 10:0$i:00',0)"); }
// Joomla lanza solo la visita guiada a quien entra por primera vez y redirige a la portada: se apaga mientras duran las pruebas y se restaura al borrar
$tours = array_map('intval', array_column(q("SELECT id FROM jos_guidedtours WHERE autostart=1")->fetch_all(MYSQLI_ASSOC), 'id')); q("UPDATE jos_guidedtours SET autostart=0");
file_put_contents($f, json_encode(['acl' => $acl, 'tours' => $tours])); echo "ok crear\n";
