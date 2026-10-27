<?php
/** 0.9.11: opciones del componente / plugin Gravatar / acceso de plugins para las pruebas del avatar. `php avatar-opciones.php com k=v ...` | `gravatar mode` | `access element folder level` | `restore` */
require __DIR__ . '/revision-082-comun.php';
$f = "$WORK/avatar-orig.json"; $o = is_file($f) ? json_decode(file_get_contents($f), true) : [];
$guarda = function (string $k, string $sql) use (&$o, $f) { if (!isset($o[$k])) { $o[$k] = (string) one($sql); file_put_contents($f, json_encode($o)); } };
$cmd = $argv[1] ?? '';
if ($cmd === 'com') { $guarda('com', "SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"); $p = json_decode($o['com'], true) ?: []; foreach (array_slice($argv, 2) as $kv) { [$k, $v] = explode('=', $kv, 2); $p[$k] = $v; } q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); }
elseif ($cmd === 'gravatar') { $guarda('grav', "SELECT IFNULL(params,'') FROM jos_extensions WHERE element='gravatar' AND folder='engage'"); $p = json_decode($o['grav'], true) ?: []; $p['mode'] = $argv[2]; q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='gravatar' AND folder='engage'"); }
elseif ($cmd === 'access') { $el = esc($argv[2]); $fo = esc($argv[3]); $guarda("acc-$el-$fo", "SELECT access FROM jos_extensions WHERE element='$el' AND folder='$fo'"); q("UPDATE jos_extensions SET access=" . (int) $argv[4] . " WHERE element='$el' AND folder='$fo'"); }
elseif ($cmd === 'restore') {
	foreach ($o as $k => $v) {
		if ($k === 'com') { q("UPDATE jos_extensions SET params='" . esc($v) . "' WHERE element='com_engage' AND type='component'"); }
		elseif ($k === 'grav') { q("UPDATE jos_extensions SET params='" . esc($v) . "' WHERE element='gravatar' AND folder='engage'"); }
		elseif (str_starts_with($k, 'acc-')) { [, $el, $fo] = explode('-', $k, 3); q("UPDATE jos_extensions SET access=" . (int) $v . " WHERE element='" . esc($el) . "' AND folder='" . esc($fo) . "'"); }
	}
	@unlink($f);
}
echo "ok\n";
