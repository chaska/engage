<?php
/**
 * 0.7.0: reacciones a los comentarios (me gusta, no me gusta, favorito). Sin Joomla: la logica (Reactions) se ejercita con un
 * almacen en memoria y el limitador con un reloj simulado; ReactionStore se ejercita con una base de datos que REGISTRA cada
 * consulta y cada parametro enlazado, para comprobar que nada de lo recibido llega al SQL. Cubre: sesion y ACL, ids invalidos
 * (cadenas, arrays, negativos, enormes, inyeccion SQL), comentario inexistente / sin publicar / borrado / de contenido que no se
 * puede ver, comentario propio, transiciones me gusta <-> no me gusta <-> nada, favorito independiente y privado, limite de
 * frecuencia, privacidad (exportar y borrar), limpieza al borrar comentarios y usuarios, opciones validadas por el esquema,
 * cadenas en ambos idiomas y seguridad estatica del controlador, la plantilla y el JS. El token CSRF, la ACL real, la base de
 * datos real, el cache de pagina y el navegador se prueban en tests/joomla-live/17-reacciones.sh.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => (string) file_get_contents($f);
$lf   = fn(string $f): string => str_replace("\r\n", "\n", $lee($f));
foreach (['Reactions', 'ReactionStoreInterface', 'ReactionStore', 'ReactionRateLimiter', 'ReactionIcons', 'SettingsSchema', 'SettingsLayout'] as $k) { require_once "$c/backend/src/Helper/$k.php"; }
require __DIR__ . '/stubs/reacciones.php';
use Akeeba\Component\Engage\Administrator\Helper\ReactionIcons;
use Akeeba\Component\Engage\Administrator\Helper\ReactionRateLimiter;
use Akeeba\Component\Engage\Administrator\Helper\Reactions as R;
use Akeeba\Component\Engage\Administrator\Helper\ReactionStore;
use Akeeba\Component\Engage\Administrator\Helper\SettingsLayout;
use Akeeba\Component\Engage\Administrator\Helper\SettingsSchema as S;

$opts = R::options([]);
$ctx = fn(array $o = []): array => array_merge(['userId' => 7, 'canReact' => true, 'opts' => $opts, 'canViewAsset' => fn(int $a): bool => $a !== 666, 'allowRate' => fn(int $u): bool => true, 'now' => '2026-10-07 10:00:00'], $o);
$mk = function (): array { $s = new MemStore(); $s->comment(10, 1, 1, 99); $s->comment(11, 1, 1, 7); $s->comment(12, 1, 0, 99); $s->comment(13, 1, -3, 99); $s->comment(14, 666, 1, 99); return [new R($s), $s]; };

echo "A) Opciones (valores por defecto y valores ajenos)\n";
t_ok($opts === ['enabled' => true, 'dislike' => true, 'favorites' => true, 'who' => 'registered'], 'por defecto: reacciones, no me gusta y favoritos activos; quien: registrados');
t_ok(R::options(['reactions_enabled' => '0', 'reactions_dislike' => 0, 'reactions_favorites' => '0', 'reactions_who' => 'commenters']) === ['enabled' => false, 'dislike' => false, 'favorites' => false, 'who' => 'commenters'], 'cadenas y enteros 0/1 se aceptan');
t_ok(R::options(['reactions_enabled' => 'yes', 'reactions_dislike' => [1], 'reactions_favorites' => '2', 'reactions_who' => 'admins'])['who'] === 'registered' && R::options(['reactions_enabled' => 'yes'])['enabled'] === true, 'valores ajenos vuelven al valor por defecto');
t_ok(R::options(fn($k, $d) => $k === 'reactions_who' ? 'commenters' : $d)['who'] === 'commenters', 'acepta una funcion lectora (Registry de Joomla)');

echo "B) Identificadores y tipos\n";
foreach ([1, '1', '123456789012345678', 42] as $v) { t_ok(R::parseId($v) > 0, 'id valido: ' . var_export($v, true)); }
foreach (['0', 0, -1, '-1', '1.5', 1.5, '01', ' 1', '1 ', "1\n", '1e3', '0x1', 'abc', '', null, true, false, [], [1], ['1'], new stdClass(), '1234567890123456789', '99999999999999999999', PHP_INT_MAX . '', "1'", '1; DROP TABLE x', '1 OR 1=1', "1\0", '１'] as $v) { t_ok(R::parseId($v) === 0, 'id NO valido: ' . json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR)); }
t_ok(R::parseIds('1,2,3') === [1, 2, 3] && R::parseIds('5,5,5') === [5] && R::parseIds('7') === [7], 'lista: ids validos, sin repetidos');
foreach ([null, '', ',', '1,', ',1', '1,,2', '1,a', '1;2', '1 ,2', ['1'], '1,2,-3', '1,2,0', "1,2\n3", '1,2) OR (1=1', implode(',', range(1, 102))] as $v) { t_ok(R::parseIds($v) === null, 'lista NO valida: ' . json_encode($v)); }
t_ok(count(R::parseIds(implode(',', range(1, 100)))) === 100 && R::parseIds(implode(',', range(1, 101))) === null, 'maximo 100 ids (101 se rechaza entera)');
t_ok(R::parseType('like') === 1 && R::parseType('dislike') === 2 && R::parseType('favorite') === 3, 'tipos: like 1, dislike 2, favorite 3');
foreach (['', 'Like', 'LIKE', '1', 1, 'love', ['like'], null, "like\0", 'like ', 'favorites'] as $v) { t_ok(R::parseType($v) === 0, 'tipo NO valido: ' . json_encode($v)); }

echo "C) toggle: sesion, permisos, opciones\n";
[$r, $s] = $mk();
$x = $r->toggle($ctx(['userId' => 0, 'canReact' => false]), 10, 'like');
t_ok($x['status'] === 403 && $x['body'] === ['ok' => false, 'error' => 'denied'] && $s->writes === 0, 'invitado: 403 generico y nada escrito');
$x = $r->toggle($ctx(['canReact' => false]), 10, 'like');
t_ok($x['status'] === 403 && $s->writes === 0, 'usuario sin permiso (reactions_who = commenters sin core.create): 403');
$x = $r->toggle($ctx(['opts' => R::options(['reactions_enabled' => '0'])]), 10, 'like');
t_ok($x['status'] === 404 && $s->writes === 0, 'reacciones desactivadas: 404 y nada escrito');
$x = $r->toggle($ctx(['opts' => R::options(['reactions_dislike' => '0'])]), 10, 'dislike');
t_ok($x['status'] === 400 && $s->writes === 0, 'no me gusta desactivado: 400');
$x = $r->toggle($ctx(['opts' => R::options(['reactions_favorites' => '0'])]), 10, 'favorite');
t_ok($x['status'] === 400 && $s->writes === 0, 'favoritos desactivados: 400');
$x = $r->toggle($ctx(['opts' => R::options(['reactions_dislike' => '0'])]), 10, 'like');
t_ok($x['status'] === 200, 'con no me gusta desactivado el me gusta sigue funcionando');

echo "D) toggle: entradas invalidas y comentarios no disponibles\n";
[$r, $s] = $mk();
foreach (['abc', '-5', '0', '', null, ['1'], '1 OR 1=1', "10'; DROP TABLE jos_engage_reactions;--", '10 UNION SELECT 1', '9999999999999999999999', 1.5] as $v) {
	$x = $r->toggle($ctx(), $v, 'like');
	t_ok($x['status'] === 400 && $x['body'] === ['ok' => false, 'error' => 'invalid'], 'comment_id invalido -> 400 generico: ' . json_encode($v));
}
foreach (['', 'x', 'LIKE', ['like'], null, "like'; DROP", '1'] as $v) { t_ok($r->toggle($ctx(), 10, $v)['status'] === 400, 'type invalido -> 400: ' . json_encode($v)); }
t_ok($s->writes === 0 && !$s->rows, 'ninguna entrada invalida escribe nada');
$nd = ['ok' => false, 'error' => 'unavailable'];
t_ok($r->toggle($ctx(), 999, 'like') === ['status' => 404, 'body' => $nd], 'comentario inexistente / borrado: 404');
t_ok($r->toggle($ctx(), 12, 'like') === ['status' => 404, 'body' => $nd], 'comentario sin publicar: 404 (misma respuesta que inexistente)');
t_ok($r->toggle($ctx(), 13, 'favorite') === ['status' => 404, 'body' => $nd], 'comentario de spam: 404 tambien para el favorito');
t_ok($r->toggle($ctx(), 14, 'like') === ['status' => 404, 'body' => $nd], 'contenido que el usuario no puede ver: 404 (misma respuesta)');
t_ok($s->writes === 0, 'nada escrito en ninguno de esos casos');
$s->comment(15, 1, 1, 99);
$x = $r->toggle($ctx(['canViewAsset' => fn() => false]), 15, 'like');
t_ok($x['status'] === 404 && $s->writes === 0, 'sin acceso al contenido: 404');

echo "E) toggle: transiciones\n";
[$r, $s] = $mk();
$x = $r->toggle($ctx(), 10, 'like');
t_ok($x['status'] === 200 && $x['body']['mine'] === ['like' => true, 'dislike' => false, 'favorite' => false] && $x['body']['counts'] === ['like' => 1, 'dislike' => 0] && $x['body']['ok'] === true && $x['body']['id'] === 10, 'nada -> me gusta');
$x = $r->toggle($ctx(), 10, 'dislike');
t_ok($x['body']['mine']['like'] === false && $x['body']['mine']['dislike'] === true && $x['body']['counts'] === ['like' => 0, 'dislike' => 1] && !$s->has(10, 7, 1), 'me gusta -> no me gusta (excluyentes, cambia)');
$x = $r->toggle($ctx(), 10, 'like');
t_ok($x['body']['mine']['like'] === true && $x['body']['mine']['dislike'] === false && $x['body']['counts'] === ['like' => 1, 'dislike' => 0], 'no me gusta -> me gusta');
$x = $r->toggle($ctx(), 10, 'like');
t_ok($x['body']['mine'] === ['like' => false, 'dislike' => false, 'favorite' => false] && $x['body']['counts'] === ['like' => 0, 'dislike' => 0] && !$s->rows, 'volver a pulsar me gusta lo quita');
$r->toggle($ctx(), 10, 'dislike'); $x = $r->toggle($ctx(), 10, 'dislike');
t_ok($x['body']['mine']['dislike'] === false && !$s->rows, 'volver a pulsar no me gusta lo quita');
$x = $r->toggle($ctx(), 10, 'favorite');
t_ok($x['body']['mine']['favorite'] === true && $s->has(10, 7, 3) && $x['body']['counts'] === ['like' => 0, 'dislike' => 0], 'favorito: se marca y NO cuenta como me gusta');
$r->toggle($ctx(), 10, 'like');
$x = $r->toggle($ctx(), 10, 'dislike');
t_ok($x['body']['mine']['favorite'] === true && $x['body']['mine']['dislike'] === true && $s->has(10, 7, 3), 'cambiar me gusta/no me gusta no toca el favorito');
$x = $r->toggle($ctx(), 10, 'favorite');
t_ok($x['body']['mine']['favorite'] === false && !$s->has(10, 7, 3) && $s->has(10, 7, 2), 'desmarcar el favorito no toca el no me gusta');
// varios usuarios acumulan
$r->toggle($ctx(['userId' => 8]), 10, 'dislike'); $x = $r->toggle($ctx(['userId' => 9]), 10, 'like');
t_ok($x['body']['counts'] === ['like' => 1, 'dislike' => 2], 'los me gusta y no me gusta de personas distintas se ACUMULAN');
// nunca devuelve quien
t_ok(!str_contains(json_encode($x), '"7"') && !preg_match('/user|name|who/i', json_encode($x['body'])), 'la respuesta no incluye quien reacciono');
// propio
[$r, $s] = $mk();
foreach (['like', 'dislike'] as $t) { $x = $r->toggle($ctx(), 11, $t); t_ok($x['status'] === 403 && $x['body'] === ['ok' => false, 'error' => 'denied'], "no se puede dar $t al PROPIO comentario (403)"); }
t_ok(!$s->rows, 'nada escrito por el intento sobre el propio comentario');
$x = $r->toggle($ctx(), 11, 'favorite');
t_ok($x['status'] === 200 && $x['body']['mine']['favorite'] === true, 'SI se puede marcar favorito el propio');
// fallo del almacen
[$r, $s] = $mk(); $s->failAdd = true;
$x = $r->toggle($ctx(), 10, 'like');
t_ok($x['status'] === 500 && $x['body'] === ['ok' => false, 'error' => 'failed'] && !$s->rows, 'fallo de la base de datos: 500 generico y transaccion deshecha');
// carrera: dos peticiones simultaneas del mismo usuario no duplican (la clave unica del esquema lo garantiza; aqui el almacen es un mapa)
[$r, $s] = $mk(); $r->toggle($ctx(), 10, 'like'); t_ok(count($s->rows) === 1, 'una sola fila por (comentario, usuario, tipo)');

echo "F) state: lectura\n";
[$r, $s] = $mk();
$r->toggle($ctx(['userId' => 8]), 10, 'like'); $r->toggle($ctx(['userId' => 9]), 10, 'dislike'); $r->toggle($ctx(['userId' => 7]), 10, 'favorite'); $r->toggle($ctx(['userId' => 8]), 10, 'favorite');
$w = $r->state($ctx(), '10,11,12,13,14,999');
$items = (array) $w['body']['items'];
t_ok($w['status'] === 200 && $w['body']['auth'] === true && $w['body']['can'] === true && array_map('strval', array_keys($items)) === ['10', '11'], 'solo los comentarios publicados y visibles; los demas se omiten SIN pistas');
t_ok($items['10']['like'] === 1 && $items['10']['dislike'] === 1 && $items['10']['mine'] === ['like' => false, 'dislike' => false, 'favorite' => true] && $items['10']['own'] === false && $items['11']['own'] === true, 'contadores, estado propio y comentario propio');
$g = $r->state($ctx(['userId' => 0, 'canReact' => false]), '10');
$gi = (array) $g['body']['items'];
t_ok($g['status'] === 200 && $g['body']['auth'] === false && $g['body']['can'] === false && $gi['10']['like'] === 1 && !isset($gi['10']['mine']) && !isset($gi['10']['own']) && !isset($g['body']['token']), 'invitado: contadores en solo lectura, sin estado propio');
$o = $r->state($ctx(['userId' => 8]), '10');
t_ok(((array) $o['body']['items'])['10']['mine']['favorite'] === true && ((array) $r->state($ctx(['userId' => 9]), '10')['body']['items'])['10']['mine']['favorite'] === false, 'el favorito es PRIVADO: cada persona solo ve el suyo');
t_ok(!str_contains(json_encode($w['body']), 'favorites') && !isset($items['10']['favorite']), 'no hay contador publico de favoritos');
t_ok($r->state($ctx(), '10')['status'] === 200 && $r->state($ctx(), 'x')['status'] === 400 && $r->state($ctx(), null)['status'] === 400 && $r->state($ctx(), implode(',', range(1, 101)))['status'] === 400, 'ids invalidos o mas de 100: 400');
t_ok($r->state($ctx(['opts' => R::options(['reactions_enabled' => '0'])]), '10')['body'] === ['ok' => true, 'enabled' => false], 'desactivadas: enabled=false y nada mas');
t_ok(((array) $r->state($ctx(['opts' => R::options(['reactions_dislike' => '0'])]), '10')['body']['items'])['10']['dislike'] === 0, 'con no me gusta desactivado no se devuelve su contador');
$o = $r->state($ctx(['userId' => 8, 'opts' => R::options(['reactions_favorites' => '0'])]), '10');
t_ok(((array) $o['body']['items'])['10']['mine']['favorite'] === false, 'con los favoritos desactivados no se devuelve ninguno (ni el guardado antes: no se pinta el fondo amarillo)');
$calls = 0; $r->state($ctx(['canViewAsset' => function (int $a) use (&$calls) { $calls++; return true; }]), '10,11'); t_ok($calls === 1, 'permiso de ver el contenido: una comprobacion por contenido, no por comentario');

echo "G) Limite de frecuencia\n";
$t = 1000; $mem = [];
$lim = new ReactionRateLimiter(function ($k) use (&$mem) { return $mem[$k] ?? null; }, function ($k, $v) use (&$mem) { $mem[$k] = $v; }, 60, 60, function () use (&$t) { return $t; });
$ok = 0; for ($i = 0; $i < 70; $i++) { if ($lim->allow(7)) { $ok++; } }
t_ok($ok === 60 && !$lim->allow(7), '60 reacciones por minuto y usuario; la 61 se bloquea');
t_ok($lim->allow(8), 'el limite es por usuario');
$t += 30; t_ok(!$lim->allow(7), 'a mitad de ventana sigue bloqueado');
$t += 31; t_ok($lim->allow(7), 'pasada la ventana vuelve a permitir');
$t -= 500; t_ok($lim->allow(7), 'un reloj que retrocede no deja bloqueado al usuario');
$fail = new ReactionRateLimiter(function () { throw new RuntimeException('x'); }, function () {}, 1, 60);
t_ok($fail->allow(1) === true, 'si el contador falla se permite (solo es un freno)');
[$r, $s] = $mk();
$x = $r->toggle($ctx(['allowRate' => fn() => false]), 10, 'like');
t_ok($x['status'] === 429 && $x['body'] === ['ok' => false, 'error' => 'rate'] && $s->writes === 0, 'limite superado: 429 generico y nada escrito');
$n = 0; $r->toggle($ctx(['allowRate' => function () use (&$n) { $n++; return true; }]), 10, 'like'); $r->toggle($ctx(['allowRate' => function () use (&$n) { $n++; return true; }]), 'zz', 'like');
t_ok($n === 1, 'una peticion invalida no gasta cupo');

echo "H) ReactionStore: consultas con parametros enlazados\n";
$evil = ["1 OR 1=1", "1'; DROP TABLE x;--", '1) UNION SELECT password FROM jos_users--', "\\' OR '1'='1"];
$db = new RecDb(); $st = new ReactionStore($db);
$db->rows = [[['id' => '10', 'asset_id' => '1', 'enabled' => '1', 'created_by' => '5']]];
$st->comments(array_merge([10, '11'], $evil, [-4, 0, 1.5, null, [1]]));
$q = $db->log[0]; $txt = $q->text();
t_ok($q->lists && $q->lists[0][1] === [10, 11] && $q->lists[0][2] === 'int', 'comments(): solo enteros positivos llegan a whereIn, con tipo INTEGER');
foreach ($evil as $e) { t_ok(!str_contains($txt, $e) && !str_contains(json_encode($q->lists), $e), 'comments(): la cadena hostil no esta en la consulta: ' . substr($e, 0, 20)); }
$db = new RecDb(); $st = new ReactionStore($db); $st->add(10, 7, 1, '2026-10-07 10:00:00');
$q = $db->log[0];
t_ok($q->type === 'insert' && $q->binds[':cid'] === 10 && $q->binds[':uid'] === 7 && $q->binds[':type'] === 1 && $q->binds[':created'] === '2026-10-07 10:00:00' && str_contains($q->text(), ':cid, :uid, :type, :created'), 'add(): INSERT con los 4 valores enlazados');
$db = new RecDb(); $st = new ReactionStore($db); $st->remove(10, 7, 3);
t_ok($db->log[0]->type === 'delete' && $db->log[0]->binds === [':cid' => 10, ':uid' => 7, ':type' => 3], 'remove(): DELETE por los tres valores enlazados');
$db = new RecDb(); $st = new ReactionStore($db); $st->userTypes(7, [10, 11]); $st->counts([10, 11]);
t_ok($db->log[0]->binds[':uid'] === 7 && $db->log[0]->lists[0][2] === 'int' && $db->log[1]->lists[0][1] === [10, 11] && $db->log[1]->lists[1][1] === [1, 2], 'userTypes()/counts(): enlazados; counts() solo cuenta me gusta y no me gusta (el favorito es privado)');
$db = new RecDb(); $st = new ReactionStore($db); $ran = false; $st->transaction(function () use (&$ran) { $ran = true; });
t_ok($db->tx === ['start', 'commit'] && $ran, 'transaction(): start y commit');
$db = new RecDb(); $st = new ReactionStore($db);
try { $st->transaction(function () { throw new RuntimeException('x'); }); t_ok(false, 'transaction() relanza'); } catch (RuntimeException $e) { t_ok($db->tx === ['start', 'rollback'], 'transaction(): rollback si falla y relanza'); }
$db = new RecDb(); $db->failExecute = true; $db->rows = [[]]; $st = new ReactionStore($db);
try { $st->add(10, 7, 1, 'x'); t_ok(false, 'add() relanza si no era una carrera'); } catch (RuntimeException $e) { t_ok(true, 'add(): un fallo real (no una carrera) se relanza'); }
$db = new RecDb(); $db->failExecute = true; $db->rows = [[['comment_id' => '10', 'type' => '1']]]; $st = new ReactionStore($db);
try { $st->add(10, 7, 1, 'x'); t_ok(true, 'add(): si la fila ya existe (peticion simultanea) no es un error'); } catch (Throwable $e) { t_ok(false, 'add() carrera'); }

echo "I) Limpieza y privacidad\n";
$db = new RecDb(); ReactionStore::deleteForComments($db, [5, '6', 'x', "7; DROP", -1]);
t_ok(count($db->log) === 1 && $db->log[0]->type === 'delete' && $db->log[0]->lists[0][1] === [5, 6] && str_contains($db->log[0]->text(), 'engage_reactions'), 'al borrar comentarios se borran sus reacciones (solo ids enteros)');
$db = new RecDb(); ReactionStore::deleteForComments($db, []); ReactionStore::deleteForComments($db, ['x']); t_ok(!$db->log, 'sin ids validos no se consulta nada');
$db = new RecDb(); $db->failExecute = true; try { ReactionStore::deleteForComments($db, [1]); ReactionStore::deleteForUser($db, 3); ReactionStore::deleteForAsset($db, 4); t_ok(true, 'las limpiezas nunca lanzan'); } catch (Throwable $e) { t_ok(false, 'limpieza lanza'); }
$db = new RecDb(); ReactionStore::deleteForUser($db, 42);
t_ok($db->log[0]->type === 'delete' && $db->log[0]->binds === [':uid' => 42], 'al borrar un usuario se borran SUS reacciones (user_id enlazado)');
$db = new RecDb(); ReactionStore::deleteForUser($db, 0); ReactionStore::deleteForUser($db, -3); t_ok(!$db->log, 'user_id 0 o negativo: no se borra nada');
$db = new RecDb(); ReactionStore::deleteForAsset($db, 9);
t_ok($db->log[0]->binds === [':asset' => 9] && str_contains($db->log[0]->text(), 'IN ('), 'al borrar un contenido: DELETE ... IN (subconsulta de sus comentarios) con el asset enlazado');
$db = new RecDb(); $db->rows = [[['id' => '1', 'comment_id' => '10', 'type' => '1', 'created' => '2026-10-07 10:00:00'], ['id' => '2', 'comment_id' => '11', 'type' => '3', 'created' => '2026-10-07 10:01:00']]];
$exp = ReactionStore::exportForUser($db, 42);
t_ok($exp === [['id' => 1, 'comment_id' => 10, 'type' => 'like', 'created' => '2026-10-07 10:00:00'], ['id' => 2, 'comment_id' => 11, 'type' => 'favorite', 'created' => '2026-10-07 10:01:00']] && $db->log[0]->binds === [':uid' => 42], 'exportacion RGPD: id, comentario, tipo en texto y fecha; solo del usuario (enlazado)');
t_ok(ReactionStore::exportForUser(new RecDb(), 0) === [], 'exportar user_id 0: vacio');
$priv = $lf("$root/src/plugins/privacy/engage/src/Extension/Engage.php");
t_ok(str_contains($priv, "createDomain('engage_reactions'") && str_contains($priv, 'ReactionStore::exportForUser($db, (int) $user->id)') && str_contains($priv, '$ret = [$domain, $reactions];'), 'plugin privacy/engage: EXPORTA las reacciones del usuario');
t_ok(str_contains($priv, 'ReactionStore::deleteForUser($this->db, (int) $user->id)'), 'plugin privacy/engage: ELIMINA las reacciones del usuario');
$usr = $lf("$root/src/plugins/user/engage/src/Extension/Engage.php");
t_ok(preg_match('/if \(!\$success\)\s*\{\s*unset\(\$this->usersToRemove\[\$userId\]\);\s*return;\s*\}[^}]*?\\\\Akeeba\\\\Component\\\\Engage\\\\Administrator\\\\Helper\\\\ReactionStore::deleteForUser\(\$this->getDatabase\(\), \(int\) \$userId\);/s', $usr) === 1 && strpos($usr, 'ReactionStore::deleteForUser') < strpos($usr, 'array_key_exists($userId, $this->usersToRemove)'), 'plugin user/engage: onUserAfterDelete borra las reacciones del usuario (solo si Joomla lo borro, antes de las comprobaciones de seudonimizacion)');
$tbl = $lf("$c/backend/src/Table/CommentTable.php");
t_ok(str_contains($tbl, 'ReactionStore::deleteForComments($this->_db, [$pk]);') && strpos($tbl, 'deleteForComments') < strpos($tbl, 'Get the child comments'), 'CommentTable::onAfterDelete borra las reacciones del comentario (las de las respuestas, al borrarlas ellas)');
$cont = $lf("$root/src/plugins/content/engage/src/Extension/Engage.php");
t_ok(str_contains($cont, 'ReactionStore::deleteForAsset($db, (int) $assetId);') && strpos($cont, 'deleteForAsset') < strpos($cont, "->delete(\$db->qn('#__engage_comments'))"), 'content/engage: al borrar un articulo se borran las reacciones ANTES que los comentarios');

echo "J) Esquema SQL\n";
$ins = $lf("$c/backend/sql/install.mysql.utf8.sql"); $upd = $lf("$c/backend/sql/updates/mysql/3.4.3-20261007.sql"); $uni = $lf("$c/backend/sql/uninstall.mysql.utf8.sql");
foreach (['`id`         BIGINT(20) unsigned NOT NULL AUTO_INCREMENT', '`comment_id` BIGINT(20) unsigned NOT NULL', '`user_id`    INT(11) unsigned NOT NULL', '`type`       TINYINT(3) unsigned NOT NULL', '`created`    DATETIME NOT NULL', 'UNIQUE KEY `#__engage_reactions_unique` (`comment_id`, `user_id`, `type`)', 'KEY `#__engage_reactions_comment` (`comment_id`)', 'KEY `#__engage_reactions_user` (`user_id`)', 'ENGINE InnoDB DEFAULT CHARSET = utf8mb4'] as $f) {
	t_ok(str_contains($ins, $f) && str_contains($upd, $f), 'install.sql y el SQL de actualizacion: ' . substr($f, 0, 60));
}
t_ok(str_contains($ins, 'CREATE TABLE IF NOT EXISTS `#__engage_reactions`') && str_contains($upd, 'CREATE TABLE IF NOT EXISTS `#__engage_reactions`'), 'CREATE TABLE IF NOT EXISTS (idempotente)');
t_ok(str_contains($uni, 'DROP TABLE IF EXISTS `#__engage_reactions`;'), 'uninstall.sql elimina la tabla');
t_ok(!preg_match('/postgres|pgsql/i', $ins . $upd) && !is_dir("$c/backend/sql/updates/postgresql"), 'sin PostgreSQL');
t_ok(!preg_match('/ALTER TABLE\s+`#__engage_comments`/', $upd), 'la tabla de comentarios no se modifica');
$man = simplexml_load_string($lee("$c/engage.xml"));
t_ok((string) $man->update->schemas->schemapath === 'sql/updates/mysql', 'el manifiesto declara la carpeta de actualizaciones de esquema');

echo "K) Opciones validadas por el esquema (guardado al instante)\n";
$sch = S::parse($lee("$c/backend/config.xml"), 'com_engage')['fields'];
foreach (['reactions_enabled', 'reactions_dislike', 'reactions_favorites'] as $k) {
	t_ok(($sch[$k]['control'] ?? '') === 'switch' && $sch[$k]['default'] === '1', "$k: interruptor, Si por defecto");
	foreach (['0', '1'] as $v) { t_ok(S::validate($sch[$k], $v) === $v, "$k acepta $v"); }
	$bad = 0; foreach (['2', '-1', 'yes', 'true', '', '01', ' 1', ['1'], null, "1'; DROP", '1.0'] as $v) { try { S::validate($sch[$k], $v); } catch (InvalidArgumentException $e) { $bad++; } }
	t_ok($bad === 11, "$k rechaza los 11 valores ajenos");
}
t_ok(($sch['reactions_who']['control'] ?? '') === 'segmented' && $sch['reactions_who']['default'] === 'registered' && array_keys($sch['reactions_who']['options']) === ['registered', 'commenters'], 'reactions_who: lista cerrada registered | commenters, por defecto registered');
foreach (['registered', 'commenters'] as $v) { t_ok(S::validate($sch['reactions_who'], $v) === $v, "reactions_who acepta $v"); }
$bad = 0; foreach (['admins', 'Registered', 'registered ', '', 'all', ['registered'], null, "registered'--", 1] as $v) { try { S::validate($sch['reactions_who'], $v); } catch (InvalidArgumentException $e) { $bad++; } }
t_ok($bad === 8 || $bad === 9, "reactions_who rechaza valores fuera de la lista ($bad de 9)");
$sec = array_column(SettingsLayout::sections(), 'items', 'id');
t_ok(array_map(fn($i) => $i[1], $sec['reactions']) === ['reactions_enabled', 'reactions_dislike', 'reactions_favorites', 'reactions_who'], 'categoria nueva «Reacciones» con las 4 opciones');

echo "L) Cadenas en en-GB y es-ES\n";
$ini = [];
foreach (['en-GB', 'es-ES'] as $l) {
	$ini[$l] = [];
	foreach (["$c/backend/language/$l/com_engage.ini", "$c/backend/language/$l/com_engage.sys.ini", "$c/frontend/language/$l/com_engage.ini"] as $f) { $ini[$l] += parse_ini_string($lee($f), false, INI_SCANNER_RAW) ?: []; }
}
$claves = array_filter(array_keys($ini['en-GB']), fn($k) => str_contains($k, 'REACTIONS') || str_contains($k, 'TOPRATED') || $k === 'COM_ENGAGE_PANEL_STAT_LIKES' || $k === 'COM_ENGAGE_PANEL_STAT_DISLIKES');
t_ok(count($claves) >= 30, count($claves) . ' cadenas de reacciones en en-GB');
foreach ($claves as $k) { if (!isset($ini['es-ES'][$k]) || $ini['es-ES'][$k] === '') { t_ok(false, "falta en es-ES: $k"); } }
t_ok(!array_diff($claves, array_keys($ini['es-ES'])), 'mismas claves en en-GB y es-ES');
t_ok($ini['es-ES']['COM_ENGAGE_REACTIONS_LIKE'] === 'Me gusta' && $ini['es-ES']['COM_ENGAGE_REACTIONS_DISLIKE'] === 'No me gusta' && $ini['es-ES']['COM_ENGAGE_REACTIONS_FAVORITE'] === 'Favorito' && $ini['en-GB']['COM_ENGAGE_REACTIONS_LIKE'] === 'Like' && $ini['en-GB']['COM_ENGAGE_REACTIONS_DISLIKE'] === 'Dislike' && $ini['en-GB']['COM_ENGAGE_REACTIONS_FAVORITE'] === 'Favorite', 'etiquetas de los botones (aria-label): Me gusta / No me gusta / Favorito y Like / Dislike / Favorite');
t_ok(str_contains($ini['es-ES']['COM_ENGAGE_REACTIONS_HINT_LOGIN'], 'Inicie sesión') && str_contains($ini['es-ES']['COM_ENGAGE_REACTIONS_HINT_OWN'], 'No puede') && str_contains($ini['es-ES']['COM_ENGAGE_REACTIONS_ERR_RATE'], 'Espere'), 'es-ES de usted (Inicie, No puede, Espere)');
$usadas = []; foreach ([$lf("$c/frontend/src/View/Comments/HtmlView.php"), $lf("$c/media/js/reactions.js"), $lf("$c/backend/tmpl/controlpanel/default.php")] as $src) { preg_match_all("/COM_ENGAGE_(?:REACTIONS|PANEL_TOPRATED)[A-Z_]*/", $src, $m); $usadas = array_merge($usadas, $m[0]); }
$faltan = array_filter(array_unique($usadas), fn($k) => !isset($ini['en-GB'][$k]) && !in_array($k, ['COM_ENGAGE_REACTIONS_', 'COM_ENGAGE_PANEL_TOPRATED_'], true));
t_ok(!$faltan, 'todas las claves que usan la vista, el JS y el panel existen' . ($faltan ? ' (faltan ' . implode(', ', $faltan) . ')' : ''));
foreach (['LABEL', 'DESC'] as $x) { foreach (['ENABLED', 'DISLIKE', 'FAVORITES', 'WHO'] as $k) { t_ok(isset($ini['en-GB']["COM_ENGAGE_CONFIG_REACTIONS_{$k}_$x"], $ini['es-ES']["COM_ENGAGE_CONFIG_REACTIONS_{$k}_$x"]), "opcion $k ($x) en ambos idiomas"); } }

echo "M) Seguridad estatica: controlador, vista, plantilla, JS, CSS\n";
$ctl = $lf("$c/frontend/src/Controller/ReactionsController.php");
t_ok(strpos($ctl, "'POST'") < strpos($ctl, "Session::checkToken('post')") && strpos($ctl, "Session::checkToken('post')") < strpos($ctl, '->toggle($ctx'), 'toggle: exige POST y token CSRF ANTES de llamar a la logica');
t_ok(str_contains($ctl, "['GET', 'HEAD']") && !preg_match('/state\(\): void\s*\{[^}]*checkToken/s', $ctl), 'state: solo GET/HEAD (sin efectos secundarios, no necesita token)');
t_ok(preg_match_all('/->(?:post|get)->get\(\'(comment_id|type|ids)\', null, \'raw\'\)/', $ctl) === 3 && !preg_match('/\$_(GET|POST|REQUEST|COOKIE)/', $ctl), 'las entradas se leen del objeto Input de Joomla como cadenas crudas y las valida Reactions');
t_ok(str_contains($ctl, 'no-store') && str_contains($ctl, 'nosniff') && str_contains($ctl, 'JSON_HEX_TAG') && str_contains($ctl, 'Vary: Cookie'), 'respuestas JSON sin cache, nosniff y con HEX_TAG/AMP/APOS/QUOT');
t_ok(!preg_match('/getMessage|getTraceAsString|\$e->/', preg_replace('/instanceof ExitException/', '', $ctl)), 'ningun mensaje de excepcion llega al cliente');
t_ok(str_contains($ctl, "'unavailable'") === false && str_contains($ctl, "'error' => 'failed'"), 'errores 500 genericos');
t_ok(str_contains($ctl, 'HTTP_SEC_FETCH_SITE') && substr_count($ctl, '$this->refuseCrossSite();') === 2 && str_contains($ctl, "'cross-site'"), 'peticiones iniciadas por otro sitio (Sec-Fetch-Site: cross-site) rechazadas en ambas tareas');
t_ok(str_contains($ctl, 'Session::getFormToken()') && str_contains($ctl, "!empty(\$b['can'])"), 'el token solo se entrega a quien tiene sesion y puede reaccionar');
t_ok(str_contains($ctl, "'core.create', 'com_engage'") && str_contains($ctl, "'commenters'") && str_contains($ctl, 'getAuthorisedViewLevels') && str_contains($ctl, "'access', 'parent_access'") && str_contains($ctl, "'unknown'"), 'permisos: reactions_who, nivel de acceso del contenido y categoria, tipo conocido');
$rs = $lf("$c/backend/src/Helper/ReactionStore.php") . $lf("$c/backend/src/Helper/Reactions.php");
t_ok(!preg_match('/->where\([^\n]*\$(commentId|userId|type|ids|v|id|created|assetId)\b/', $rs) && !str_contains($rs, '->q($') && substr_count($rs, 'ParameterType::') >= 15, 'ReactionStore/Reactions: ningun valor de la peticion se concatena en el SQL; todo enlazado');
t_ok(!preg_match('/\b(mysqli_|PDO|->query\(|->escape\(|->quote\()/', $rs), 'sin acceso a la base de datos fuera del constructor de consultas');
$tpl = $lf("$c/frontend/tmpl/comments/default_list.php"); $vw = $lf("$c/frontend/src/View/Comments/HtmlView.php");
t_ok(!preg_match('/style=/', substr($vw, strpos($vw, 'function reactionsHtml'), 2200)) && !str_contains(ReactionIcons::svg('like') . ReactionIcons::svg('dislike') . ReactionIcons::svg('favorite'), 'style='), 'esqueleto y SVG sin estilos en linea (CSP)');
t_ok(preg_match('/data-engage-react="\' \. \$type \. \'" data-engage-id="\' \. \(int\) \$commentId \. \'" aria-pressed="false" aria-label="\' \. \$label/', $vw) === 1 && str_contains($vw, "htmlspecialchars(\$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')") && str_contains($vw, 'data-engage-reactions hidden'), 'esqueleto: id entero, texto escapado, aria-pressed y aria-label, oculto (hidden) hasta que el JS lo pinta');
t_ok(str_contains($tpl, "\$comment->enabled == 1") && str_contains($tpl, 'akengage-comment-reply--react') && str_contains($tpl, '$this->reactions[\'enabled\']'), 'plantilla: solo comentarios publicados y con la opcion activada; fila de Responder con modificador');
t_ok(!str_contains($vw, 'getFormToken()') || substr_count($vw, 'getFormToken()') === 1, 'la vista no pone el token de reacciones en la pagina (puede estar en cache)');
$js = $lf("$c/media/js/reactions.js");
t_ok(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function|setAttribute\("on|\.src\s*=|location\s*=/', $js), 'JS: sin innerHTML, eval ni URL dinamicas');
t_ok(substr_count($js, 'try {') >= 4 && substr_count($js, 'catch') >= 7, 'JS: try/catch (los fallos nunca rompen la pagina)');
t_ok(preg_match('/XMLHttpRequest|jquery|\$\(/i', $js) === 0 && str_contains($js, 'textContent') && str_contains($js, 'isId('), 'JS: sin dependencias; textContent; ids validados como enteros');
t_ok(str_contains($js, 'function trusted(btn)') && substr_count($js, 'trusted(') >= 5 && str_contains($js, 'btn.closest(".akengage-comment-body")'), 'JS: solo hace caso a botones pintados por el servidor (dentro de la botonera de su comentario y fuera del texto del comentario)');
t_ok(str_contains($js, 'method: "POST"') && str_contains($js, 'body.set(token, "1")') && str_contains($js, 'credentials: "same-origin"') && str_contains($js, 'cache: "no-store"'), 'JS: POST con el token del servidor, mismas credenciales, sin cache');
t_ok(!preg_match('~https?://~', $js) && !preg_match('~https?://|@import|url\(~', $lf("$c/media/css/replies.css") . str_replace('http://www.w3.org/2000/svg', '', ReactionIcons::svg('like') . ReactionIcons::svg('favorite'))), 'JS, CSS y SVG sin recursos externos');
$assets = json_decode($lee("$c/media/joomla.asset.json"), true); $by = [];
foreach ($assets['assets'] as $a) { $by[$a['type'] . ':' . $a['name']] = $a; }
t_ok(($by['script:com_engage.reactions']['uri'] ?? '') === 'com_engage/reactions.js' && ($by['script:com_engage.reactions']['attributes']['defer'] ?? false) === true && is_file("$c/media/js/reactions.js"), 'reactions.js registrado en joomla.asset.json (defer) y existe');
$css = $lf("$c/media/css/replies.css");
foreach (['.akengage-comment-reply.akengage-comment-reply--react', 'justify-content: space-between', 'flex-wrap: wrap', 'akengage-react-btn', '[aria-pressed="true"] .akengage-react-icon', 'fill: currentColor', 'min-width: 2.5rem', 'prefers-reduced-motion: reduce', ':focus-visible', '.akengage-is-favorite', '--eg-fav-fondo', '[hidden]'] as $f) { t_ok(str_contains($css, $f), "replies.css: $f"); }
t_ok(str_contains(ReactionIcons::svg('like'), 'stroke="currentColor"') && str_contains(ReactionIcons::svg('like'), 'fill="none"') && str_contains(ReactionIcons::svg('like'), 'aria-hidden="true"') && ReactionIcons::svg('xx') === '', 'SVG: contorno con currentColor, aria-hidden; tipo desconocido = vacio');

echo "N) Contraste del favorito (WCAG 4,5:1) en cada tema y modo\n";
$lum = function (string $h): float { $h = ltrim($h, '#'); $v = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; foreach ($v as &$x) { $x /= 255; $x = $x <= .03928 ? $x / 12.92 : (($x + .055) / 1.055) ** 2.4; } return .2126 * $v[0] + .7152 * $v[1] + .0722 * $v[2]; };
$cr = fn(string $a, string $b): float => (max($lum($a), $lum($b)) + .05) / (min($lum($a), $lum($b)) + .05);
$var = function (string $css, string $name): string { preg_match('/^\s*' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6})/mi', $css, $m); return $m[1] ?? ''; };
foreach (['moderno' => 'modern', 'minimalista' => 'minimal', 'oscuro' => 'dark'] as $f => $tema) {
	$th = $lf("$c/media/css/themes/$f.css"); $bg = $var($th, '--eg-fav-fondo');
	foreach (['--eg-texto', '--eg-texto-suave', '--eg-enlace', '--eg-ok', '--eg-peligro'] as $v) { $col = $var($th, $v); $min = in_array($v, ['--eg-ok', '--eg-peligro'], true) ? 4.5 : 4.5; t_ok($bg !== '' && $col !== '' && $cr($col, $bg) >= $min, "$tema: $v $col sobre el amarillo $bg = " . round($cr($col, $bg), 2) . ':1'); }
	t_ok($cr($var($th, '--eg-fav-estrella-borde'), $var($th, '--eg-fondo')) >= 3 || $cr($var($th, '--eg-fav-estrella'), $var($th, '--eg-fondo')) >= 3, "$tema: la estrella pulsada se distingue de la tarjeta (>= 3:1)");
}
foreach (['#1f2937' => 'texto', '#0a58ca' => 'enlaces', '#4b5563' => 'notas'] as $col => $n) { t_ok($cr($col, '#fff3bf') >= 4.5, "classic: $n $col sobre #fff3bf = " . round($cr($col, '#fff3bf'), 2) . ':1 (pagina clara u oscura: el texto lo fija replies.css)'); }
t_ok($cr('#854d0e', '#ffffff') >= 3 && $cr('#facc15', '#212529') >= 3, 'classic: estrella (contorno oscuro sobre pagina clara, relleno amarillo sobre pagina oscura) >= 3:1');
foreach (['moderno', 'minimalista', 'oscuro'] as $f) { $th = $lf("$c/media/css/themes/$f.css"); t_ok(str_contains($th, 'akengage-is-favorite .akengage-comment-body') && str_contains($th, '--eg-fav-fondo') && str_contains($th, '.akengage-react-btn[aria-pressed="true"]'), "$f.css: favorito y botones pulsados"); }
t_fin();
