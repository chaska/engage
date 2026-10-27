<?php
/**
 * 0.9.6 contra el Joomla REAL con el SMTP de captura: TODOS los correos de Engage salen en HTML + texto (multipart/alternative) con cualquier «Formato de correo» de
 * Joomla (solo texto —el valor por defecto—, HTML o ambos), en es-ES y en-GB: MIME, botones con su etiqueta y SIN direcciones a la vista en el HTML, direcciones en el texto
 * una por linea con su etiqueta, aviso de confidencialidad en HTML y texto, comentario sin etiquetas crudas ni dobles escapes, «Incluir enlaces» en No (sin botones de ver
 * y de moderar, baja se mantiene) y la configuracion de Joomla intacta al acabar. Restaura todo al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
$S = "$SITE";
usleep(5000000); // el script anterior (h11) acaba de restaurar configuration.php: el opcache del servidor de pruebas tarda unos segundos en verlo
$mailDir = "$WORK/mails";
$AP = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
@mkdir("$WORK/tmp");
$com0   = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
$mails0 = one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_mails'");
$adminId = (int) one("SELECT id FROM jos_users WHERE username='admintest'");
$adminParams0 = (string) one("SELECT IFNULL(params,'{}') FROM jos_users WHERE id=$adminId");
$tplSum0 = one("SELECT MD5(GROUP_CONCAT(CONCAT(template_id,language,subject,body,htmlbody) ORDER BY template_id, language)) FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%'");
$creados = [];
foreach (['language' => 'site', 'administrator/language' => 'administrator'] as $d => $cl) {
	if (!is_dir("$S/$d/es-ES")) {
		mkdir("$S/$d/es-ES", 0755, true);
		$meta = str_replace(['client="site"', 'English (en-GB)', 'English (United Kingdom)', '<tag>en-GB</tag>'], ["client=\"$cl\"", 'Spanish (es-ES)', 'Español (España)', '<tag>es-ES</tag>'], (string) file_get_contents("$S/language/en-GB/langmetadata.xml"));
		file_put_contents("$S/$d/es-ES/langmetadata.xml", preg_replace('/en_GB[^<]*/', 'es_ES.utf8, es_ES.UTF-8, es_ES, es', $meta));
		$creados[] = "$S/$d/es-ES";
	}
	copy(dirname(__DIR__, 3) . '/src/component/backend/language/es-ES/com_engage.ini', "$S/$d/es-ES/com_engage.ini");
	$creados[] = "$S/$d/es-ES/com_engage.ini";
}
register_shutdown_function(function () use ($com0, $mails0, $adminId, $adminParams0, $creados) {
	q("UPDATE jos_extensions SET params='" . esc((string) $com0) . "' WHERE element='com_engage' AND type='component'");
	q("UPDATE jos_extensions SET params='" . esc((string) $mails0) . "' WHERE element='com_mails'");
	q("UPDATE jos_users SET params='" . esc($adminParams0) . "' WHERE id=$adminId");
	q("DELETE FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%' AND subject LIKE 'R9-%'");
	q("DELETE FROM jos_engage_comments WHERE name LIKE 'R3N-%'"); borraUsuarios();
	foreach (array_reverse($creados) as $p) { if (is_file($p)) { unlink($p); } }
	foreach (array_reverse($creados) as $p) { if (is_dir($p)) { @array_map('unlink', glob("$p/*") ?: []); @rmdir($p); } }
});
usuario('r3sub', 2);
usuario('r3mod', 7);
$setCom = function (array $extra) { $p = json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'"), true) ?: []; $p = array_merge(['max_level' => '3', 'comments_ordering' => 'asc', 'theme' => 'classic', 'default_limit' => '100', 'reactions_enabled' => '0', 'sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0', 'email_include_links' => '1', 'email_rich' => '1', 'email_confidential' => '1', 'email_accent' => 'blue'], $p, $extra); q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); usleep(300000); };
$setLang = function (string $lang) use ($adminId) { $p = ['language' => $lang, 'admin_language' => $lang, 'administrator_language' => $lang]; q("UPDATE jos_users SET params='" . esc(json_encode($p)) . "' WHERE id=$adminId OR username IN ('r3sub','r3mod')"); };
$leer = function (string $f): array { $t = (string) shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/leer-correo.py') . ' ' . escapeshellarg($f)); $raw = (string) file_get_contents($f); preg_match('/^To:\s*(.+)$/mi', $raw, $m);
	preg_match('/@@@PARTE text\/plain@@@\n(.*?)(?=@@@PARTE|\z)/s', $t, $a); preg_match('/@@@PARTE text\/html@@@\n(.*?)(?=@@@PARTE|\z)/s', $t, $b); return ['para' => $m[1] ?? '', 'txt' => $a[1] ?? '', 'html' => $b[1] ?? '', 'raw' => $raw]; };
$enviar = function (string $tag, ?int $padre = null, string $email = '') use ($BASE, $WORK, $PAGE, $ASSET_PUB, $mailDir, $leer) {
	$antes = glob("$mailDir/*.eml") ?: [];
	$c = new Cliente($BASE, "$WORK/tmp", 'r9' . $tag); $p = $c->get($PAGE); $tk = Cliente::token($p['body']);
	$c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode('/'), $tk => 1, 'jform' => ['asset_id' => $ASSET_PUB, 'parent_id' => (string) ($padre ?? 0), 'name' => "R3N-H12-$tag", 'email' => $email ?: "h12$tag@example.invalid", 'body' => "R3B-F-$tag Viajeros & curiosos!!!\nSegunda linea del comentario"]]);
	usleep(1500000);
	$out = []; foreach (array_diff(glob("$mailDir/*.eml") ?: [], $antes) as $f) { $out[] = $leer($f); }
	if (getenv('DEPURA')) { fwrite(STDERR, "[$tag] correos=" . count($out) . ' para=' . implode('|', array_map(fn($x) => $x['para'], $out)) . "\n"); }
	return $out;
};

// ---- configuracion que se toca: plugin de correo (avisar a los moderadores) y formato de correo de Joomla; se restauran al acabar
$plug0 = (string) one("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='email' AND folder='engage'");
register_shutdown_function(function () use ($plug0) { q("UPDATE jos_extensions SET params='" . esc($plug0) . "' WHERE element='email' AND folder='engage'"); });
$pp = json_decode($plug0, true) ?: []; $pp['managers_notify'] = '1';
q("UPDATE jos_extensions SET params='" . esc(json_encode($pp)) . "' WHERE element='email' AND folder='engage'");
$fraseC = ['en-GB' => 'confidential and intended exclusively for the person', 'es-ES' => 'confidenciales y van dirigidos exclusivamente a la persona destinataria'];
$etiq = ['en-GB' => ['ver' => 'View comments', 'baja' => 'Unsubscribe', 'mod' => ['Publish', 'Delete'], 'modP' => ['Unpublish', 'Delete']], 'es-ES' => ['ver' => 'Ver comentarios', 'baja' => 'Darse de baja', 'mod' => ['Publicar', 'Eliminar'], 'modP' => ['Despublicar', 'Eliminar']]];
$visible = fn(string $h) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<style.*?</style>#s', '', $h)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$botones = function (string $h): array { preg_match_all('#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#s', $h, $m); $o = []; foreach ($m[2] as $i => $t) { $o[] = [trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8')), html_entity_decode($m[1][$i])]; } return $o; };
$lineasUrl = function (string $t): array { $mal = []; foreach (explode("\n", $t) as $l) { if (preg_match('~https?://~', $l) && !preg_match('~^(?:[^\n]{0,80}?: ?)?https?://\S+$~', rtrim($l))) { $mal[] = substr($l, 0, 90); } } return $mal; };

$n = 0;
foreach (['en-GB', 'es-ES'] as $lang) {
	$setLang($lang);
	foreach (['plaintext' => null, 'html' => 'html', 'both' => 'both'] as $nombre => $estilo) {
		$mp = $estilo === null ? [] : ['mail_style' => $estilo];   // «solo texto»: el valor por defecto de Joomla (no hay nada guardado)
		q("UPDATE jos_extensions SET params='" . esc(json_encode((object) $mp)) . "' WHERE element='com_mails'");
		$setCom(['default_publish' => '0', 'email_rich' => '1', 'email_confidential' => '1', 'email_include_links' => '1']);
		$man = array_values(array_filter($enviar("$lang$nombre" . 'm'), fn($x) => stripos($x['para'], 'admin@example.test') !== false))[0] ?? null;
		$setCom(['default_publish' => '1']);
		$uid = (int) one("SELECT id FROM jos_users WHERE username='r3sub'");
		q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B padre del suscriptor</p>',NULL,NULL,'203.0.113.8','t',1,'2026-09-02 10:00:00',$uid)");
		$padre = (int) $db->insert_id;
		$todos = $enviar("$lang$nombre" . 'r', $padre);
		q("DELETE FROM jos_engage_comments WHERE id=$padre");
		$por = fn(string $quien) => array_values(array_filter($todos, fn($x) => stripos($x['para'], $quien) !== false))[0] ?? null;
		$mails = ['manage (moderador)' => [$man, 'mod'], 'notify (suscriptor)' => [$por('r3sub@example.invalid'), 'sub'], 'notify_author (autor)' => [$por('admin@example.test'), 'sub'], 'notify_managers (moderador)' => [$por('r3mod'), 'modP']];
		foreach ($mails as $quien => [$m, $tipo]) {
			$id = sprintf('F-%02d', ++$n); $etq = "Joomla «{$nombre}» $lang $quien";
			if ($m === null) { r($id, "$etq: llega el correo", false); continue; }
			$h = $m['html']; $t = $m['txt']; $raw = $m['raw']; $vis = $visible($h); $bt = $botones($h); $L = $etiq[$lang];
			$fallos = [];
			if (!preg_match('/^Content-Type:\s*multipart\/alternative/mi', $raw) || $h === '' || $t === '') { $fallos[] = 'no es multipart/alternative con texto y HTML'; }
			if (!str_contains($h, 'eg-card') || !str_contains($h, 'prefers-color-scheme: dark')) { $fallos[] = 'sin el diseno enriquecido'; }
			if (preg_match('~https?://~i', $vis)) { $fallos[] = 'direccion a la vista en el HTML: ' . (preg_match('~.{0,30}https?://\S{0,40}~', $vis, $mv) ? $mv[0] : ''); }
			$textos = array_column($bt, 0);
			if (!in_array($L['ver'], $textos, true)) { $fallos[] = 'sin boton «' . $L['ver'] . '» (' . implode('/', $textos) . ')'; }
			foreach ($L[$tipo === 'sub' ? 'mod' : $tipo] as $b) { if ($tipo !== 'sub' && !in_array($b, $textos, true)) { $fallos[] = "sin boton «{$b}»"; } }
			if ($tipo === 'sub' && !in_array($L['baja'], $textos, true)) { $fallos[] = 'sin boton «' . $L['baja'] . '»'; }
			if ($tipo === 'sub' && count(array_filter($bt, fn($b) => str_contains($b[1], 'comments.unsubscribe'))) !== 1) { $fallos[] = 'la baja no sale exactamente una vez'; }
			foreach ($bt as [$tx, $href]) { if (preg_match('~^https?://~i', $tx)) { $fallos[] = 'boton con la direccion como texto'; } if (str_contains($href, 'amp;') || str_contains($href, '<')) { $fallos[] = 'href con doble escape'; } }
			if (!str_contains($vis, 'Viajeros & curiosos!!!') || !str_contains($vis, 'Segunda linea del comentario') || str_contains($h, '&lt;p&gt;')) { $fallos[] = 'comentario en el HTML mal'; }
			if (preg_match('~</?p>|&amp;|&lt;|&gt;|<br~i', $t)) { $fallos[] = 'etiquetas o entidades crudas en el texto'; }
			if (!str_contains($t, 'Viajeros & curiosos!!!') || !str_contains($t, 'Segunda linea del comentario')) { $fallos[] = 'comentario en el texto mal'; }
			if (!preg_match('~Viajeros & curiosos!!!\s*\n+\s*Segunda linea~', $t)) { $fallos[] = 'parrafos del comentario no conservados en el texto'; }
			if ($mal = $lineasUrl($t)) { $fallos[] = 'URL del texto que no va en su linea con etiqueta: ' . $mal[0]; }
			if (!str_contains($visible($h), $fraseC[$lang]) || !str_contains(trim(preg_replace('/\s+/', ' ', $t)), $fraseC[$lang])) { $fallos[] = 'sin aviso de confidencialidad en HTML y texto'; }
			if ($lang === 'es-ES' && (preg_match('/\busted\b/i', $vis) !== 1)) { $fallos[] = 'sin «usted»'; }
			if (substr_count($t, 'https://') + substr_count($t, 'http://') < ($tipo === 'sub' ? 2 : 4)) { $fallos[] = 'faltan direcciones en el texto'; }
			if ($tipo !== 'sub' && !str_contains($t, 'task=comments.')) { $fallos[] = 'sin direcciones de moderacion en el texto'; }
			if ($tipo === 'sub' && !str_contains($t, 'task=comments.unsubscribe')) { $fallos[] = 'sin la baja en el texto'; }
			r($id, "$etq: multipart/alternative, diseno, botones con etiqueta sin direcciones a la vista, direcciones en el texto una por linea con etiqueta, aviso en HTML y texto, comentario limpio", !$fallos, implode(' | ', $fallos) . ' botones=' . implode(',', $textos));
			if ($nombre === 'plaintext' && $tipo === 'sub' && str_contains($quien, 'notify (')) { @mkdir("$WORK/correos-formato"); file_put_contents("$WORK/correos-formato/$lang-suscriptor.html", $h); file_put_contents("$WORK/correos-formato/$lang-suscriptor.txt", $t); }
			if ($nombre === 'plaintext' && $tipo === 'mod') { @mkdir("$WORK/correos-formato"); file_put_contents("$WORK/correos-formato/$lang-moderador.html", $h); file_put_contents("$WORK/correos-formato/$lang-moderador.txt", $t); }
		}
	}
}

// ---- «Incluir enlaces en los correos» = No: ni «Ver comentarios» ni botones de moderacion; la baja de los suscriptores se mantiene
$setLang('en-GB'); q("UPDATE jos_extensions SET params='{}' WHERE element='com_mails'");
$setCom(['default_publish' => '0', 'email_include_links' => '0']);
$man = array_values(array_filter($enviar('sinenlm'), fn($x) => stripos($x['para'], 'admin@example.test') !== false))[0] ?? null;
$setCom(['default_publish' => '1']);
$uid = (int) one("SELECT id FROM jos_users WHERE username='r3sub'");
q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>R3B padre</p>',NULL,NULL,'203.0.113.8','t',1,'2026-09-02 10:00:00',$uid)"); $padre = (int) $db->insert_id;
$sub = array_values(array_filter($enviar('sinenlr', $padre), fn($x) => stripos($x['para'], 'r3sub@example.invalid') !== false))[0] ?? null;
q("DELETE FROM jos_engage_comments WHERE id=$padre");
$bm = $man ? array_column($botones($man['html']), 0) : ['?']; $bs = $sub ? array_column($botones($sub['html']), 0) : ['?'];
r('F-90', 'enlaces = No, moderador: ni «View comments» ni botones de moderacion, ni direcciones de moderacion en el texto; aviso presente', $man && !array_intersect($bm, ['View comments', 'Publish', 'Delete', 'Possible Spam']) && !str_contains($man['txt'], 'task=comments.') && str_contains($visible($man['html']), $fraseC['en-GB']), implode(',', $bm));
r('F-91', 'enlaces = No, suscriptor: sin «View comments»; el boton «Unsubscribe» y la baja del texto se mantienen', $sub && !in_array('View comments', $bs, true) && in_array('Unsubscribe', $bs, true) && str_contains($sub['txt'], 'task=comments.unsubscribe') && !preg_match('~</?p>~', $sub['txt']), implode(',', $bs));
// ---- la configuracion de Joomla queda como estaba (el formato se fuerza solo en memoria)
q("UPDATE jos_extensions SET params='" . esc(json_encode(['mail_style' => 'plaintext', 'foo' => 'bar'])) . "' WHERE element='com_mails'");
$enviar('fin');
r('F-92', 'la configuracion guardada de com_mails no cambia tras enviar', json_decode((string) one("SELECT params FROM jos_extensions WHERE element='com_mails'"), true) === ['mail_style' => 'plaintext', 'foo' => 'bar']);
resumen('correos-formato');
