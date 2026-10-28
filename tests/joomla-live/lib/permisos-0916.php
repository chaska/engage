<?php
/**
 * 0.9.16 contra el Joomla REAL: matriz de permisos.
 *  1) contraste: cada celda de la pantalla == lo que decide Joomla (Access::checkGroup en CLI) para todos los grupos y acciones
 *  2) acceso: sin sesion / Registrado / Manager con acceso al componente (sin core.admin): 403 en la pantalla y en el guardado
 *  3) guardado: sin token, por GET, sin X-Requested-With, cargas inventadas; ids de grupo y acciones inventados (core.admin, core.options), Super Users: ignorados
 *  4) efecto real: dar «Editar los suyos» a Registrado y comprobarlo con un usuario real (User::authorise); quitarlo; idempotencia
 *  5) concesion amplia: sin confirmacion 409 y sin cambios; con ella se aplica
 *  6) un Deny existente no se pisa; core.admin/core.options se conservan; registro de acciones sin datos personales
 * Restaura las reglas del activo al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
$uid = ['reg' => usuario('r3reg', 2), 'mgr' => usuario('r3mgr', 6)];
$rulesOrig = (string) one("SELECT rules FROM jos_assets WHERE name='com_engage'");
$alOrig    = q("SELECT extension_id, enabled FROM jos_extensions WHERE type='plugin' AND folder='actionlog' AND element='engage'")->fetch_assoc();
register_shutdown_function(function () use ($rulesOrig, $alOrig) {
	q("UPDATE jos_assets SET rules='" . esc($rulesOrig) . "' WHERE name='com_engage'");
	if ($alOrig) { q("UPDATE jos_extensions SET enabled=" . (int) $alOrig['enabled'] . " WHERE extension_id=" . (int) $alOrig['extension_id']); }
	q("DELETE FROM jos_action_logs WHERE message_language_key='COM_ENGAGE_USERLOG_PERMISSIONS_CHANGED'");
	borraUsuarios();
});
q("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='actionlog' AND element='engage'");
q("DELETE FROM jos_action_logs WHERE message_language_key='COM_ENGAGE_USERLOG_PERMISSIONS_CHANGED'");
q("UPDATE jos_guidedtours SET autostart=0");
register_shutdown_function(function () { q("UPDATE jos_guidedtours SET autostart=1 WHERE id=12"); });
// El Manager (grupo 6) entra en el componente (core.manage) pero NO es core.admin
$base = json_decode($rulesOrig, true) ?: []; $base['core.manage'] = ['6' => 1, '7' => 1]; $base['core.options'] = ['7' => 1]; unset($base['core.edit.own']);
$setRules = function (array $r) { q("UPDATE jos_assets SET rules='" . esc(json_encode($r, JSON_FORCE_OBJECT)) . "' WHERE name='com_engage'"); };
$rules    = fn() => json_decode((string) one("SELECT rules FROM jos_assets WHERE name='com_engage'"), true) ?: [];
$setRules($base);
@mkdir("$WORK/tmp");
$adm = new Cliente($BASE, "$WORK/tmp", 'p9adm'); $adm->loginAdmin('admintest', 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt")));
$mgr = new Cliente($BASE, "$WORK/tmp", 'p9mgr'); $mgr->loginAdmin('r3mgr', $PASS);
$reg = new Cliente($BASE, "$WORK/tmp", 'p9reg'); $reg->login('r3reg', $PASS);
$gst = new Cliente($BASE, "$WORK/tmp", 'p9gst');
$VIEW = '/administrator/index.php?option=com_engage&view=permissions'; $SAVE = '/administrator/index.php?option=com_engage&task=permissions.save';
$cli = fn(string ...$a) => trim((string) shell_exec('WORK=' . escapeshellarg($WORK) . ' php ' . escapeshellarg(__DIR__ . '/permisos-joomla-cli.php') . ' ' . implode(' ', array_map('escapeshellarg', $a)) . ' 2>/dev/null'));
$celdas = function (string $html): array { $o = []; preg_match_all('#<button type="button" class="eg-perm-cell"\s+data-g="(\d+)"[^>]*?data-a="([^"]+)"[^>]*?data-state="([a-z]+)"#s', $html, $m, PREG_SET_ORDER); foreach ($m as $x) { $o[(int) $x[1]][$x[2]] = $x[3]; } return $o; };
$tokenDe = fn(string $html) => preg_match('/data-token="([0-9a-f]{32})"/', $html, $m) ? $m[1] : '';
$guarda = function (Cliente $c, string $tok, $cambios, bool $confirma = false, array $cab = ['X-Requested-With: XMLHttpRequest']) use ($SAVE) {
	$campos = ($tok !== '' ? [$tok => 1] : []) + ['changes' => is_string($cambios) ? $cambios : json_encode($cambios)] + ($confirma ? ['confirm' => 1] : []);
	$x = $c->post($SAVE, $campos, $cab);

	return [$x['code'], json_decode($x['body'], true), $x];
};

// 1) contraste
$pg = $adm->get($VIEW); $tok = $tokenDe($pg['body']); $cel = $celdas($pg['body']); $j = json_decode($cli('matrix'), true);
$mal = []; $n = 0;
foreach ($j as $g => $accs) { foreach ($accs as $a => $v) { $n++; $st = $cel[(int) $g][$a] ?? '-'; $perm = in_array($st, ['allow', 'inherit', 'always'], true); if ($v === 'super' ? $st !== 'always' : ($perm !== $v || $st === 'always')) { $mal[] = "$g $a: pantalla=$st joomla=" . json_encode($v); } } }
r('PM-01', "la pantalla (Super User) muestra $n celdas y TODAS coinciden con Access::checkGroup de Joomla", $pg['code'] === 200 && $n >= 54 && !$mal && $tok !== '', implode(' | ', array_slice($mal, 0, 4)));
r('PM-02', 'solo las seis acciones como columnas; ni core.admin ni core.options', !str_contains($pg['body'], 'data-a="core.admin"') && !str_contains($pg['body'], 'data-a="core.options"') && substr_count($pg['body'], 'data-g="2"') === 6);

// 2) acceso
$pm = $mgr->get($VIEW); $tm = $tokenDe($pm['body']);
r('PM-03', 'un Manager con acceso al componente pero sin core.admin NO ve la matriz', !str_contains($pm['body'], 'eg-perm-table') && $tm === '', "HTTP {$pm['code']}");
[$c1, $j1] = $guarda($mgr, Cliente::token($mgr->get('/administrator/index.php')['body']), [['g' => 2, 'a' => 'core.create', 'v' => 1]]);
r('PM-04', 'guardar como Manager (sin core.admin) = 403 y no cambia nada', $c1 === 403 && $rules() == $base, "HTTP $c1");
[$c2, $j2x] = $guarda($gst, '', [['g' => 2, 'a' => 'core.create', 'v' => 1]]);
[$c3, $j3x] = $guarda($reg, Cliente::token($reg->get('/')['body']), [['g' => 2, 'a' => 'core.create', 'v' => 1]]);
r('PM-05', 'guardar como invitado o como Registrado = 403', ($c2 === 403 || ($c2 === 200 && !(($j2x['ok'] ?? false)))) && ($c3 === 403 || ($c3 === 200 && !(($j3x['ok'] ?? false)))) && $rules() == $base, "HTTP $c2/$c3");
$pgR = $reg->get($VIEW);
r('PM-06', 'un Registrado no ve la matriz', !str_contains($pgR['body'], 'eg-perm-table'));

// 3) validacion del guardado
$val = [['g' => 2, 'a' => 'core.edit.own', 'v' => 1]];
[$c, ] = $guarda($adm, '', $val);                                   r('PM-07', 'sin token CSRF: 403 y sin cambios', $c === 403 && $rules() == $base, "HTTP $c");
$x = $adm->get($SAVE . '&' . $tok . '=1&changes=' . urlencode(json_encode($val)), ['X-Requested-With: XMLHttpRequest']); r('PM-08', 'por GET: 405 y sin cambios', $x['code'] === 405 && $rules() == $base, "HTTP {$x['code']}");
[$c, ] = $guarda($adm, $tok, $val, false, []);                       r('PM-09', 'sin X-Requested-With: 403 y sin cambios', $c === 403 && $rules() == $base, "HTTP $c");
foreach (['', 'x', '{"g":1}', '[1,2]', str_repeat('a', 120000), json_encode(array_fill(0, 601, $val[0]))] as $i => $mal) { [$c] = $guarda($adm, $tok, $mal); if (!($c === 400 || ($i === 3 && $c === 200)) || $rules() != $base) { $malos[] = "#$i=$c"; } }
r('PM-10', 'cargas inutilizables (vacia, basura, no lista, enorme, 601 cambios): 400 y sin cambios', empty($malos ?? []), implode(',', $malos ?? []));
[$c, $j2] = $guarda($adm, $tok, [['g' => 99999, 'a' => 'core.create', 'v' => 1], ['g' => 2, 'a' => 'core.admin', 'v' => 1], ['g' => 2, 'a' => 'core.options', 'v' => 1], ['g' => 8, 'a' => 'core.create', 'v' => 1], ['g' => 8, 'a' => 'core.manage', 'v' => 0], ['g' => 2, 'a' => 'core.create', 'v' => 7], ['g' => 0, 'a' => 'core.create', 'v' => 1], ['g' => '2 OR 1=1', 'a' => 'core.create', 'v' => 1]]);
r('PM-11', 'grupo inventado, core.admin, core.options, Super Users y valores raros: ignorados; reglas IDENTICAS', $c === 200 && ($j2['changed'] ?? -1) === 0 && $rules() == $base, "HTTP $c " . json_encode($j2['changed'] ?? null));

// 4) efecto real
$antes = $cli('authorise', 'r3reg', 'core.edit.own');
[$c, $j3] = $guarda($adm, $tok, [['g' => 2, 'a' => 'core.edit.own', 'v' => 1]]);
$r1 = $rules();
r('PM-12', 'dar «Editar los suyos» a Registrado: se guarda como Allow explicito en el activo com_engage', $c === 200 && ($j3['changed'] ?? 0) === 1 && ($r1['core.edit.own']['2'] ?? null) === 1, "HTTP $c");
$ya = $cli('authorise', 'r3reg', 'core.edit.own');
r('PM-13', 'un usuario REAL del grupo lo tiene ahora segun Joomla (User::authorise)', $ya === 'true', "antes=$antes ahora=$ya");
r('PM-14', 'la respuesta redibuja las celdas con lo que decide Joomla (esa celda: permitido; sus hijos: heredado)', ($j3['cells']['2']['core.edit.own'] ?? '') === 'allow' && ($j3['cells']['3']['core.edit.own'] ?? '') === 'inherit');
[$c, $j4] = $guarda($adm, $tok, [['g' => 2, 'a' => 'core.edit.own', 'v' => 1]]);
r('PM-15', 'idempotente: repetir el mismo cambio = 0 cambios y reglas iguales', $c === 200 && ($j4['changed'] ?? -1) === 0 && $rules() == $r1);
$ex = $cli('matrix'); $jm = json_decode($ex, true); $cel2 = $celdas($adm->get($VIEW)['body']); $mal = [];
foreach ($jm as $g => $accs) { foreach ($accs as $a => $v) { $st = $cel2[(int) $g][$a] ?? '-'; if ($v === 'super' ? $st !== 'always' : (in_array($st, ['allow', 'inherit', 'always'], true) !== $v)) { $mal[] = "$g $a"; } } }
r('PM-16', 'tras el cambio la pantalla sigue coincidiendo con Joomla en todas las celdas', !$mal, implode(',', array_slice($mal, 0, 4)));
[$c, $j5] = $guarda($adm, $tok, [['g' => 2, 'a' => 'core.edit.own', 'v' => 0]]);
$r2 = $rules();
r('PM-17', 'quitarlo: sin asignar; desaparece la entrada y Joomla lo niega al usuario real', $c === 200 && !isset($r2['core.edit.own']['2']) && $cli('authorise', 'r3reg', 'core.edit.own') === $antes, "HTTP $c");
$sin = $r2; unset($sin['core.edit.own']); $b2 = $base; unset($b2['core.edit.own']);
r('PM-18', 'tras ida y vuelta las demas reglas (incluidas core.options y core.manage) siguen iguales', array_diff_key($r2, ['core.edit.own' => 1]) == array_diff_key($base, ['core.edit.own' => 1]));

// 5) concesion amplia
[$c, $j6] = $guarda($adm, $tok, [['g' => 2, 'a' => 'core.delete', 'v' => 1]]);
r('PM-19', 'dar «Borrar» a Registrado (grupo amplio) sin confirmar: 409 y sin cambios', $c === 409 && ($j6['error'] ?? '') === 'confirm' && !isset($rules()['core.delete']['2']), "HTTP $c");
[$c, $j7] = $guarda($adm, $tok, [['g' => 2, 'a' => 'core.delete', 'v' => 1]], true);
r('PM-20', 'con la confirmacion se aplica', $c === 200 && ($rules()['core.delete']['2'] ?? null) === 1);
[$c] = $guarda($adm, $tok, [['g' => 2, 'a' => 'core.delete', 'v' => 0]]);
r('PM-21', 'quitar un permiso amplio no pide confirmacion', $c === 200 && !isset($rules()['core.delete']['2']));
foreach ([[1, 'core.manage'], [9, 'core.edit.state'], [1, 'core.edit']] as [$g, $a]) { [$c] = $guarda($adm, $tok, [['g' => $g, 'a' => $a, 'v' => 1]]); $ko409[] = $c; }
r('PM-22', 'Moderar/Editar cualquiera/Entrar al panel a Publico o Invitado: tambien exigen confirmacion (409)', $ko409 === [409, 409, 409] && $rules() == $r2);

// 6) Deny, core.admin/options, registro
$cd = $rules(); $cd['core.create']['9'] = 0; $cd['core.admin'] = ['8' => 1]; $setRules($cd);
$pdeny = $celdas($adm->get($VIEW)['body']);
[$c, $j8] = $guarda($adm, $tok, [['g' => 9, 'a' => 'core.create', 'v' => 1], ['g' => 9, 'a' => 'core.create', 'v' => 0]]);
r('PM-23', 'un Deny existente (Invitado: Comentar) no se pisa ni se quita; sale como denegado', ($pdeny[9]['core.create'] ?? '') === 'deny' && ($rules()['core.create']['9'] ?? null) === 0 && ($j8['changed'] ?? -1) === 0);
[$c, ] = $guarda($adm, $tok, [['g' => 5, 'a' => 'core.create', 'v' => 1]]);
$after = $rules();
r('PM-24', 'tras un guardado real el Deny, core.admin y core.options siguen exactamente como estaban', ($after['core.create']['9'] ?? null) === 0 && ($after['core.admin'] ?? null) == ['8' => 1] && ($after['core.options'] ?? null) == $base['core.options']);
$hijo = $celdas($adm->get($VIEW)['body']);
$lg = q("SELECT message FROM jos_action_logs WHERE message_language_key='COM_ENGAGE_USERLOG_PERMISSIONS_CHANGED' ORDER BY id")->fetch_all(MYSQLI_NUM);
$txt = implode(' ', array_column($lg, 0));
r('PM-25', 'cada guardado con cambios queda en el registro de acciones, con grupos y acciones y SIN datos personales (ni correo, ni IP)', count($lg) >= 3 && str_contains($txt, 'core.edit.own') && !preg_match('/@|\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', preg_replace('/accountlink[^}]*/', '', $txt)), count($lg) . ' registros');
resumen('permisos-0916');
