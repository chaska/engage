<?php
/**
 * 0.7.1: correcciones de la revision de seguridad independiente de la 0.7.0. Sin Joomla (logica con un almacen en memoria, consultas con una base de
 * datos que registra, y comprobaciones estaticas del codigo); lo que necesita Joomla de verdad esta en tests/joomla-live (17-reacciones.sh):
 *  MEDIO-1  borrado de cuenta (plugin user/engage + Meta::pseudonymiseUserComments)
 *  MEDIO-2  ACL de categoria (cargador de articulos, Meta, controlador de reacciones y modelo de comentarios)
 *  BAJO-1   comentario propio por email verificado de la sesion; sin me gusta propios (estado, contadores y cambio de propietario al iniciar sesion)
 *  BAJO-2   toggle no revela counts.dislike con la opcion desactivada
 *  BAJO-3   consulta masiva: contenidos distintos y limite de frecuencia
 *  CARRERA  bloqueo de la fila del comentario dentro de la transaccion
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => (string) file_get_contents($f);
$lf   = fn(string $f): string => str_replace("\r\n", "\n", $lee($f));
foreach (['Reactions', 'ReactionStoreInterface', 'ReactionStore', 'ReactionRateLimiter'] as $k) { require_once "$c/backend/src/Helper/$k.php"; }
require __DIR__ . '/stubs/reacciones.php';
use Akeeba\Component\Engage\Administrator\Helper\ReactionRateLimiter;
use Akeeba\Component\Engage\Administrator\Helper\Reactions as R;
use Akeeba\Component\Engage\Administrator\Helper\ReactionStore;

$opts = R::options([]);
$ctx = fn(array $o = []): array => array_merge(['userId' => 7, 'userEmail' => 'siete@example.test', 'canReact' => true, 'opts' => $opts, 'canViewAsset' => fn(int $a): bool => $a !== 666, 'allowRate' => fn(int $u): bool => true, 'now' => '2026-10-07 10:00:00'], $o);

echo "A) BAJO-1: comentario propio por created_by Y por email de la sesion\n";
t_ok(R::isOwn(['created_by' => 7, 'email' => ''], 7, 'siete@example.test') === true, 'propio por created_by');
t_ok(R::isOwn(['created_by' => 0, 'email' => 'siete@example.test'], 7, 'siete@example.test') === true, 'invitado con el email de la cuenta: propio');
t_ok(R::isOwn(['created_by' => 0, 'email' => 'SIETE@Example.TEST'], 7, 'siete@example.test') === true && R::isOwn(['created_by' => 0, 'email' => ' siete@example.test '], 7, ' SIETE@example.test') === true, 'el email se compara sin distinguir mayusculas ni espacios (como la base de datos y ownComments)');
t_ok(R::isOwn(['created_by' => 0, 'email' => 'otro@example.test'], 7, 'siete@example.test') === false, 'email distinto: no es propio');
t_ok(R::isOwn(['created_by' => 99, 'email' => 'siete@example.test'], 7, 'siete@example.test') === false, 'comentario de OTRA cuenta aunque conserve un email igual: no es propio');
t_ok(R::isOwn(['created_by' => 0, 'email' => ''], 7, '') === false && R::isOwn(['created_by' => 0, 'email' => 'a@b.c'], 7, '') === false && R::isOwn(['created_by' => 0], 7, 'a@b.c') === false, 'sin email no hay coincidencia (ni vacio con vacio)');
t_ok(R::isOwn(['created_by' => 0, 'email' => 'siete@example.test'], 0, 'siete@example.test') === false, 'un invitado (id 0) nunca tiene comentarios propios');
$s = new MemStore(); $s->comment(10, 1, 1, 99); $s->comment(20, 1, 1, 0, 'siete@example.test'); $s->comment(21, 1, 1, 0, 'otro@example.test'); $r = new R($s);
$x = $r->toggle($ctx(), 20, 'like');
t_ok($x['status'] === 403 && $x['body'] === ['ok' => false, 'error' => 'denied'] && !$s->rows, 'toggle like en un comentario de invitado con MI email: 403 y nada escrito');
t_ok($r->toggle($ctx(), 20, 'dislike')['status'] === 403, 'toggle dislike: 403');
$x = $r->toggle($ctx(), 20, 'favorite');
t_ok($x['status'] === 200 && $x['body']['mine'] === ['like' => false, 'dislike' => false, 'favorite' => true], 'el favorito (privado) si se puede');
t_ok($r->toggle($ctx(), 21, 'like')['status'] === 200, 'comentario de invitado con otro email: se puede');
t_ok($r->toggle($ctx(['userEmail' => '']), 20, 'like')['status'] === 200, 'sin email en el contexto no se supone nada (200)');
$w = (array) $r->state($ctx(), '10,20,21')['body']['items'];
t_ok($w['20']['own'] === true && $w['21']['own'] === false && $w['10']['own'] === false, 'state: own = true tambien por email');
// resto antiguo: un me gusta propio que ya estaba
$s = new MemStore(); $s->comment(20, 1, 1, 0, 'siete@example.test'); $s->comment(30, 1, 1, 7); $s->comment(31, 1, 1, 99); $s->emails = [7 => 'siete@example.test', 8 => 'ocho@example.test'];
foreach ([[20, 7, 1], [20, 7, 3], [20, 8, 1], [30, 7, 1], [30, 7, 2], [30, 8, 2], [31, 7, 1]] as [$cm, $u, $t]) { $s->add($cm, $u, $t, 'x'); }
$r = new R($s);
$w = (array) $r->state($ctx(), '20,30,31')['body']['items'];
t_ok($w['20']['mine'] === ['like' => false, 'dislike' => false, 'favorite' => true] && $w['30']['mine'] === ['like' => false, 'dislike' => false, 'favorite' => false] && $w['31']['mine']['like'] === true, 'state: un me gusta / no me gusta propio que quedara no se muestra como activo (el favorito si; en comentarios ajenos todo normal)');
t_ok($w['20']['like'] === 1 && $w['30']['like'] === 0 && $w['30']['dislike'] === 1 && $w['31']['like'] === 1, 'los contadores no cuentan las reacciones propias (por email: 1 = solo la de otra persona; por created_by: 0 me gusta)');
$g = (array) $r->state($ctx(['userId' => 0, 'canReact' => false, 'userEmail' => '']), '20,30')['body']['items'];
t_ok($g['20']['like'] === 1 && $g['30']['like'] === 0, 'y el invitado ve los mismos contadores');
$x = $r->toggle($ctx(), 20, 'favorite');
t_ok($x['status'] === 200 && $x['body']['mine']['like'] === false && $x['body']['mine']['favorite'] === false, 'la respuesta de toggle en el comentario propio tampoco devuelve me gusta activo (resto antiguo)');

echo "B) BAJO-2: toggle y el contador de no me gusta\n";
$s = new MemStore(); $s->comment(10, 1, 1, 99); $r = new R($s);
$r->toggle($ctx(['userId' => 8]), 10, 'dislike'); $r->toggle($ctx(['userId' => 9]), 10, 'dislike');
$x = $r->toggle($ctx(), 10, 'like');
t_ok($x['body']['counts'] === ['like' => 1, 'dislike' => 2], 'con la opcion activada toggle devuelve counts.dislike real (2)');
$x = $r->toggle($ctx(['opts' => R::options(['reactions_dislike' => '0'])]), 10, 'like');
t_ok($x['body']['counts']['dislike'] === 0 && $x['body']['counts']['like'] === 0, 'con «No me gusta: No» toggle devuelve counts.dislike = 0 (no revela el numero)');
$st = (array) $r->state($ctx(['opts' => R::options(['reactions_dislike' => '0'])]), '10')['body']['items'];
t_ok($st['10']['dislike'] === 0, 'igual que state');

echo "C) BAJO-3: contenidos distintos y frecuencia en la consulta masiva\n";
$s = new MemStore(); for ($i = 1; $i <= 12; $i++) { $s->comment($i, 100 + $i, 1, 99); } $s->comment(50, 100, 1, 99); $s->comment(51, 100, 1, 99); $r = new R($s);
t_ok(R::MAX_ASSETS === 10, 'maximo 10 contenidos distintos por consulta');
$n = 0; $cv = ['canViewAsset' => function (int $a) use (&$n) { $n++; return true; }];
$x = $r->state($ctx($cv), implode(',', range(1, 10)));
t_ok($x['status'] === 200 && $n === 10, '10 contenidos distintos: 200 (una comprobacion de permiso por contenido)');
$n = 0; $x = $r->state($ctx($cv), implode(',', range(1, 11)));
t_ok($x['status'] === 400 && $x['body'] === ['ok' => false, 'error' => 'invalid'] && $n === 0, '11 contenidos distintos: 400 generico y NINGUNA comprobacion de permiso (rechazo barato)');
t_ok($r->state($ctx(), '1,2,3,4,5,6,7,8,9,10,11,12')['status'] === 400, '12 contenidos: 400');
t_ok($r->state($ctx(), '50,51,50')['status'] === 200 && $r->state($ctx(), '50,51,999999')['status'] === 200, 'varios comentarios del MISMO contenido (y ids inexistentes) no cuentan como contenidos distintos');
$n = 0; $x = $r->state($ctx(['allowRead' => function () use (&$n) { $n++; return false; }]), '50');
t_ok($x['status'] === 429 && $x['body'] === ['ok' => false, 'error' => 'rate'] && $n === 1, 'limite de frecuencia superado: 429 generico');
$n = 0; $x = $r->state($ctx(['allowRead' => function () use (&$n) { $n++; return true; }]), 'x');
t_ok($x['status'] === 400 && $n === 0, 'una consulta con ids no validos no gasta cupo');
$n = 0; $r->state($ctx(['allowRead' => function () use (&$n) { $n++; return true; }]), '50'); t_ok($n === 1, 'una consulta valida gasta un cupo');
t_ok($r->state($ctx(['opts' => R::options(['reactions_enabled' => '0']), 'allowRead' => fn() => false]), '50')['body'] === ['ok' => true, 'enabled' => false], 'con las reacciones desactivadas no se consulta nada ni gasta cupo');
$t = 1000; $mem = [];
$lim = new ReactionRateLimiter(function ($k) use (&$mem) { return $mem[$k] ?? null; }, function ($k, $v) use (&$mem) { $mem[$k] = $v; }, R::READ_LIMIT, 60, function () use (&$t) { return $t; });
$ok = 0; for ($i = 0; $i < 250; $i++) { if ($lim->allowKey('i0123456789abcdef0123')) { $ok++; } }
t_ok(R::READ_LIMIT === 240 && $ok === 240 && !$lim->allowKey('i0123456789abcdef0123'), '240 consultas por minuto y clave; la 241 se bloquea');
t_ok($lim->allowKey('s7') && $lim->allowKey('iotra'), 'cada clave (usuario s<id>, IP i<hash>) tiene su cubeta');
$t += 61; t_ok($lim->allowKey('i0123456789abcdef0123'), 'pasada la ventana vuelve a permitir');
t_ok($lim->allow(7) && $lim->allowKey('u7') !== null, 'allow(id) sigue funcionando (clave u<id>)');
$mem2 = []; $l2 = new ReactionRateLimiter(function ($k) use (&$mem2) { return $mem2[$k] ?? null; }, function ($k, $v) use (&$mem2) { $mem2[$k] = $v; }, 2, 60, function () { return 1; });
$l2->allow(5); $l2->allow(5); t_ok(!$l2->allow(5) && $l2->allowKey('s5'), 'las cubetas de lectura (s5) y de escritura (u5) son independientes');
t_ok($lim->allowKey("x'; DROP") === true && $lim->allowKey('') === true && !array_filter(array_keys($mem), fn($k) => !preg_match('/^[a-z][a-z0-9]{0,40}$/D', $k)), 'una clave con caracteres raros no se guarda (el controlador solo genera claves validas)');

echo "D) Carrera me gusta / no me gusta: bloqueo de la fila del comentario\n";
$s = new MemStore(); $s->comment(10, 1, 1, 99); $r = new R($s);
$r->toggle($ctx(), 10, 'like'); $s->log = []; $r->toggle($ctx(), 10, 'dislike');
t_ok($s->log === ['begin', 'lock:10', 'remove:10:7:1', 'add:10:7:2', 'commit'], 'toggle: begin, lock del comentario PRIMERO (antes de leer y escribir), cambio y commit: ' . implode(' ', $s->log));
$s->log = []; $r->toggle($ctx(), 10, 'dislike');
t_ok($s->log === ['begin', 'lock:10', 'remove:10:7:2', 'commit'], 'quitar tambien bloquea primero');
$s->goneOnLock = true; $s->log = []; $before = $s->rows;
$x = $r->toggle($ctx(), 10, 'like');
t_ok($x['status'] === 404 && $x['body'] === ['ok' => false, 'error' => 'unavailable'] && $s->rows === $before && !preg_match('/add|remove/', implode(' ', $s->log)), 'si el comentario desaparece entre la comprobacion y el bloqueo: 404 generico y no se escribe nada');
$s->goneOnLock = false; $s->failAdd = true;
t_ok($r->toggle($ctx(), 10, 'like')['status'] === 500 && end($s->log) === 'rollback', 'un fallo dentro de la transaccion: 500 generico y rollback (se libera el bloqueo)');
$src = $lf("$c/backend/src/Helper/ReactionStore.php");
t_ok(preg_match('/function lockComment\(int \$commentId\): bool\s*\{.*?max\(0, \$commentId\) \. \' FOR UPDATE\';/s', $src) === 1, 'ReactionStore::lockComment: SELECT ... FOR UPDATE por id entero (int, sin valores de la peticion)');
$db = new RecDb(); $st = new ReactionStore($db); $db->rows = [[]];
$ref = new ReflectionMethod($st, 'lockComment'); t_ok($ref->isPublic(), 'lockComment es publico y de la interfaz');
$logic = $lf("$c/backend/src/Helper/Reactions.php");
t_ok(strpos($logic, 'lockComment($id)') < strpos($logic, "userTypes(\$uid, [\$id])[\$id] ?? [], true);") && strpos($logic, 'transaction(function') < strpos($logic, 'lockComment($id)'), 'Reactions::toggle: lockComment es lo primero dentro de la transaccion');

echo "E) ReactionStore: consultas de la 0.7.1\n";
$db = new RecDb(); $st = new ReactionStore($db); $db->rows = [[['id' => '10', 'asset_id' => '1', 'enabled' => '1', 'created_by' => '0', 'email' => ' A@B.C ']]];
$got = $st->comments([10]);
t_ok(str_contains($db->log[0]->text(), '`email`') && $got[10]['email'] === 'A@B.C' && $got[10]['created_by'] === 0, 'comments(): selecciona y devuelve el email (recortado)');
$db = new RecDb(); $st = new ReactionStore($db); $st->counts([10, 11]); $txt = $db->log[0]->text();
t_ok(str_contains($txt, '#__engage_comments') && str_contains($txt, '#__users') && str_contains($txt, 'IFNULL') && str_contains($txt, 'created_by') && str_contains($txt, 'NOT (') && $db->log[0]->lists[0][1] === [10, 11], 'counts(): une comentarios y usuarios y deja fuera las reacciones propias (created_by o email de la cuenta); ids enlazados');
t_ok(!preg_match('/\b(10|11)\b/', preg_replace('/"[^"]*"/', '', $txt) . '') || $db->log[0]->lists[0][2] === 'int', 'counts(): los ids no se concatenan en el SQL');
$db = new RecDb(); ReactionStore::deleteOwnLikes($db, 7, [20, '21', 'x', -1, "5; DROP"]);
t_ok(count($db->log) === 1 && $db->log[0]->type === 'delete' && $db->log[0]->binds === [':uid' => 7] && $db->log[0]->lists[0][1] === [20, 21] && $db->log[0]->lists[1][1] === [1, 2], 'deleteOwnLikes(): borra SOLO me gusta y no me gusta (tipos 1 y 2; el favorito se conserva) del usuario en esos comentarios, con parametros enlazados');
$db = new RecDb(); ReactionStore::deleteOwnLikes($db, 0, [1]); ReactionStore::deleteOwnLikes($db, 7, []); t_ok(!$db->log, 'sin usuario o sin comentarios no se consulta nada');
$db = new RecDb(); $db->failExecute = true; try { ReactionStore::deleteOwnLikes($db, 7, [1]); t_ok(true, 'deleteOwnLikes() nunca lanza'); } catch (Throwable $e) { t_ok(false, 'deleteOwnLikes() lanza'); }

echo "F) MEDIO-1: borrado de cuenta (comprobaciones estaticas; el comportamiento real esta en tests/joomla-live/lib/borrado-usuario-cli.php)\n";
$usr = $lf("$root/src/plugins/user/engage/src/Extension/Engage.php");
$after = substr($usr, strpos($usr, 'public function onUserAfterDelete'), strpos($usr, 'public function onUserBeforeDelete') - strpos($usr, 'public function onUserAfterDelete'));
t_ok(!str_contains($after, 'if (array_key_exists($userId, $this->usersToRemove))') && str_contains($after, 'if (!array_key_exists($userId, $this->usersToRemove))'), 'onUserAfterDelete: la condicion YA NO esta invertida (sale si NO se vio al usuario antes de borrarlo)');
t_ok(strpos($after, 'if (!$success)') < strpos($after, 'ReactionStore::deleteForUser') && strpos($after, 'if (!$success)') < strpos($after, 'pseudonymiseUserComments') && strpos($after, 'ReactionStore::deleteForUser') < strpos($after, 'pseudonymiseUserComments'), 'se comprueba success ANTES de borrar reacciones y de seudonimizar; si Joomla fallo no se toca nada');
t_ok(preg_match('/if \(!\$success\)\s*\{\s*unset\(\$this->usersToRemove\[\$userId\]\);\s*return;/', $after) === 1, 'si Joomla fallo se olvida el usuario cacheado');
t_ok(preg_match('/try\s*\{.*?pseudonymiseUserComments\(\$userObject, true\);\s*\}\s*catch \(Throwable \$e\)/s', $after) === 1 && str_contains($after, 'Log::add('), 'la seudonimizacion va en try/catch (Throwable): un fallo no llega a quien borra la cuenta y se anota en el registro');
t_ok(strpos($after, 'unset($this->usersToRemove[$userId]);') < strpos($after, 'try'), 'el usuario cacheado se olvida antes de seudonimizar (no se acumula memoria ni se repite)');
$before = substr($usr, strpos($usr, 'public function onUserBeforeDelete'), strpos($usr, 'public function onUserLogin') - strpos($usr, 'public function onUserBeforeDelete'));
t_ok(preg_match('/try\s*\{\s*\$userObject = UserFetcher::getUser\(\$userId\);.*?\$this->usersToRemove\[\$userId\] = clone \$userObject;.*?\}\s*catch \(Throwable \$e\)/s', $before) === 1, 'onUserBeforeDelete no lanza nunca (un fallo aqui impediria borrar la cuenta)');
$meta = $lf("$c/frontend/src/Helper/Meta.php");
$fn = substr($meta, strpos($meta, 'public static function pseudonymiseUserComments'), strpos($meta, 'private static function getMVCFactory') - strpos($meta, 'public static function pseudonymiseUserComments'));
t_ok(str_contains($fn, "->qn('ip') . ' = NULL'") && str_contains($fn, "user_agent') . \" = ''\"") && substr_count($fn, "->qn('ip') . ' = NULL'") === 3, 'se vacian la IP y el navegador en los tres UPDATE (propios, por email y los convertidos a invitado)');
t_ok(str_contains($fn, "->qn('created_by') . ' = 0'") && str_contains($fn, '$convertToGuest && !empty($cid)'), 'al convertir a invitado created_by pasa a 0 (ningun id reutilizable)');
t_ok(str_contains($fn, '$db->transactionStart();') && str_contains($fn, '$db->transactionCommit();') && str_contains($fn, '$db->transactionRollback();') && str_contains($fn, 'throw $e;'), 'todo en una transaccion: o se hace entero o no se hace (y el error se propaga al plugin, que lo anota)');
t_ok(substr_count($fn, 'ParameterType::') >= 10 && !preg_match('/\$db->q\(\$user->(email|id)\)/', $fn) && !str_contains($fn, "implode(','"), 'consultas con parametros enlazados (sin concatenar el email ni listas de ids)');
t_ok(str_contains($fn, "if (\$email !== '')") && str_contains($fn, 'parse_url((string) Uri::root(), PHP_URL_HOST)') && str_contains($fn, 'catch (Throwable $e)') && str_contains($fn, "'localhost'"), 'un usuario sin email no casa con todos los comentarios sin email; el host tiene alternativa si no hay HTTP_HOST (consola)');
foreach (['datacompliance', 'privacy'] as $pl) { t_ok(str_contains($lf("$root/src/plugins/$pl/engage/src/Extension/Engage.php"), 'Meta::pseudonymiseUserComments($user)'), "plugin $pl/engage sigue usando la misma funcion (ahora tambien vacia IP y navegador)"); }
t_ok(str_contains($usr, 'deleteOwnLikes($db, (int) $user->id, $ownIds)') && strpos($usr, 'deleteOwnLikes') < strpos($usr, "->update(\$db->qn('#__engage_comments'))"), 'BAJO-1: ownComments() elimina los me gusta / no me gusta del usuario en los comentarios que pasan a ser suyos, ANTES de cambiar el propietario');
$ctl = $lf("$c/frontend/src/Controller/ReactionsController.php");
t_ok(str_contains($ctl, "'userEmail'    => (\$uid > 0) ? (string) \$user->email : ''") && str_contains($ctl, "'allowRead'] = \$this->readLimiter((int) \$ctx['userId'])") && str_contains($ctl, 'Reactions::READ_LIMIT') && str_contains($ctl, "'i' . substr(md5(\$ip), 0, 20)"), 'controlador: email de la SESION (no de la peticion), limite de lectura por usuario o IP');

echo "G) MEDIO-2: ACL de categoria (comprobaciones estaticas; el ataque real esta en tests/joomla-live/lib/reacciones-071-http.php)\n";
$cont = $lf("$root/src/plugins/content/engage/src/Extension/Engage.php");
$ld = substr($cont, strpos($cont, 'private function loadArticleObject'), 2400);
t_ok(str_contains($ld, "->join('LEFT', \$db->qn('#__categories', 'c'), \$db->qn('c.id') . ' = ' . \$db->qn('a.catid'))") && str_contains($ld, "\$db->qn('c.access', 'category_access')") && str_contains($ld, "\$db->qn('c.published', 'category_published')") && str_contains($ld, "\$db->qn('c.title', 'category_title')") && str_contains($ld, "->from(\$db->qn('#__content', 'a'))"), 'loadArticleObject: une #__categories y selecciona category_access, category_title, category_alias y category_published');
t_ok(str_contains($cont, "'category_published' => isset(\$row->category_published) ? (int) \$row->category_published : null") && str_contains($cont, "'category_published' => \$row->category_published,"), 'la fila en cache y la respuesta onAkeebaEngageGetAssetMeta llevan category_published');
t_ok(str_contains($meta, "'category_published' => null") && str_contains($meta, 'public static function isCategoryPublished(array $meta): bool'), 'Meta: category_published en los metadatos y isCategoryPublished()');
// isCategoryPublished se ejecuta de verdad (extraida del codigo fuente)
preg_match('/public static function isCategoryPublished\(array \$meta\): bool\s*\{(.*?)\n\t\}/s', $meta, $mm);
$fnCat = eval('return static function (array $meta): bool {' . $mm[1] . '};');
t_ok($fnCat(['category_published' => 1]) === true && $fnCat(['category_published' => 2]) === true && $fnCat(['category_published' => '2']) === true, 'categoria publicada (1) o archivada (2): visible (como la pagina de Joomla)');
t_ok($fnCat(['category_published' => 0]) === false && $fnCat(['category_published' => -2]) === false && $fnCat(['category_published' => '0']) === false && $fnCat(['category_published' => '-2']) === false, 'sin publicar (0) o en la papelera (-2): NO visible');
t_ok($fnCat(['category_published' => null]) === true && $fnCat([]) === true, 'desconocida o no aplicable (otros tipos de contenido): visible');
t_ok(preg_match('/function canView\(User \$user, int \$assetId\): bool.*?empty\(\$meta\[\'published\'\]\).*?Meta::isCategoryPublished\(\$meta\).*?getAuthorisedViewLevels.*?\[\'access\', \'parent_access\'\]/s', $ctl) === 1, 'ReactionsController::canView: publicado, categoria publicada y niveles de acceso del contenido y de su categoria');
t_ok(preg_match('/assertAssetAccess.*?\$assetMeta\[\'published\'\].*?Meta::isCategoryPublished\(\$assetMeta\).*?\$parentAccess = \$assetMeta\[\'parent_access\'\]/s', $lf("$c/frontend/src/Model/CommentModel.php")) === 1, 'CommentModel::assertAssetAccess (publicar un comentario): tambien exige categoria publicada');

echo "H) plugin datacompliance/engage: exportacion y borrado de reacciones (analogo a los comentarios; sin Akeeba Data Compliance real, solo estatico)\n";
$dc = $lf("$root/src/plugins/datacompliance/engage/src/Extension/Engage.php");
t_ok(str_contains($dc, 'use Akeeba\\Component\\Engage\\Administrator\\Helper\\ReactionStore;') && strpos($dc, 'pseudonymiseUserComments($user)') < strpos($dc, 'ReactionStore::deleteForUser($this->getDatabase(), (int) $userID)'), 'onDataComplianceDeleteUser: ademas de seudonimizar los comentarios borra las reacciones del usuario (ReactionStore::deleteForUser, que nunca lanza)');
t_ok(str_contains($dc, "addAttribute('name', 'engage_reactions')") && str_contains($dc, 'ReactionStore::exportForUser($db, (int) $userID)') && substr_count($dc, 'adoptChild($domain, ') === 6, 'onDataComplianceExportUser: dominio engage_reactions con el mismo mecanismo que los comentarios (Export y OldExport)');
t_ok(!str_contains(substr($dc, strpos($dc, 'onDataComplianceDeleteUser(Event'), 1500), "\$ret['engage_reactions']"), 'el resultado de auditoria no cambia de forma (solo ids de comentarios)');
$ini = []; foreach (['en-GB', 'es-ES'] as $l) { $ini[$l] = parse_ini_string($lee("$root/src/plugins/datacompliance/engage/language/$l/plg_datacompliance_engage.ini"), false, INI_SCANNER_RAW); }
t_ok(!empty($ini['en-GB']['PLG_DATACOMPLIANCE_ENGAGE_DOMAINNAMEACTIONS_2']) && !empty($ini['es-ES']['PLG_DATACOMPLIANCE_ENGAGE_DOMAINNAMEACTIONS_2']) && str_contains($dc, "DOMAINNAMEACTIONS_2'") && str_contains($ini['es-ES']['PLG_DATACOMPLIANCE_ENGAGE_DOMAINNAMEACTIONS_2'], 'sus reacciones'), 'texto de lo que se borrara (reacciones, IP y navegador) en en-GB y es-ES (de usted)');

t_fin();
