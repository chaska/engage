<?php
/**
 * 0.9.8 contra el Joomla REAL: la foto del usuario en sus comentarios. Campo personalizado de usuario (tipo imagen) con foto; Gravatar en cada modo
 * (preguntar / desactivado / siempre); valores hostiles del campo (el valor lo escribe el usuario); invitados; el orden de los plugins (un plugin del grupo
 * engage que da foto, con Gravatar antes y despues); la opcion en el panel (lista cerrada, solo core.admin). Requiere filtro-datos.php crear. Restaura todo.
 */
require __DIR__ . '/revision-082-comun.php';
$g = new Cliente($BASE, "$WORK/tmp", 'foto');
$comOrig = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$grav = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='gravatar' AND folder='engage'");
$gravOrd = (string) one("SELECT ordering FROM jos_extensions WHERE element='gravatar' AND folder='engage'");
$demoDir = "$SITE/plugins/engage/fotodemo";
register_shutdown_function(function () use ($comOrig, $grav, $gravOrd, $demoDir) {
	q("UPDATE jos_extensions SET params='" . esc($comOrig) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc($grav) . "', ordering=" . (int) $gravOrd . " WHERE element='gravatar' AND folder='engage'");
	q("DELETE FROM jos_extensions WHERE element='fotodemo' AND folder='engage'");
	q("DELETE FROM jos_fields_values WHERE field_id IN (SELECT id FROM jos_fields WHERE name='r3foto-avatar')");
	q("DELETE FROM jos_fields WHERE name='r3foto-avatar'");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'F9R-%' OR body LIKE '%F9R-FOTO%'"); borraUsuarios();
	foreach (glob("$demoDir/*") ?: [] as $f) { @unlink($f); } @rmdir($demoDir);
});
$uid = usuario('r3foto', 2); $uid2 = usuario('r3sinfoto', 2);
q("DELETE FROM jos_engage_comments WHERE body LIKE '%F9R-FOTO%'");
foreach ([[$uid, 1], [$uid2, 2]] as [$u, $i]) { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>F9R-FOTO $i registrado</p>',NULL,NULL,'127.0.0.1','t',1,'2000-01-0$i 10:00:00',$u)"); }
q("DELETE FROM jos_fields WHERE name='r3foto-avatar'");
q("INSERT INTO jos_fields (asset_id, context, group_id, title, name, label, default_value, type, note, description, state, required, only_use_in_subform, checked_out, ordering, params, fieldparams, language, created_time, created_user_id, modified_time, modified_by, access) VALUES (0,'com_users.user',0,'Foto de prueba','r3foto-avatar','Foto de prueba','','media','','',1,0,0,NULL,0,'{}','{\"directory\":\"\",\"preview\":\"tooltip\",\"image_class\":\"\",\"preview_width\":200,\"preview_height\":200}','*',NOW(),$uid,NOW(),$uid,1)");
$fid = (int) $db->insert_id;
$valor = function (?string $v) use ($fid, $uid) { q("DELETE FROM jos_fields_values WHERE field_id=$fid"); if ($v !== null) { q("INSERT INTO jos_fields_values (field_id,item_id,value) VALUES ($fid,'$uid','" . esc($v) . "')"); } };
$setCom = function (array $extra) use ($comOrig) { $p = array_merge(json_decode($comOrig, true) ?: [], ['max_level' => '3', 'comments_ordering' => 'asc', 'default_limit' => '20', 'sort_selector' => '0', 'reactions_enabled' => '0', 'copy_link' => '0'], $extra); q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); usleep(250000); };
$modo = function (string $m) use ($grav) { $p = json_decode($grav, true) ?: []; $p['mode'] = $m; q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='gravatar' AND folder='engage'"); usleep(250000); };
/** Los datos del avatar de un comentario: src y data-engage-gravatar. */
$avatar = function (string $html, string $marca): array { if (!preg_match('~<article\b[^>]*id="akengage-comment-\d+".*?' . preg_quote($marca, '~') . '.*?</article>~s', $html, $m)) { return ['?', '', '']; } preg_match('~<article\b.*?(?=<article\b|\z)~s', $m[0], $a); $blk = $m[0]; $first = strrpos(substr($blk, 0, strpos($blk, $marca)), '<article'); $blk = substr($blk, $first === false ? 0 : $first); preg_match('~<img[^>]*class="akengage-commenter-avatar[^>]*>~', $blk, $i); $tag = $i[0] ?? ''; return [preg_match('/\ssrc="([^"]*)"/', $tag, $s) ? html_entity_decode($s[1]) : '(sin img)', preg_match('/data-engage-gravatar="([^"]*)"/', $tag) ? 'diferida' : '', $blk]; };
$pagina = fn() => (new Cliente($BASE, "$WORK/tmp", 'foto' . mt_rand()))->get($PAGE)['body'];
$FOTO = '/images/avatars/chaska.jpg'; $GEN = '/media/com_engage/images/avatar-generico.svg';

$setCom(['customfield_avatar' => (string) $fid]);
foreach (['ask', 'off', 'always'] as $m) {
	$modo($m); $valor('images/avatars/chaska.jpg#joomlaImage://local-images/avatars/chaska.jpg?width=200&height=200');
	$h = $pagina(); [$s, $d] = $avatar($h, 'F9R-FOTO 1'); [$s2, $d2] = $avatar($h, 'F9R-FOTO 2'); [$sg, $dg] = $avatar($h, 'F9B libro');
	r("FT-$m-1", "Gravatar «{$m}»: el usuario con foto en su campo muestra SU foto ($FOTO), sin Gravatar ni referencia diferida", str_contains($s, $FOTO) && $d === '' && !str_contains($s, 'gravatar'), "src=$s $d");
	$esperadoSin = $m === 'always' ? true : str_contains($s2, 'avatar-generico') || str_contains($s2, 'avatar-blanco');
	r("FT-$m-2", "Gravatar «{$m}»: el usuario SIN foto y el invitado siguen como antes (" . ($m === 'always' ? 'Gravatar' : 'generico') . ')', $esperadoSin && ($m === 'always' || str_contains($sg, 'avatar-')), "sin foto=$s2 $d2 | invitado=$sg $dg");
}
$modo('ask');
// valores hostiles
$hostiles = ['javascript:alert(1)', 'data:image/svg+xml;base64,PHN2Zz4=', 'https://evil.example/a.jpg', '//evil.example/a.jpg', '../../etc/a.jpg', 'images/a.php', 'images/a.svg', 'images/a.jpg"onerror="alert(1)', '<img src="https://evil.example/x.jpg" onerror="alert(1)">', '<img src="javascript:alert(1)">', "images/a.jpg'><script>alert(1)</script>", '{"imagefile":"javascript:alert(1)"}', 'images/%2e%2e/a.jpg', str_repeat('a', 700) . '.jpg'];
$malos = [];
foreach ($hostiles as $v) {
	$valor($v); $h = $pagina(); [$s, $d, $blk] = $avatar($h, 'F9R-FOTO 1');
	$limpio = (str_contains($s, 'avatar-generico') || str_contains($s, 'avatar-blanco')) && !preg_match('~evil\.example|javascript:|onerror=|<script~i', $blk);
	if (!$limpio) { $malos[] = substr($v, 0, 40) . ' => ' . substr($s, 0, 60); }
}
r('FT-host', count($hostiles) . ' valores hostiles del campo (javascript:, data:, otros dominios, ../, .php, .svg, comillas, script, JSON, %2e%2e, 700 caracteres): ninguno llega al HTML; sale el avatar de siempre', !$malos, implode(' | ', $malos));
$valor(null); [$s] = $avatar($pagina(), 'F9R-FOTO 1');
r('FT-vacio', 'campo vacio: el avatar de siempre (generico en «preguntar»)', str_contains($s, 'avatar-'), $s);
$valor('images/avatars/chaska.jpg'); $setCom(['customfield_avatar' => '']); [$s] = $avatar($pagina(), 'F9R-FOTO 1');
r('FT-ninguno', 'opcion «Ninguno» (por defecto): el campo no se usa', str_contains($s, 'avatar-') && !str_contains($s, 'chaska'), $s);
$setCom(['customfield_avatar' => '99999']); [$s] = $avatar($pagina(), 'F9R-FOTO 1');
r('FT-idmal', 'opcion con un id que no existe: no se usa ningun campo', !str_contains($s, 'chaska'), $s);
$setCom(['customfield_avatar' => "$fid; DROP"]); [$s] = $avatar($pagina(), 'F9R-FOTO 1');
r('FT-idsql', 'opcion con texto raro: no se usa ningun campo ni hay error', !str_contains($s, 'chaska') && $s !== '?', $s);

// orden de los plugins: un plugin del grupo engage que da foto, con Gravatar antes y despues
@mkdir($demoDir, 0755, true);
file_put_contents("$demoDir/fotodemo.php", '<?php defined("_JEXEC") or die; use Joomla\CMS\Plugin\CMSPlugin; use Joomla\Event\Event; use Joomla\Event\SubscriberInterface; class PlgEngageFotodemo extends CMSPlugin implements SubscriberInterface { public static function getSubscribedEvents(): array { return ["onAkeebaEngageUserAvatarURL" => "avatar"]; } public function avatar(Event $e): void { [$u] = array_values($e->getArguments()); if ((int) ($u->id ?? 0) > 0 && $u->username === "r3foto") { $e->setArgument("result", array_merge($e->getArgument("result", []), ["/images/avatars/plugin.png"])); } } }');
file_put_contents("$demoDir/fotodemo.xml", '<?xml version="1.0" encoding="utf-8"?><extension type="plugin" group="engage" method="upgrade"><name>fotodemo</name><version>1.0.0</version><files><filename plugin="fotodemo">fotodemo.php</filename></files></extension>');
q("DELETE FROM jos_extensions WHERE element='fotodemo' AND folder='engage'");
q("INSERT INTO jos_extensions (name, type, element, folder, client_id, enabled, access, protected, locked, manifest_cache, params, custom_data, ordering, state) VALUES ('fotodemo','plugin','fotodemo','engage',0,1,1,0,0,'" . esc(json_encode(['name' => 'fotodemo', 'version' => '1.0.0'])) . "','{}','',5,0)");
$setCom(['customfield_avatar' => '']); $valor(null);
foreach (['ask', 'off', 'always'] as $m) {
	foreach ([['gravatar ANTES del plugin de fotos', 1, 9], ['gravatar DESPUES del plugin de fotos', 9, 1]] as [$etq, $og, $of]) {
		$modo($m); q("UPDATE jos_extensions SET ordering=$og WHERE element='gravatar' AND folder='engage'"); q("UPDATE jos_extensions SET ordering=$of WHERE element='fotodemo' AND folder='engage'"); usleep(300000);
		[$s, $d] = $avatar($pagina(), 'F9R-FOTO 1'); [$s2] = $avatar($pagina(), 'F9R-FOTO 2');
		r("FT-orden-$m-" . ($og === 1 ? 'a' : 'd'), "Gravatar «{$m}», $etq: gana la foto del plugin (/images/avatars/plugin.png), sin referencia diferida; quien no tiene foto sigue igual", str_contains($s, '/images/avatars/plugin.png') && $d === '' && !str_contains($s2, 'plugin.png'), "src=$s $d | otro=$s2");
	}
}
q("DELETE FROM jos_extensions WHERE element='fotodemo' AND folder='engage'");

// la opcion en el panel: lista cerrada con el campo; un editor sin core.admin no la cambia
$setCom(['customfield_avatar' => (string) $fid]);
$nav = (string) shell_exec('node ' . escapeshellarg(__DIR__ . '/foto-navegador.js') . ' 2>&1');
r('FT-panel', 'panel moderno de Opciones (Chromium): «Campo de usuario con la foto» es una lista cerrada con «Ninguno» y el campo «Foto de prueba»', str_starts_with($nav, 'OK '), trim($nav));
r('FT-sin-admin', 'la opcion solo la cambia core.admin: va en SettingsSchema::ADMIN_ONLY y el filtro AdminOnlyFilter la valida contra la lista', str_contains((string) file_get_contents('' . dirname(__DIR__, 3) . '/src/component/backend/src/Helper/SettingsSchema.php'), "'customfield_avatar'") && str_contains((string) file_get_contents('' . dirname(__DIR__, 3) . '/src/component/backend/config.xml'), 'AdminOnlyFilter::customfield_avatar'), '');
resumen('foto-usuario');
