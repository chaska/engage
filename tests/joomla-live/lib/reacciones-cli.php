<?php
/**
 * 0.7.0: reacciones y RGPD contra Joomla REAL (CLI, framework y plugins reales). Comprueba con los modelos de com_privacy que el plugin
 * privacy/engage EXPORTA las reacciones del usuario (solo las suyas: ni las de otros sobre sus comentarios) y las ELIMINA al atender una
 * peticion de supresion, y que el plugin user/engage las borra cuando Joomla borra al usuario (User::delete). Escribe
 * $WORK/resultados-reacciones-cli.json. Sale con 1 si algo FALLA. Restaura el estado de los plugins al acabar.
 */
ob_start(); // los avisos de sesion de Joomla fallan si ya se ha escrito algo
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
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

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

$plug = $db->setQuery("SELECT extension_id, enabled FROM #__extensions WHERE type='plugin' AND ((folder='privacy' AND element='engage') OR (folder='user' AND element='engage'))")->loadAssocList('extension_id');
$restaurar = function () use ($db, $plug) { foreach ($plug as $id => $p) { sql("UPDATE #__extensions SET enabled=" . (int) $p['enabled'] . " WHERE extension_id=" . (int) $id); } };
register_shutdown_function($restaurar);
sql("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND ((folder='privacy' AND element='engage') OR (folder='user' AND element='engage'))");

// Usuarios, comentarios y reacciones de prueba
$ids = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$asset = (int) $ids['art_publico_asset'];
$mk = function (string $u) use ($db) {
	$id = (int) val("SELECT id FROM #__users WHERE username='$u'");
	if (!$id) {
		sql("INSERT INTO #__users (name,username,email,password,block,sendEmail,registerDate,activation,params,resetCount,otpKey,otep,requireReset,authProvider) VALUES ('$u','$u','$u@example.invalid','x',0,0,NOW(),'','{}',0,'','',0,'')");
		$id = (int) val("SELECT id FROM #__users WHERE username='$u'");
		sql("INSERT INTO #__user_usergroup_map (user_id,group_id) VALUES ($id,2)");
	}
	return $id;
};
$P = $mk('reacpriv'); $O = $mk('reacotro'); $D = $mk('reacborrar');
sql("DELETE FROM #__engage_reactions"); sql("DELETE FROM #__engage_comments WHERE asset_id=$asset");
sql("INSERT INTO #__engage_comments (asset_id,body,ip,user_agent,enabled,created,created_by) VALUES ($asset,'<p>de P</p>','203.0.113.7','t',1,NOW(),$P)"); $cP = (int) val('SELECT LAST_INSERT_ID()');
sql("INSERT INTO #__engage_comments (asset_id,body,ip,user_agent,enabled,created,created_by) VALUES ($asset,'<p>de O</p>','203.0.113.7','t',1,NOW(),$O)"); $cO = (int) val('SELECT LAST_INSERT_ID()');
$ins = fn($c, $u, $t) => sql("INSERT INTO #__engage_reactions (comment_id,user_id,type,created) VALUES ($c,$u,$t,'2026-10-07 10:00:00')");
$ins($cO, $P, 1); $ins($cO, $P, 3); $ins($cP, $O, 1); $ins($cP, $O, 2 + 0 === 2 ? 3 : 3); $ins($cO, $D, 2); $ins($cP, $D, 1);
$tot = fn() => (int) val('SELECT COUNT(*) FROM #__engage_reactions');
r('preparadas 6 reacciones: 2 de P, 2 de otro sobre el comentario de P y 2 de un tercero', $tot() === 6);

// ---- Exportacion
sql("DELETE FROM #__privacy_requests WHERE email LIKE 'reac%@example.invalid'");
sql("INSERT INTO #__privacy_requests (email,requested_at,status,request_type,confirm_token,confirm_token_created_at) VALUES ('reacpriv@example.invalid',NOW(),1,'export','',NOW())");
$rid = (int) val('SELECT LAST_INSERT_ID()');
$mvc = $app->bootComponent('com_privacy')->getMVCFactory();
$export = $mvc->createModel('Export', 'Administrator', ['ignore_request' => true]);
$domains = $export->collectDataForExportRequest($rid);
$byName = [];
foreach ($domains ?: [] as $d) { $byName[(string) $d->name] = $d; }
$names = array_keys($byName);
r('la exportacion de com_privacy incluye el dominio engage_reactions', isset($byName['engage_reactions']), implode(',', $names));
$items = isset($byName['engage_reactions']) ? $byName['engage_reactions']->getItems() : [];
$dump = [];
foreach ($items as $it) { $row = []; foreach ($it->getFields() as $f) { $row[$f->name] = $f->value; } $dump[] = $row; }
r('exporta SOLO las 2 reacciones del usuario (comentario, tipo en texto y fecha), no las de otros', count($dump) === 2 && array_column($dump, 'type') === ['like', 'favorite'] && !array_diff(array_column($dump, 'comment_id'), [(string) $cO]) && isset($dump[0]['created']), json_encode($dump));
r('la exportacion solo lleva id, comentario, tipo y fecha: ni user_id ni nada de quien reacciono a los comentarios del usuario', !array_filter($dump, fn($row) => array_keys($row) !== ['id', 'comment_id', 'type', 'created']) && !str_contains(json_encode($dump), 'reacotro'));
r('la exportacion de comentarios sigue funcionando (dominio engage_comments)', isset($byName['engage_comments']));

// ---- Supresion
sql("INSERT INTO #__privacy_requests (email,requested_at,status,request_type,confirm_token,confirm_token_created_at) VALUES ('reacpriv@example.invalid',NOW(),1,'remove','',NOW())");
$rid2 = (int) val('SELECT LAST_INSERT_ID()');
$rem = $mvc->createModel('Remove', 'Administrator', ['ignore_request' => true]);
$ok = $rem->removeDataForRequest($rid2);
r('atender la supresion con com_privacy termina bien', $ok === true, json_encode($rem->getErrors()));
r('la supresion elimina las reacciones DEL USUARIO (0 de P) y conserva las de los demas (4)', (int) val("SELECT COUNT(*) FROM #__engage_reactions WHERE user_id=$P") === 0 && $tot() === 4);
r('el comentario de P queda seudonimizado (comportamiento de siempre)', (string) val("SELECT body FROM #__engage_comments WHERE id=$cP") !== '<p>de P</p>');

// ---- Borrado del usuario por Joomla (plugin user/engage)
$u = $uf->loadUserById($D);
$res = $u->delete();
r('Joomla borra al usuario (User::delete)', $res === true && (int) val("SELECT COUNT(*) FROM #__users WHERE id=$D") === 0);
r('el plugin user/engage borra TODAS las reacciones del usuario borrado (0) y no toca las de los demas (2)', (int) val("SELECT COUNT(*) FROM #__engage_reactions WHERE user_id=$D") === 0 && $tot() === 2, 'quedan ' . $tot());

// ---- Limpieza del articulo
sql("INSERT INTO #__engage_comments (asset_id,body,ip,user_agent,enabled,created,created_by) VALUES (" . (int) $ids['art_otro_asset'] . ",'<p>x</p>','203.0.113.7','t',1,NOW(),$O)"); $cX = (int) val('SELECT LAST_INSERT_ID()');
$ins($cX, $P, 1);
r('(antes de borrar el articulo) hay una reaccion en un comentario de otro articulo', (int) val("SELECT COUNT(*) FROM #__engage_reactions WHERE comment_id=$cX") === 1);
\Akeeba\Component\Engage\Administrator\Helper\ReactionStore::deleteForAsset($db, (int) $ids['art_otro_asset']);
r('deleteForAsset borra las reacciones de los comentarios de ese contenido y solo esas', (int) val("SELECT COUNT(*) FROM #__engage_reactions WHERE comment_id=$cX") === 0 && $tot() === 2);

// ---- Limpieza final
sql("DELETE FROM #__engage_reactions"); sql("DELETE FROM #__engage_comments WHERE asset_id IN ($asset," . (int) $ids['art_otro_asset'] . ")");
sql("DELETE FROM #__privacy_requests WHERE email LIKE 'reac%@example.invalid'");
sql("DELETE FROM #__user_usergroup_map WHERE user_id IN ($P,$O)"); sql("DELETE FROM #__users WHERE id IN ($P,$O)");
file_put_contents("$WORK/resultados-reacciones-cli.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$f = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
printf("\n%d comprobaciones, %d FALLAN\n", count($RES), $f);
exit($f ? 1 : 0);
