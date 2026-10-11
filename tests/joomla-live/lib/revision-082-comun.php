<?php
/**
 * 0.8.3: preludio comun de las pruebas de la revision independiente de la 0.8.2 (tests/joomla-live/22-revision-082.sh).
 * Conexion a la base de datos, clientes HTTP (invitado, Registered ajeno, Registered autor, Manager), siembra de datos con marcas unicas, registro de
 * resultados y restauracion al acabar. Requiere WORK, SITE_DIR, IDS_FILE, DB_NAME, DB_USER y BASE_URL (los define config.sh).
 */
require_once __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$SITE = $WORK . '/' . (getenv('SITE_DIR') ?: 'site');
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-9s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [" . substr($ev, 0, 300) . "]" : '');
}
function q(string $sql) { global $db; $x = $db->query($sql); if ($x === false) { die("SQL: $sql -> {$db->error}\n"); } return $x; }
function one(string $sql) { $x = q($sql)->fetch_row(); return $x[0] ?? null; }
function esc(string $s): string { global $db; return $db->real_escape_string($s); }
function resumen(string $seccion): void
{
	global $RES;
	$ko = count(array_filter($RES, fn($x) => $x['estado'] === 'FALLA'));
	printf("RESUMEN %s: %d PASA / %d FALLA\n", $seccion, count($RES) - $ko, $ko);
	@file_put_contents((getenv('WORK') ?: '/tmp') . "/resultados-revision-082-$seccion.json", json_encode($RES, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	exit($ko ? 1 : 0);
}

$ASSET_PUB = (int) $ids['art_publico_asset'];
$ASSET_RES = (int) $ids['art_restringido_asset'];
$ASSET_UNP = (int) $ids['art_despublicado_asset'];
$ART = (int) $ids['art_publico']; $CAT = (int) $ids['cat_publica'];
$PAGE = "/index.php?option=com_content&view=article&id=$ART&catid=$CAT";
$PHPLOG = "$WORK/php-errors-" . (getenv('SITE_DIR') ?: 'site') . '.log';

$passFile = "$WORK/rev082pass.txt";
if (!is_file($passFile)) { file_put_contents($passFile, 'Aa1!' . bin2hex(random_bytes(10))); chmod($passFile, 0600); }
$PASS = trim(file_get_contents($passFile));

/** Crea (o reinicia la contrasena de) un usuario de prueba; devuelve su id. $grupo: 2 Registered, 6 Manager, 8 Super Users. */
function usuario(string $user, int $grupo): int
{
	global $PASS;
	$id = (int) one("SELECT id FROM jos_users WHERE username='$user'"); $h = password_hash($PASS, PASSWORD_BCRYPT);
	if (!$id) {
		q("INSERT INTO jos_users (name, username, email, password, block, sendEmail, registerDate, activation, params, resetCount, otpKey, otep, requireReset, authProvider) VALUES ('$user', '$user', '$user@example.invalid', '$h', 0, 0, NOW(), '', '{}', 0, '', '', 0, '')");
		global $db; $id = (int) $db->insert_id; q("INSERT INTO jos_user_usergroup_map (user_id, group_id) VALUES ($id, $grupo)");
	} else { q("UPDATE jos_users SET password='$h', block=0 WHERE id=$id"); }
	return $id;
}
function borraUsuarios(): void { foreach (q("SELECT id FROM jos_users WHERE username LIKE 'r3%'")->fetch_all() as [$id]) { q("DELETE FROM jos_user_usergroup_map WHERE user_id=$id"); q("DELETE FROM jos_users WHERE id=$id"); } }

/**
 * Siembra comentarios con marcas unicas en cada campo personal. Devuelve [clave => id]. Todos llevan name que empieza por «R3N-» para borrarlos.
 */
function siembra(array $uid): array
{
	global $ASSET_PUB, $ASSET_RES, $ASSET_UNP;
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%' OR body LIKE '%R3B-%'");
	$defs = [
		'publicado' => [$ASSET_PUB, 1, 0], 'spam' => [$ASSET_PUB, -3, 0], 'sinpublicar' => [$ASSET_PUB, 0, 0], 'papelera' => [$ASSET_PUB, -2, 0],
		'restringido' => [$ASSET_RES, 1, 0], 'artsinpublicar' => [$ASSET_UNP, 1, 0], 'delregistrado' => [$ASSET_PUB, 1, $uid['reg']],
	];
	$out = [];
	foreach ($defs as $k => [$asset, $en, $by]) {
		$n = strtoupper($k);
		$name = $by ? 'NULL' : "'R3N-$n'"; $mail = $by ? 'NULL' : "'r3mail-$k@privado.invalid'";
		q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset,NULL,'<p>R3B-$n texto del comentario</p>',$name,$mail,'198.51.100." . (count($out) + 11) . "','R3UA-$n',$en,'2026-09-01 10:00:00',$by)");
		global $db; $out[$k] = (int) $db->insert_id;
	}
	return $out;
}
/** Rastros personales de un comentario sembrado que NO deben salir a quien no modera. */
function rastros(string $k, array $ids): array
{
	$n = strtoupper($k);
	return ["R3B-$n", "R3N-$n", "r3mail-$k@privado.invalid", '198.51.100.', "R3UA-$n"];
}
function sinRastros(string $body, array $lista): array { $x = []; foreach ($lista as $m) { if (stripos($body, $m) !== false) { $x[] = $m; } } return $x; }
/** Normaliza una respuesta de error para comparar «existe» con «no existe»: sin tokens, nonces ni marcas de tiempo. */
function normaliza(string $b): string { $b = preg_replace('/[0-9a-f]{32}/', 'T', $b); $b = preg_replace('/\b20\d\d-\d\d-\d\d[ T][\d:]+/', 'F', $b); return preg_replace('/\s+/', ' ', $b); }
function logPhp(): int { global $PHPLOG; return is_file($PHPLOG) ? (int) filesize($PHPLOG) : 0; }
function logNuevo(int $desde): string { global $PHPLOG; return is_file($PHPLOG) ? (string) substr((string) file_get_contents($PHPLOG), $desde) : ''; }
