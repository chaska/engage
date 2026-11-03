<?php
/**
 * README con capturas: datos INVENTADOS para el Joomla de pruebas (sitio «Sitio de pruebas», personas ficticias, correos example.test, sin IP ni navegador).
 * Requiere 02 + lib/seed.php ya ejecutados (03 los ejecuta). Uso: php capturas-readme-datos.php crear | borrar. Solo toca el sitio de pruebas, nunca el repositorio.
 */
require __DIR__ . '/revision-082-comun.php';
$AS = (int) $ids['art_publico_asset']; $AS2 = (int) $ids['art_otro_asset'];
$cfg = "$SITE/configuration.php";
if (($argv[1] ?? '') === 'borrar') {
	q("DELETE FROM jos_engage_reports"); q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments");
	q("DELETE FROM jos_modules_menu WHERE moduleid IN (SELECT id FROM jos_modules WHERE module='mod_engage_latest')"); q("DELETE FROM jos_modules WHERE module='mod_engage_latest'");
	echo "ok borrar\n"; exit;
}
// Sitio, categoria, articulos y personas ficticias
@chmod($cfg, 0644); $c = file_get_contents($cfg); $c = preg_replace("/public \\\$sitename = '[^']*';/", "public \$sitename = 'Sitio de pruebas';", $c); file_put_contents($cfg, $c); @chmod($cfg, 0444);
q("UPDATE jos_categories SET title='Jardinería', alias='jardineria' WHERE id=" . (int) $ids['cat_publica']);
$txt = fn(string $a, string $b) => esc("<p>$a</p><p>$b</p>");
q("UPDATE jos_content SET title='Cómo montar un huerto urbano en casa', alias='huerto-urbano', introtext='" . $txt('Un huerto en el balcón no necesita mucho espacio: basta con unas cajas, buena tierra y algo de sol.', 'En esta guía vemos qué plantar en cada estación y cada cuánto hay que regar.') . "' WHERE id=" . (int) $ids['art_publico']);
q("UPDATE jos_content SET title='Guía rápida de compostaje', alias='compostaje', introtext='" . $txt('El compost convierte los restos de cocina en abono gratis para sus macetas.', 'Aprenda qué se puede echar, qué no y cuánto tarda en estar listo.') . "' WHERE id=" . (int) $ids['art_otro']);
$p = [125 => ['msoler', 'Marta Soler'], 126 => ['jortega', 'Javier Ortega'], 127 => ['lprieto', 'Lucía Prieto']];
foreach ($p as $id => [$u, $n]) { q("UPDATE jos_users SET username='$u', name='" . esc($n) . "', email='$u@example.test' WHERE id=$id"); }
q("UPDATE jos_users SET name='Administración' WHERE id=124");
// Comentarios (sin IP ni navegador)
q("DELETE FROM jos_engage_reports"); q("DELETE FROM jos_engage_reactions"); q("DELETE FROM jos_engage_comments");
$n = 0;
$add = function (int $asset, ?int $parent, string $name, string $mail, string $body, int $enabled, int $horas, int $uid = 0) use (&$n) {
	q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset," . ($parent ?? 'NULL') . ",'" . esc("<p>$body</p>") . "','" . esc($name) . "','" . esc($mail) . "','','',$enabled,DATE_SUB(NOW(), INTERVAL $horas HOUR),$uid)");
	return (int) $GLOBALS['db']->insert_id;
};
$a = $add($AS, null, 'Marta Soler', 'msoler@example.test', 'Muy buen artículo. Probé las cajas de madera y las tomateras van estupendamente. Gracias.', 1, 50, 125);
$b = $add($AS, $a, 'Javier Ortega', 'jortega@example.test', '¿Qué madera usó? A mí me preocupa que se pudra con el riego.', 1, 40, 126);
$d = $add($AS, $b, 'Marta Soler', 'msoler@example.test', 'Pino tratado para exterior. Después de un año sigue perfecto.', 1, 30, 125);
$e = $add($AS, null, 'Lucía Prieto', 'lprieto@example.test', 'Excelente explicación, clara y directa. La recomendaré en el club de jardinería.', 1, 20, 127);
$f = $add($AS, null, 'Carmen Vidal', 'carmen@example.test', 'Pendiente de aprobación: ¿se puede plantar fresas en el mismo huerto?', 0, 5);
$g = $add($AS, null, 'Oferta imperdible', 'oferta@example.test', 'Compre relojes baratos pulsando aquí', -3, 3);
$h = $add($AS, null, 'Nuria Camps', 'nuria@example.test', 'Me encanta el nuevo diseño de los comentarios, se lee muy bien en el móvil.', 1, 2);
$i = $add($AS2, null, 'Ricardo Gil', 'ricardo@example.test', 'Llevo un año con la compostera y ya no compro abono. Muy recomendable.', 1, 10);
$j = $add($AS2, null, 'Elena Cano', 'elena@example.test', '¿Se pueden echar cáscaras de naranja? Me dijeron que no.', 1, 6);
foreach ([[$a, 126, 1], [$a, 127, 1], [$e, 125, 1], [$e, 126, 1], [$d, 126, 1], [$b, 127, 3], [$a, 125, 3], [$h, 125, 2]] as [$cm, $us, $ty]) { q("INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES ($cm,$us,$ty,NOW())"); }
q("INSERT INTO jos_engage_reports (comment_id,user_id,reason,note,created,status) VALUES ($h,126,4,'Parece publicidad encubierta',DATE_SUB(NOW(), INTERVAL 1 HOUR),0), ($j,125,2,'',DATE_SUB(NOW(), INTERVAL 2 HOUR),0), ($g,127,1,'',DATE_SUB(NOW(), INTERVAL 3 HOUR),2)");
// Modulo «Ultimos comentarios»
q("DELETE FROM jos_modules_menu WHERE moduleid IN (SELECT id FROM jos_modules WHERE module='mod_engage_latest')"); q("DELETE FROM jos_modules WHERE module='mod_engage_latest'");
q("INSERT INTO jos_modules (title,note,content,ordering,position,checked_out,publish_up,publish_down,published,module,access,showtitle,params,client_id,language) VALUES ('Últimos comentarios','','',1,'sidebar-right',NULL,NULL,NULL,1,'mod_engage_latest',1,1,'{\"mode\":\"latest\",\"count\":\"5\",\"show_title\":\"1\",\"excerpt\":\"1\"}',0,'*')");
q("INSERT INTO jos_modules_menu (moduleid,menuid) VALUES (" . (int) $db->insert_id . ",0)");
// Opciones y plugins para las capturas (valores razonables, sin claves reales)
q("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND ((folder='engage' AND element IN ('email','akismet','gravatar')) OR (folder IN ('actionlog','user','privacy','datacompliance') AND element='engage') OR (folder='system' AND element='engagecache'))");
q("UPDATE jos_extensions SET params=JSON_SET(IFNULL(NULLIF(params,''),'{}'),'$.mode','ask') WHERE type='plugin' AND folder='engage' AND element='gravatar'");
q("UPDATE jos_extensions SET params=JSON_SET(IFNULL(NULLIF(params,''),'{}'),'$.privacy_url','/politica-de-privacidad','$.default_publish','0','$.tos_accept','1') WHERE type='component' AND element='com_engage'");
$rl = json_decode((string) one("SELECT rules FROM jos_assets WHERE name='com_engage'"), true) ?: []; $rl['core.create'] = ['1' => 1]; $rl['core.edit.own'] = ['2' => 1]; $rl['core.edit.state'] = ['6' => 1]; $rl['core.manage'] = ['6' => 1]; $rl['core.delete'] = ['6' => 1];
q("UPDATE jos_assets SET rules='" . esc(json_encode($rl, JSON_FORCE_OBJECT)) . "' WHERE name='com_engage'");
q("UPDATE jos_guidedtours SET autostart=0");
q("UPDATE jos_extensions SET params=JSON_SET(IFNULL(NULLIF(params,''),'{}'),'$.mode','0') WHERE type='plugin' AND element='stats'");
q("UPDATE jos_extensions SET params='{\"mode\":3,\"lastrun\":" . time() . "}' WHERE type='plugin' AND element='stats' AND folder='system'");
echo "ok crear\n";
