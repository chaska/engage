<?php
/**
 * 0.9.9 contra el Joomla REAL: un plugin de fotos del grupo engage que devuelve una URL del propio sitio SIN extension y CON parametros que depende de QUIEN MIRA
 * (como MiFoto: `/index.php?option=com_mifoto&task=photo.show&u=<16 hex>&s=<32|64|128|300>&v=<8 hex>`, con o sin subcarpeta):
 *  - se pinta tal cual (escapada) en el img del comentario y gana a Gravatar (preguntar / desactivado / siempre) con cualquier orden de plugins, sin data-engage-gravatar;
 *  - NO sale en los correos (ni en una plantilla que conserve {AVATAR_URL}): el correo solo lleva fuentes publicas (campo de usuario validado, absoluto) o nada;
 *  - con cache conservadora y progresiva (con y sin la cache de paginas y engagecache) y 4 espectadores (invitado, propietario, moderador, otro registrado)
 *    cada uno ve SU marca y nunca la de otro.
 * Requiere filtro-datos.php crear. Restaura todo.
 */
require __DIR__ . '/revision-082-comun.php';
$cfgPath = "$SITE/configuration.php"; $cfgOrig = file_get_contents($cfgPath);
$comOrig = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$grav = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='gravatar' AND folder='engage'");
$gravOrd = (string) one("SELECT ordering FROM jos_extensions WHERE element='gravatar' AND folder='engage'");
$pageC = (string) one("SELECT CONCAT(enabled,'|',IFNULL(params,'')) FROM jos_extensions WHERE element='cache' AND folder='system'");
$engC = (string) one("SELECT enabled FROM jos_extensions WHERE element='engagecache' AND folder='system'");
$mailsP = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_mails'");
$demoDir = "$SITE/plugins/engage/fotodemo";
$limpiaCache = function () use ($SITE) { foreach (['cache', 'administrator/cache'] as $d) { foreach (glob("$SITE/$d/*", GLOB_ONLYDIR) ?: [] as $gg) { exec('rm -rf ' . escapeshellarg($gg)); } } };
register_shutdown_function(function () use ($cfgPath, $cfgOrig, $comOrig, $grav, $gravOrd, $pageC, $engC, $mailsP, $demoDir, $limpiaCache) {
	file_put_contents($cfgPath, $cfgOrig);
	q("UPDATE jos_extensions SET params='" . esc($comOrig) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc($grav) . "', ordering=" . (int) $gravOrd . " WHERE element='gravatar' AND folder='engage'");
	[$pe, $pp] = explode('|', $pageC, 2); q("UPDATE jos_extensions SET enabled=" . (int) $pe . ", params='" . esc($pp) . "' WHERE element='cache' AND folder='system'");
	q("UPDATE jos_extensions SET enabled=" . (int) $engC . " WHERE element='engagecache' AND folder='system'");
	q("UPDATE jos_extensions SET params='" . esc($mailsP) . "' WHERE element='com_mails'");
	q("DELETE FROM jos_extensions WHERE element='fotodemo' AND folder='engage'");
	q("DELETE FROM jos_fields_values WHERE field_id IN (SELECT id FROM jos_fields WHERE name='r3foto-avatar')"); q("DELETE FROM jos_fields WHERE name='r3foto-avatar'");
	q("DELETE FROM jos_engage_comments WHERE body LIKE '%F9R-FOTO%' OR name LIKE 'R3N-%'"); borraUsuarios();
	foreach (glob("$demoDir/*") ?: [] as $f) { @unlink($f); } @rmdir($demoDir); $limpiaCache();
});
$uid = usuario('r3foto', 2); usuario('r3mod', 6); usuario('r3sub', 2);
q("DELETE FROM jos_engage_comments WHERE body LIKE '%F9R-FOTO%'");
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>F9R-FOTO propietario</p>',NULL,NULL,'127.0.0.1','t',1,'2000-01-01 10:00:00',$uid)");
$hex16 = fn(int $u) => substr(md5('u' . $u), 0, 16); $hex8 = fn(int $v) => sprintf('%08x', $v);
$crearPlugin = function (string $sub) use ($demoDir, $hex16, $uid, $ASSET_PUB) {
	@mkdir($demoDir, 0755, true); file_put_contents("$demoDir/sub.txt", $sub);
	file_put_contents("$demoDir/fotodemo.php", '<?php defined("_JEXEC") or die; use Joomla\CMS\Factory; use Joomla\CMS\Plugin\CMSPlugin; use Joomla\Event\Event; use Joomla\Event\SubscriberInterface; class PlgEngageFotodemo extends CMSPlugin implements SubscriberInterface { public static function getSubscribedEvents(): array { return ["onAkeebaEngageUserAvatarURL" => "avatar"]; } public function avatar(Event $e): void { $a = array_values($e->getArguments()); $u = $a[0]; $size = (int) ($a[1] ?? 48); $ctx = (string) ($a[2] ?? ""); if (!(int) ($u->id ?? 0) || $u->username !== "r3foto") { return; } $s = $size <= 32 ? 32 : ($size <= 64 ? 64 : ($size <= 128 ? 128 : 300)); $viewer = (int) (Factory::getApplication()->getIdentity()->id ?? 0); @file_put_contents(' . var_export("$demoDir/contextos.log", true) . ', $ctx . "\n", FILE_APPEND); $e->setArgument("result", array_merge($e->getArgument("result", []), [(string) @file_get_contents(' . var_export("$demoDir/sub.txt", true) . ') . "/index.php?option=com_mifoto&task=photo.show&u=" . substr(md5("u" . $u->id), 0, 16) . "&s=" . $s . "&v=" . sprintf("%08x", $viewer)])); } }');
	file_put_contents("$demoDir/fotodemo.xml", '<?xml version="1.0" encoding="utf-8"?><extension type="plugin" group="engage" method="upgrade"><name>fotodemo</name><version>1.0.0</version><files><filename plugin="fotodemo">fotodemo.php</filename></files></extension>');
	q("DELETE FROM jos_extensions WHERE element='fotodemo' AND folder='engage'");
	q("INSERT INTO jos_extensions (name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state) VALUES ('fotodemo','plugin','fotodemo','engage',0,1,1,0,0,'" . esc(json_encode(['name' => 'fotodemo', 'version' => '1.0.0'])) . "','{}','',5,0)");
};
$setCom = function (array $extra) use ($comOrig) { $p = array_merge(json_decode($comOrig, true) ?: [], ['max_level' => '3', 'comments_ordering' => 'asc', 'default_limit' => '20', 'sort_selector' => '0', 'reactions_enabled' => '0', 'copy_link' => '0', 'customfield_avatar' => ''], $extra); q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); usleep(250000); };
$modo = function (string $m, int $og = 1, int $of = 9) use ($grav) { $p = json_decode($grav, true) ?: []; $p['mode'] = $m; q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "', ordering=$og WHERE element='gravatar' AND folder='engage'"); q("UPDATE jos_extensions SET ordering=$of WHERE element='fotodemo' AND folder='engage'"); usleep(300000); };
$imgDe = function (string $html, string $marca): array { $p = strpos($html, $marca); if ($p === false) { return ['(sin comentario)', '', '']; } $a = strrpos(substr($html, 0, $p), '<article'); $blk = substr($html, $a === false ? 0 : $a, $p - ($a === false ? 0 : $a) + 400); preg_match('~<img[^>]*class="akengage-commenter-avatar[^>]*>~', $blk, $i); $tag = $i[0] ?? ''; return [preg_match('/\ssrc="([^"]*)"/', $tag, $s) ? $s[1] : '(sin img)', str_contains($tag, 'data-engage-gravatar') ? 'diferida' : '', $tag]; };
$pagina = function (?Cliente $c = null) use ($BASE, $WORK, $PAGE) { $c ??= new Cliente($BASE, "$WORK/tmp", 'mf' . mt_rand()); return $c->get($PAGE)['body']; };
$url = fn(string $sub, int $viewer, int $size = 0) => $sub . '/index.php?option=com_mifoto&task=photo.show&u=' . substr(md5('u' . $GLOBALS['uid']), 0, 16) . '&s=' . ($size ?: 0) . '&v=' . sprintf('%08x', $viewer);
$patron = fn(string $sub) => '~^' . preg_quote($sub, '~') . '/index\.php\?option=com_mifoto&task=photo\.show&u=' . $hex16($uid) . '&s=(32|64|128|300)&v=00000000$~';

// ---- 1) se pinta tal cual y gana, tres modos x dos ordenes (y con subcarpeta)
foreach (['' => ['ask', 'off', 'always'], '/sub' => ['ask']] as $sub => $modos) {
	$crearPlugin($sub); $setCom([]);
	foreach ($modos as $m) {
		foreach ([['a', 1, 9], ['d', 9, 1]] as [$ord, $og, $of]) {
			$modo($m, $og, $of); $h = $pagina(); [$s, $d, $tag] = $imgDe($h, 'F9R-FOTO propietario'); $real = html_entity_decode($s); [$sg, $dg] = $imgDe($h, 'F9B libro');
			r("MF-" . ($sub === '' ? '' : 'sub-') . "$m-$ord", "Gravatar «{$m}», " . ($ord === 'a' ? 'antes' : 'despues') . ' del plugin de fotos' . ($sub === '' ? '' : ', sitio en subcarpeta') . ': el img lleva EXACTAMENTE la URL del plugin (sin extension, con parametros), escapada (&amp;), sin data-engage-gravatar', preg_match($patron($sub), $real) === 1 && str_contains($s, '&amp;task=photo.show') && !preg_match('/&(?!amp;)/', $s) && $d === '' && !str_contains($tag, 'gravatar.com'), "src=$s $d");
			if ($m === 'ask' && $ord === 'a') { r('MF-ask-invitado', 'en «preguntar» los demas comentarios siguen igual (generico + referencia diferida)', str_contains($sg, 'avatar-') && $dg === 'diferida', "$sg $dg"); }
		}
	}
}
$crearPlugin(''); $setCom([]); $modo('ask');
$ctxLog = (string) @file_get_contents("$demoDir/contextos.log");
r('MF-contexto', 'el plugin recibe el contexto de la peticion como tercer argumento («page»)', str_contains($ctxLog, 'page') && !preg_match('/^(email|static)$/m', $ctxLog), trim(substr($ctxLog, 0, 80)));

// ---- 2) correos: la foto que depende del espectador NO sale
@unlink("$demoDir/contextos.log");
$mailDir = "$WORK/mails";
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mail_style' => 'both', 'disable_htmllayout' => '0'])) . "' WHERE element='com_mails'");
$leer = function (string $f): string { return (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f)) . "\n" . (string) file_get_contents($f); };
$pass = $PASS;
foreach ([['sin campo', ''], ['con campo', 'images/avatars/chaska.jpg']] as [$etq, $valor]) {
	if ($valor !== '') {
		q("DELETE FROM jos_fields WHERE name='r3foto-avatar'");
		q("INSERT INTO jos_fields (asset_id, context, group_id, title, name, label, default_value, type, note, description, state, required, only_use_in_subform, checked_out, ordering, params, fieldparams, language, created_time, created_user_id, modified_time, modified_by, access) VALUES (0,'com_users.user',0,'Foto de prueba','r3foto-avatar','Foto de prueba','','media','','',1,0,0,NULL,0,'{}','{\"directory\":\"\",\"preview\":\"tooltip\",\"image_class\":\"\",\"preview_width\":200,\"preview_height\":200}','*',NOW(),$uid,NOW(),$uid,1)");
		$fid = (int) $db->insert_id; q("INSERT INTO jos_fields_values (field_id,item_id,value) VALUES ($fid,'$uid','images/avatars/chaska.jpg')");
	}
	$setCom(['email_rich' => '0', 'email_confidential' => '0', 'default_publish' => '1', 'customfield_avatar' => $valor !== '' ? (string) $fid : '']);
	$antes = glob("$mailDir/*.eml") ?: [];
	$c = new Cliente($BASE, "$WORK/tmp", 'mfmail' . mt_rand()); $okl = $c->login('r3foto', $pass); $p = $c->get($PAGE); $tk = Cliente::token($p['body']);
	$c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode('/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => '0', 'body' => "R3B-F9R-FOTO correo $etq"]]);
	usleep(1800000);
	$todos = ''; $n = 0; foreach (array_diff(glob("$mailDir/*.eml") ?: [], $antes) as $f) { $todos .= $leer($f); $n++; }
	$imgs = preg_match_all('~<img[^>]*class="engageemail-comment-header-avatar"[^>]*>~', $todos, $mi) ? $mi[0] : [];
	$ok = $okl && $n >= 1 && !str_contains($todos, 'com_mifoto') && !str_contains($todos, 'photo.show');
	$ok = $ok && ($valor === '' ? count($imgs) >= 1 && !array_filter($imgs, fn($t) => !str_contains($t, 'src="http://127.0.0.1:8080/media/com_engage/images/avatar-generico.svg"')) : count($imgs) >= 1 && !array_filter($imgs, fn($t) => !str_contains($t, 'src="http://127.0.0.1:8080/images/avatars/chaska.jpg"')));
	r('MF-correo-' . ($valor === '' ? 'sin' : 'con'), "correo del comentario del propietario ($etq, diseno HTML sin envoltorio, {AVATAR_URL} en la plantilla): ni rastro del plugin de fotos; " . ($valor === '' ? 'avatar generico publico de Engage' : 'avatar = el campo, URL absoluta de la base fiable'), $ok, "correos=$n imgs=" . json_encode($imgs));
	$ctxLog = (string) @file_get_contents("$demoDir/contextos.log");
}
r('MF-correo-ctx', 'durante el envio del correo no se pregunto a los plugins de fotos por el autor (solo la pagina del propio comentario, contexto «page»)', !preg_match('/^(email|static)$/m', $ctxLog), trim($ctxLog));

// ---- 3) caches compartidas: cada espectador ve SU marca
$setCom(['customfield_avatar' => '', 'email_rich' => '1', 'default_publish' => '1']); $modo('ask');
$espectadores = ['invitado' => [null, 0], 'propietario' => ['r3foto', $uid], 'moderador' => ['r3mod', (int) one("SELECT id FROM jos_users WHERE username='r3mod'")], 'otro' => ['r3sub', (int) one("SELECT id FROM jos_users WHERE username='r3sub'")]];
$clientes = []; foreach ($espectadores as $n => [$u, $id]) { $c = new Cliente($BASE, "$WORK/tmp", "mfv$n"); if ($u !== null && !$c->login($u, $pass)) { r("MF-login-$n", "login $n", false); } $clientes[$n] = $c; }
$cfgs = ['conservadora (1) + cache de paginas + engagecache' => [1, 1, 1], 'conservadora (1) sin cache de paginas, con engagecache' => [1, 0, 1], 'progresiva (2) + cache de paginas + engagecache' => [2, 1, 1], 'progresiva (2) sin cache de paginas ni engagecache' => [2, 0, 0], 'conservadora (1) + cache de paginas SIN engagecache' => [1, 1, 0]];
foreach ($cfgs as $nombre => [$caching, $page, $eng]) {
	file_put_contents($cfgPath, str_replace('public $caching = 0;', 'public $caching = ' . $caching . ';', $cfgOrig));
	q("UPDATE jos_extensions SET enabled=$page, params='{\"browsercache\":\"0\",\"cachetime\":\"15\"}' WHERE element='cache' AND folder='system'"); q("UPDATE jos_extensions SET enabled=$eng WHERE element='engagecache' AND folder='system'");
	$limpiaCache(); usleep(4500000);
	$malos = []; $orden = ['invitado', 'propietario', 'invitado', 'moderador', 'otro', 'invitado', 'propietario', 'moderador', 'invitado', 'otro', 'propietario', 'invitado'];
	foreach ($orden as $i => $n) {
		$h = $clientes[$n]->get($PAGE)['body']; [$s] = $imgDe($h, 'F9R-FOTO propietario'); $real = html_entity_decode($s);
		$esp = $hex8($espectadores[$n][1]);
		if (!preg_match('~&v=([0-9a-f]{8})$~', $real, $mv) || $mv[1] !== $esp) { $malos[] = "#$i $n vio v=" . ($mv[1] ?? '?') . " esperaba $esp"; }
	}
	r('MF-cache-' . substr(md5($nombre), 0, 4), "$nombre: 12 peticiones de invitado, propietario, moderador y otro registrado, mezcladas: cada uno ve la marca de SU identidad, nunca la de otro", !$malos, implode(' | ', array_slice($malos, 0, 4)));
}
resumen('foto-mifoto');
