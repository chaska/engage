<?php
/** 0.9.16: datos para lib/permisos-0916-navegador.js. `crear` | `borrar`. Deja el activo com_engage sin Allow de Registrado en «Editar los suyos» y «Borrar», y restaura todo al borrar. */
require __DIR__ . '/revision-082-comun.php';
$f = "$WORK/permisos-datos.json";
if (($argv[1] ?? '') === 'borrar') {
	$o = is_file($f) ? json_decode(file_get_contents($f), true) : [];
	if (isset($o['acl'])) { q("UPDATE jos_assets SET rules='" . esc($o['acl']) . "' WHERE name='com_engage'"); }
	foreach ($o['tours'] ?? [] as $t) { q("UPDATE jos_guidedtours SET autostart=1 WHERE id=" . (int) $t); }
	q("DELETE FROM jos_action_logs WHERE message_language_key='COM_ENGAGE_USERLOG_PERMISSIONS_CHANGED'");
	@unlink($f); echo "ok borrar\n"; exit;
}
$acl = (string) one("SELECT rules FROM jos_assets WHERE name='com_engage'");
$rules = json_decode($acl, true) ?: []; unset($rules['core.edit.own'], $rules['core.delete']);
q("UPDATE jos_assets SET rules='" . esc(json_encode($rules, JSON_FORCE_OBJECT)) . "' WHERE name='com_engage'");
$tours = array_map('intval', array_column(q("SELECT id FROM jos_guidedtours WHERE autostart=1")->fetch_all(MYSQLI_ASSOC), 'id')); q("UPDATE jos_guidedtours SET autostart=0");
file_put_contents($f, json_encode(['acl' => $acl, 'tours' => $tours])); echo "ok crear\n";
