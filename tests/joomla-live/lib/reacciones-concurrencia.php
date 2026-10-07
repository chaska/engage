<?php
/**
 * 0.7.1: CARRERA entre «me gusta» y «no me gusta» del MISMO usuario sobre el MISMO comentario, contra un Joomla real y con MariaDB/MySQL forzado
 * a READ COMMITTED (sin bloqueos de hueco: el caso en que la transaccion de la 0.7.0 no bastaba y podian quedar los DOS estados). Arranca un
 * servidor `php -S` propio con 16 procesos (PHP_CLI_SERVER_WORKERS) y lanza RONDAS de 8 peticiones «like» + 8 «dislike» simultaneas (curl_multi,
 * misma sesion y mismo token). Tras cada ronda cuenta las filas me gusta/no me gusta de (comentario, usuario): 0 o 1 es correcto, 2 es un estado doble.
 * Se repite con REPEATABLE READ (el valor por defecto de InnoDB) y con SERIALIZABLE. Restaura el nivel de aislamiento global al acabar.
 * Variables: RONDAS (por defecto 25), BASE_URL/PORT (el puerto del sitio: Joomla tiene live_site fijo, asi que se REEMPLAZA el servidor normal por uno de 16 procesos y al acabar se restaura con lib/servidor.sh). Requisitos: 17-reacciones.sh (usuarios de prueba) y el paquete instalado.
 * Escribe $WORK/resultados-reacciones-concurrencia.json. Sale con 1 si algun nivel produce estados dobles.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$SITE = "$WORK/" . (getenv('SITE_DIR') ?: 'site');
$PORT = (int) (getenv('PORT') ?: 8080);
$BASE = "http://127.0.0.1:$PORT";
$ROUNDS = (int) (getenv('RONDAS') ?: 25);
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
function root(string $sql): string { return trim((string) shell_exec('mysql -N -e ' . escapeshellarg($sql) . ' 2>&1')); }
$ASSET = (int) $ids['art_publico_asset'];
$PASS  = trim(file_get_contents("$WORK/reaccionespass.txt") ?: '') ?: die("Falta reaccionespass.txt (ejecuta antes reacciones-http.php)\n");
// MariaDB < 11.1 llama a la variable tx_isolation; MySQL 8 y MariaDB recientes, transaction_isolation
function iso(): string { $v = root('SELECT @@global.transaction_isolation'); return (stripos($v, 'ERROR') === false && $v !== '') ? $v : root('SELECT @@global.tx_isolation'); }
$prevIso = iso();
$prevCom = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");

// Servidor con 16 procesos en el mismo puerto (el sitio tiene live_site fijo): se para el normal y se restaura al final
$pidf = "$WORK/php-server-$PORT.pid";
if (is_file($pidf)) { shell_exec('kill ' . (int) file_get_contents($pidf) . ' 2>/dev/null'); sleep(1); }
$pid = trim((string) shell_exec(sprintf('cd %s && (PHP_CLI_SERVER_WORKERS=16 setsid nohup php -d error_reporting=-1 -d display_errors=0 -d log_errors=1 -d error_log=%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!)',
	escapeshellarg($WORK), escapeshellarg("$WORK/php-errors-concurrencia.log"), $PORT, escapeshellarg($SITE), escapeshellarg(__DIR__ . '/router.php'))));
register_shutdown_function(function () use ($pid, $prevIso, $prevCom, $ASSET, $db) {
	if ($pid) { shell_exec('kill ' . (int) $pid . ' 2>/dev/null'); sleep(1); }
	shell_exec('bash ' . escapeshellarg(__DIR__ . '/servidor.sh') . ' >/dev/null 2>&1');
	root('SET GLOBAL TRANSACTION ISOLATION LEVEL ' . str_replace('-', ' ', $prevIso));
	$db->query("UPDATE jos_extensions SET params='" . $db->real_escape_string($prevCom) . "' WHERE element='com_engage' AND type='component'");
	$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$ASSET AND name LIKE 'conc_%'");
	$db->query('DELETE FROM jos_engage_reactions');
});
for ($i = 0; $i < 40; $i++) { $c = @file_get_contents("$BASE/"); if ($c !== false) { break; } usleep(250000); }

$p = ['default_publish' => '1', 'max_level' => '3', 'comments_ordering' => 'asc'];
q("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
$uid = (int) one("SELECT id FROM jos_users WHERE username='reacreg1'");
if (!$uid) { die("Falta el usuario reacreg1 (ejecuta antes reacciones-http.php)\n"); }
q("UPDATE jos_users SET password='" . $db->real_escape_string(password_hash($PASS, PASSWORD_BCRYPT)) . "', block=0 WHERE id=$uid");
q("INSERT INTO jos_engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET,'<p>carrera</p>','conc_inv','conc_inv@example.invalid','203.0.113.7','t',1,NOW(),0)");
$cid = (int) $db->insert_id;
$A = new Cliente($BASE, "$WORK/tmp", 'conc-a');
if (!$A->login('reacreg1', $PASS)) { die("No se pudo iniciar sesion\n"); }
$st = json_decode($A->get("/index.php?option=com_engage&task=reactions.state&format=json&ids=$cid")['body'], true);
$tok = $st['token'] ?? ''; if ($tok === "") { die("Sin token: " . $A->ultimo["code"] . " " . substr($A->ultimo["body"], 0, 200) . "\n"); }
$reset = function () use ($WORK) { foreach (['/cache', '/administrator/cache'] as $d) { exec('rm -rf ' . escapeshellarg("$WORK/" . (getenv('SITE_DIR') ?: 'site') . "$d/com_engage_reactions")); } };

$ronda = function () use ($BASE, $A, $cid, $tok, $uid, $reset): array {
	q('DELETE FROM jos_engage_reactions'); $reset();
	$mh = curl_multi_init(); $hs = [];
	for ($i = 0; $i < 16; $i++) {
		$ch = curl_init("$BASE/index.php?option=com_engage&task=reactions.toggle&format=json");
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['comment_id' => $cid, 'type' => $i % 2 ? 'dislike' : 'like', $tok => 1]),
			CURLOPT_COOKIEFILE => $A->jar, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'conc/1.0']);
		curl_multi_add_handle($mh, $ch); $hs[] = $ch;
	}
	do { $r = curl_multi_exec($mh, $act); if ($act) { curl_multi_select($mh, 0.2); } } while ($act && $r === CURLM_OK);
	$codes = [];
	foreach ($hs as $ch) { $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($mh, $ch); curl_close($ch); }
	curl_multi_close($mh);
	$n = (int) one("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type IN (1,2)");
	return [$n, $codes];
};

// ---- Modelo determinista de la carrera (dos conexiones, intercalado a mano) con READ COMMITTED: el algoritmo de la 0.7.0 deja los DOS estados;
// el de la 0.7.1 (bloqueo de la fila del comentario primero) obliga a la segunda a esperar y ve el estado de la primera.
$dbx = function () use ($WORK) { $c = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test'); $c->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); return $c; };
$tabla = 'jos_engage_reactions';
$dobles = function (mysqli $x) use ($cid, $uid): int { return (int) $x->query("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type IN (1,2)")->fetch_row()[0]; };
$tiene = fn(mysqli $c, int $tipo) => (int) $c->query("SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type=$tipo")->fetch_row()[0] > 0;
q('DELETE FROM jos_engage_reactions');
$c1 = $dbx(); $c2 = $dbx();
$c1->query('START TRANSACTION'); $c2->query('START TRANSACTION');
$h1 = $tiene($c1, 1); $h2 = $tiene($c2, 2);                                   // ninguna ve nada: las dos van a ANADIR
$c1->query("DELETE FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type=2");   // las dos borran «el otro tipo» (no hay nada) ANTES de que ninguna inserte
$c2->query("DELETE FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type=1");
$c1->query("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($cid,$uid,1,NOW())");
$c2->query("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($cid,$uid,2,NOW())");
$c1->query('COMMIT'); $c2->query('COMMIT');
$n0 = $dobles($c1);
printf("%-6s modelo del algoritmo de la 0.7.0 (sin bloqueo) con READ COMMITTED: filas me gusta/no me gusta tras el intercalado = %d (2 = estado doble: la carrera existe)\n", 'MODELO', $n0);
q('DELETE FROM jos_engage_reactions');
$c1->query('START TRANSACTION'); $c2->query('START TRANSACTION');
$c1->query("SELECT id FROM jos_engage_comments WHERE id=$cid FOR UPDATE");
$c2->query("SELECT id FROM jos_engage_comments WHERE id=$cid FOR UPDATE", MYSQLI_ASYNC);   // debe quedar ESPERANDO
usleep(400000);
$r = [$c2]; $e = []; $rj = [$c2]; $listo = mysqli_poll($r, $e, $rj, 0, 100000) > 0;
$h1 = $tiene($c1, 1);
$c1->query("DELETE FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type=2"); $c1->query("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($cid,$uid,1,NOW())");
$c1->query('COMMIT');
$r = [$c2]; $e = []; $rj = [$c2]; mysqli_poll($r, $e, $rj, 5); $c2->reap_async_query();
$h2 = $tiene($c2, 1);                                                          // ahora SI ve el me gusta de la primera
if ($h2) { $c2->query("DELETE FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type=1"); }
$c2->query("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($cid,$uid,2,NOW())"); $c2->query('COMMIT');
$n1 = $dobles($c1); $tipoFinal = (int) $c1->query("SELECT type FROM jos_engage_reactions WHERE comment_id=$cid AND user_id=$uid AND type IN (1,2)")->fetch_row()[0];
$okM = !$listo && $h2 && $n1 === 1 && $tipoFinal === 2;
printf("%-6s modelo del algoritmo de la 0.7.1 (SELECT ... FOR UPDATE del comentario primero) con READ COMMITTED: la segunda transaccion ESPERA (esperando=%s), ve el me gusta de la primera y queda 1 solo estado (filas=%d, tipo=%d)\n", $okM ? 'PASA' : 'FALLA', $listo ? 'no' : 'si', $n1, $tipoFinal);
$c1->close(); $c2->close(); q('DELETE FROM jos_engage_reactions');

$RES = []; $mal = $okM ? 0 : 1;
foreach (['READ-COMMITTED' => 'READ COMMITTED', 'REPEATABLE-READ' => 'REPEATABLE READ', 'SERIALIZABLE' => 'SERIALIZABLE'] as $k => $nivel) {
	root('SET GLOBAL TRANSACTION ISOLATION LEVEL ' . $nivel);
	$ef = iso();
	$dist = [0 => 0, 1 => 0, 2 => 0]; $no200 = 0; $err = 0;
	for ($i = 0; $i < $ROUNDS; $i++) {
		[$n, $codes] = $ronda();
		$dist[min(2, $n)]++;
		foreach ($codes as $c) { if ($c !== 200) { $no200++; } if ($c >= 500) { $err++; } }
	}
	$ok = $dist[2] === 0;
	$RES[] = ['nivel' => $nivel, 'efectivo' => $ef, 'rondas' => $ROUNDS, 'peticiones' => $ROUNDS * 16, 'sin_estado' => $dist[0], 'un_estado' => $dist[1], 'estados_dobles' => $dist[2], 'respuestas_no_200' => $no200, 'errores_5xx' => $err, 'estado' => $ok ? 'PASA' : 'FALLA'];
	printf("%-6s %-16s (efectivo: %s) %d rondas x (8 like + 8 dislike): 0 estados=%d, 1 estado=%d, ESTADOS DOBLES=%d; respuestas no 200=%d (5xx=%d)\n", $ok ? 'PASA' : 'FALLA', $nivel, $ef, $ROUNDS, $dist[0], $dist[1], $dist[2], $no200, $err);
	if (!$ok) { $mal++; }
}
$ok5 = true;
foreach ($RES as $x) { if ($x['errores_5xx'] > 0) { $ok5 = false; } }
printf("%-6s ninguna de las %d peticiones dio un error 5xx (ni interbloqueos ni tiempos de espera)\n", $ok5 ? 'PASA' : 'FALLA', $ROUNDS * 16 * count($RES));
if (!$ok5) { $mal++; }
file_put_contents("$WORK/resultados-reacciones-concurrencia.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($mal ? 1 : 0);
