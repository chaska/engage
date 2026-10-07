<?php
/**
 * 0.7.1: BORRADO DE UNA CUENTA contra Joomla REAL (CLI, framework y plugins reales). La prueba de la 0.7.0 (reacciones-cli.php) llamaba a
 * User::delete() pero solo miraba las reacciones, no los comentarios, y por eso no vio que el plugin user/engage salia siempre antes de
 * seudonimizarlos (condicion invertida heredada de Akeeba Engage). Aqui se mira la BASE DE DATOS tras un User::delete() real:
 *  - los comentarios del usuario (created_by) quedan con el texto de borrado, nombre «Deleted Account N», email deleted.N@host, IP y
 *    navegador vacios y created_by = 0;
 *  - los comentarios de INVITADO con el email del usuario, igual;
 *  - los comentarios de los demas, intactos; las reacciones hechas por el usuario se borran y las que recibieron sus comentarios se conservan;
 *  - si Joomla no puede borrar la cuenta (disparador que lo impide) no se toca ningun comentario;
 *  - si el borrado de la cuenta triunfa pero la seudonimizacion falla (tabla de comentarios inaccesible), la cuenta SE BORRA igualmente
 *    y el fallo no llega a quien borra (se anota en el registro de Joomla);
 *  - con onUserAfterDelete con success = false (evento directo) no se toca nada.
 * Escribe $WORK/resultados-borrado-usuario.json. Sale con 1 si algo FALLA. Deja la base de datos como la encontro.
 */
ob_start();
define('_JEXEC', 1);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$site = "$WORK/" . (getenv('SITE_DIR') ?: 'site');
define('JPATH_BASE', $site);
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Event\User\AfterDeleteEvent;
use Joomla\CMS\Event\User\BeforeDeleteEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\ArrayHelper;

$container = Factory::getContainer();
$container->alias('session.cli', 'session.web.site')->alias('session', 'session.web.site')->alias('JSession', 'session.web.site')
	->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(ConsoleApplication::class);
Factory::$application = $app;
if (method_exists($app, 'createExtensionNamespaceMap')) { $app->createExtensionNamespaceMap(); }
$app->getLanguage()->load('lib_joomla', JPATH_ADMINISTRATOR);
$db = $container->get(DatabaseInterface::class);
$uf = $container->get(UserFactoryInterface::class);
$app->loadIdentity($uf->loadUserByUsername('admintest'));

$RES = [];
function r(string $d, bool $ok, string $ev = ''): void { global $RES; $RES[] = ['desc' => $d, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev]; printf("%-6s %s%s\n", $ok ? 'PASA' : 'FALLA', $d, $ev !== '' ? "  [$ev]" : ''); }
function sql(string $s) { global $db; $db->setQuery($s)->execute(); }
function val(string $s) { global $db; return $db->setQuery($s)->loadResult(); }
function row(string $s) { global $db; return $db->setQuery($s)->loadAssoc() ?: []; }

$ids   = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$asset = (int) $ids['art_publico_asset'];
$plug  = $db->setQuery("SELECT extension_id, enabled FROM #__extensions WHERE type='plugin' AND folder='user' AND element='engage'")->loadAssocList('extension_id');
register_shutdown_function(function () use ($plug) { global $db; foreach ($plug as $id => $p) { $db->setQuery("UPDATE #__extensions SET enabled=" . (int) $p['enabled'] . " WHERE extension_id=" . (int) $id)->execute(); } });
sql("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND folder='user' AND element='engage'");
PluginHelper::importPlugin('user');

$pass = 'x';
$mk = function (string $u) use ($pass) {
	global $db;
	sql("DELETE FROM #__users WHERE username='$u'");
	sql("INSERT INTO #__users (name,username,email,password,block,sendEmail,registerDate,activation,params,resetCount,otpKey,otep,requireReset,authProvider) VALUES ('Nombre $u','$u','$u@example.invalid','$pass',0,0,NOW(),'','{}',0,'','',0,'')");
	$id = (int) val("SELECT id FROM #__users WHERE username='$u'");
	sql("INSERT INTO #__user_usergroup_map (user_id,group_id) VALUES ($id,2)");
	return $id;
};
$esc = fn(string $s) => $db->quote($s);
$cm = function (int $by, string $body, string $name, string $email, string $ip = '198.51.100.9', string $ua = 'Mozilla/5.0 (UA-secreto)', int $enabled = 1) use ($db, $asset, $esc) {
	$n = $name === '' ? 'NULL' : $esc($name); $e = $email === '' ? 'NULL' : $esc($email);
	sql("INSERT INTO #__engage_comments (asset_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset," . $esc($body) . ",$n,$e,'$ip'," . $esc($ua) . ",$enabled,NOW(),$by)");
	return (int) val('SELECT LAST_INSERT_ID()');
};
$c = fn(int $id) => row("SELECT * FROM #__engage_comments WHERE id=$id");
$textoEs = 'Se borró el comentario por la eliminación de la cuenta'; $textoEn = 'Comment removed due to account deletion';
$limpiar = function () use ($asset) { sql("DELETE FROM #__engage_reactions"); sql("DELETE FROM #__engage_comments WHERE asset_id=$asset"); sql("DELETE FROM #__engage_unsubscribe WHERE email LIKE '%@example.invalid'"); };
$limpiar();

// ===== 1. Borrado normal
$U = $mk('borrarcuenta'); $O = $mk('borrarotro'); $mail = 'borrarcuenta@example.invalid';
$a = $cm($U, '<p>Mi comentario como usuario</p>', '', '');                                   // created_by = U
$b = $cm(0, '<p>Mi comentario de invitado</p>', 'Fulano', strtoupper($mail), '198.51.100.10', 'UA-invitado'); // invitado con el email de U (otra capitalizacion)
$d = $cm($O, '<p>Comentario de otro usuario</p>', '', '', '203.0.113.5', 'UA-otro');
$e = $cm(0, '<p>Invitado ajeno</p>', 'Ajeno', 'ajeno@example.invalid', '203.0.113.6', 'UA-ajeno');
$f = $cm($U, '<p>Mi comentario sin publicar</p>', '', '', '198.51.100.11', 'UA-nopub', 0);
sql("INSERT INTO #__engage_unsubscribe (asset_id,email) VALUES ($asset,'$mail')");
$ins = fn($cid, $u, $t) => sql("INSERT INTO #__engage_reactions (comment_id,user_id,type,created) VALUES ($cid,$u,$t,'2026-10-07 10:00:00')");
$ins($d, $U, 1); $ins($d, $U, 3); $ins($a, $O, 1); $ins($a, $O, 3); $ins($e, $O, 2);
$antesD = $c($d); $antesE = $c($e);
r('preparado: 3 comentarios de la cuenta (propios y de invitado con su email), 2 ajenos, 3+2 reacciones', (int) val("SELECT COUNT(*) FROM #__engage_comments WHERE id IN ($a,$b,$d,$e,$f)") === 5 && (int) val('SELECT COUNT(*) FROM #__engage_reactions') === 5);

$u = $uf->loadUserById($U);
$res = $u->delete();
r('Joomla borra la cuenta (User::delete devuelve true y la fila de #__users desaparece)', $res === true && (int) val("SELECT COUNT(*) FROM #__users WHERE id=$U") === 0);
$host = '127.0.0.1';
$pseudo = function (array $x) use ($U, $textoEs, $textoEn, $host) {
	return in_array($x['body'], [$textoEs, $textoEn], true)
		&& in_array($x['name'], ["Deleted Account $U", "Cuenta $U borrada"], true)
		&& $x['email'] === "deleted.$U@$host"
		&& $x['ip'] === null && $x['user_agent'] === '';
};
foreach (['a' => [$a, 'comentario del usuario (created_by)'], 'f' => [$f, 'comentario del usuario SIN publicar'], 'b' => [$b, 'comentario de INVITADO con el email del usuario (otra capitalizacion)']] as $k => [$id, $desc]) {
	$x = $c($id);
	r("$desc: texto de borrado, nombre «Deleted Account N», email deleted.N@host, IP vacia y navegador vacio", $pseudo($x), json_encode(['body' => $x['body'], 'name' => $x['name'], 'email' => $x['email'], 'ip' => $x['ip'], 'ua' => $x['user_agent']], JSON_UNESCAPED_UNICODE));
}
r('los comentarios de la cuenta quedan como invitado (created_by = 0): ningun id de usuario reutilizable', (int) $c($a)['created_by'] === 0 && (int) $c($f)['created_by'] === 0 && (int) $c($b)['created_by'] === 0);
r('el comentario de otro usuario y el de un invitado ajeno quedan INTACTOS', $c($d) === $antesD && $c($e) === $antesE);
r('el aviso de baja (unsubscribe) con el email del usuario se elimina', (int) val("SELECT COUNT(*) FROM #__engage_unsubscribe WHERE email='$mail'") === 0);
r('las reacciones HECHAS por la cuenta se borran (0) y las que recibieron sus comentarios se conservan (3)', (int) val("SELECT COUNT(*) FROM #__engage_reactions WHERE user_id=$U") === 0 && (int) val('SELECT COUNT(*) FROM #__engage_reactions') === 3);

// ===== 2. Joomla no puede borrar la cuenta: no se toca nada
$limpiar();
$U2 = $mk('borrarcuenta2');
$a2 = $cm($U2, '<p>Comentario que debe sobrevivir</p>', '', ''); $antes2 = $c($a2);
sql('DROP TRIGGER IF EXISTS engage_test_impide_borrado');
sql("CREATE TRIGGER engage_test_impide_borrado BEFORE DELETE ON #__users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='no se puede borrar'");
$lanzo = false; $res2 = null;
try { $res2 = $uf->loadUserById($U2)->delete(); } catch (Throwable $t) { $lanzo = true; }
sql('DROP TRIGGER IF EXISTS engage_test_impide_borrado');
r('Joomla NO puede borrar la cuenta (un disparador lo impide): la cuenta sigue y el comentario NO se seudonimiza', (int) val("SELECT COUNT(*) FROM #__users WHERE id=$U2") === 1 && ($lanzo || $res2 === false) && $c($a2) === $antes2, $lanzo ? 'Joomla lanzo la excepcion' : 'delete() devolvio false');

// ===== 3. Evento directo con success = false (cuenta no borrada)
$u2 = $uf->loadUserById($U2);
$disp = $app->getDispatcher();
$disp->dispatch('onUserBeforeDelete', new BeforeDeleteEvent('onUserBeforeDelete', ['subject' => ArrayHelper::fromObject($u2, false)]));
$disp->dispatch('onUserAfterDelete', new AfterDeleteEvent('onUserAfterDelete', ['subject' => ArrayHelper::fromObject($u2, false), 'deletingResult' => false, 'errorMessage' => 'fallo']));
r('onUserAfterDelete con success = false (la cuenta no se borro): el comentario sigue intacto', $c($a2) === $antes2 && (int) val("SELECT COUNT(*) FROM #__users WHERE id=$U2") === 1);

// ===== 4. La cuenta se borra aunque falle la seudonimizacion
sql("DELETE FROM #__user_usergroup_map WHERE user_id=$U2"); sql("DELETE FROM #__users WHERE id=$U2");
$limpiar();
$U3 = $mk('borrarcuenta3');
$a3 = $cm($U3, '<p>Comentario con tabla inaccesible</p>', '', '');
sql('RENAME TABLE #__engage_comments TO #__engage_comments_oculta');
$lanzo3 = false; $res3 = null;
try { $res3 = $uf->loadUserById($U3)->delete(); } catch (Throwable $t) { $lanzo3 = true; $msg3 = $t->getMessage(); }
sql('RENAME TABLE #__engage_comments_oculta TO #__engage_comments');
r('la seudonimizacion FALLA (tabla de comentarios inaccesible): la cuenta se borra igualmente y el error no llega a quien borra', !$lanzo3 && $res3 === true && (int) val("SELECT COUNT(*) FROM #__users WHERE id=$U3") === 0, $lanzo3 ? ($msg3 ?? '') : '');
$limpiar();

// ===== 5. Cuenta sin comentarios ni reacciones
$U4 = $mk('borrarcuenta4');
$r4 = $uf->loadUserById($U4)->delete();
r('borrar una cuenta sin comentarios ni reacciones funciona', $r4 === true && (int) val("SELECT COUNT(*) FROM #__users WHERE id=$U4") === 0);

// ---- Limpieza final
sql("DELETE FROM #__user_usergroup_map WHERE user_id IN ($O)"); sql("DELETE FROM #__users WHERE id IN ($O)");
sql("DELETE FROM #__users WHERE username LIKE 'borrarcuenta%'");
file_put_contents("$WORK/resultados-borrado-usuario.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$fl = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
printf("\n%d comprobaciones, %d FALLAN\n", count($RES), $fl);
exit($fl ? 1 : 0);
