<?php
/**
 * 0.9.0: correos HTML enriquecidos con aviso de confidencialidad (EmailWrapper, funciones puras).
 *  A) el HTML: sin script, sin src, sin url(), sin imagenes, solo href http(s)/mailto, todo escapado, bien formado, 600 px, tablas, modo oscuro, contraste
 *  B) texto plano coherente y aviso en es-ES (usted) y en-GB, con respaldo
 *  C) opciones (cerradas) y el codigo que las usa
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/component/backend/src/Helper/EmailWrapper.php";

use Akeeba\Component\Engage\Administrator\Helper\EmailWrapper as W;

$ini = function (string $lang): array { $a = parse_ini_file(dirname(__DIR__) . "/src/component/backend/language/$lang/com_engage.ini", false, INI_SCANNER_RAW); return array_map(fn($v) => str_replace('\"', '"', trim((string) $v, '"')), $a); };
$tpls = fn(array $i, string $k): string => str_replace(['\n'], ["\n"], (string) ($i[$k] ?? ''));
$tr   = fn(array $i) => fn(string $k, ...$a): string => $a ? vsprintf((string) ($i[$k] ?? $k), $a) : (string) ($i[$k] ?? $k);
$data = ['{SITEURL}' => 'https://www.example.com/', '{SITENAME}' => 'Mi sitio', '{RECIPIENT_NAME}' => 'Ana <script>alert(2)</script>', '{CONTENT_LINK}' => 'https://www.example.com/a', '{CONTENT_TITLE}' => 'Titulo', '{COMMENT_LINK}' => 'https://www.example.com/c',
	'{UNPUBLISH_URL}' => 'https://www.example.com/u?a=1&amp;b=2', '{DELETE_URL}' => '#', '{POSSIBLESPAM_URL}' => '', '{SPAM_URL}' => 'javascript:alert(1)', '{PUBLISH_URL}' => 'https://www.example.com/p', '{UNSPAM_URL}' => 'https://www.example.com/n',
	'{AVATAR_URL}' => 'https://gravatar.example/x.png', '{NAME}' => 'Pepe', '{EMAIL}' => 'p@e.c', '{IP}' => '1.2.3.4', '{DATE_LOCAL}' => 'hoy', '{USER_AGENT}' => 'UA',
	'{COMMENT_SANITIZED}' => '<p>Hola <img src="https://t.example/p.gif" width="1" height="1"> <a href="http://x.example/" onclick="x()">enlace</a> <a href="javascript:alert(1)">mal</a> <a href="data:text/html,x">d</a> <span style="background:url(http://t.example/x.gif)">u</span> <script>alert(1)</script> <style>@import url(http://t.example/c.css)</style><iframe src="http://t.example"></iframe><form action="http://t.example"><input name=a></form></p>',
	'{UNSUBSCRIBE_URL}' => 'https://www.example.com/unsub?a=1&amp;b=2'];
$ctxBase = fn(array $i, string $key, bool $sub, array $o = []) => $o + ['rich' => true, 'confidential' => true, 'accent' => 'blue', 'lang' => 'en-GB', 'key' => $key, 'sitename' => 'Mi <b>sitio</b> & "co"', 'siteurl' => 'https://www.example.com/', 'contact' => 'info@example.com', 'unsubscribe' => 'https://www.example.com/unsub?a=1&b=2', 'subscriber' => $sub, 'tr' => $tr($i)];

echo "A) El HTML\n";
$en = $ini('en-GB'); $es = $ini('es-ES');
$tabla = ['com_engage.manage' => 'MANAGE', 'com_engage.spam' => 'SPAM', 'com_engage.notify' => 'NOTIFY', 'com_engage.notify_managers' => 'NOTIFY_MANAGERS', 'com_engage.notify_author' => 'NOTIFY_AUTHOR'];

/** Comprobacion de etiquetas equilibradas (sin DOM: no perdona nada). */
$balanceado = function (string $h): bool {
	$void = ['meta', 'br', 'hr', 'img', 'input', 'link', 'col'];
	$pila = [];
	$h    = preg_replace('/<!DOCTYPE[^>]*>/i', '', $h);
	if (!preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $h, $m, PREG_SET_ORDER)) { return false; }
	foreach ($m as [$all, $close, $name]) {
		$name = strtolower($name);
		if (in_array($name, $void, true) || str_ends_with($all, '/>')) { continue; }
		if ($close === '') { $pila[] = $name; continue; }
		if (array_pop($pila) !== $name) { return false; }
	}
	return $pila === [];
};
foreach ($tabla as $key => $K) {
	foreach (['en-GB' => $en, 'es-ES' => $es] as $lang => $i) {
		$tpl = strtr($tpls($i, "COM_ENGAGE_MAIL_{$K}_BODY_HTML"), $data);
		$sub = in_array($key, ['com_engage.notify', 'com_engage.notify_author'], true);
		[$b, $alt] = W::compose($ctxBase($i, $key, $sub, ['lang' => $lang]), $tpl, "texto\n", true);
		$n = "$key $lang";
		t_ok(!preg_match('/<script|<style[^>]*>\s*@import|<iframe|<form|<input|<object|<embed|<link\b|<base\b/i', preg_replace('#<style type="text/css">.*?</style>#s', '', $b)), "$n: sin script, iframe, formularios ni recursos (solo el <style> propio)");
		t_ok(!preg_match('/\bsrc\s*=|<img|url\s*\(|@import|expression\s*\(|javascript:|\bon[a-z]+\s*=|data:text/i', $b), "$n: sin src=, imagenes, url(), @import, javascript:, manejadores de eventos");
		preg_match_all('/\bhref="([^"]*)"/i', $b, $hm);
		$malos = array_filter($hm[1], fn($h) => !preg_match('~^(?:https?://|mailto:)~i', html_entity_decode($h)));
		t_ok(!$malos, "$n: todos los href son http(s) o mailto" . ($malos ? ': ' . implode(',', $malos) : ''));
		t_ok(!str_contains($b, 't.example') && !str_contains($b, 'gravatar.example'), "$n: nada de los recursos externos de la plantilla ni del comentario (pixel, avatar)");
		t_ok($balanceado($b), "$n: HTML bien formado (etiquetas equilibradas)");
		t_ok(str_contains($b, 'width="600"') && str_contains($b, 'max-width:100%') && str_contains($b, 'role="presentation"') && substr_count($b, '<table') >= 2, "$n: maquetacion con tablas de 600 px como maximo");
		t_ok(str_contains($b, '<meta name="color-scheme" content="light dark">') && str_contains($b, '<meta name="supported-color-schemes" content="light dark">') && str_contains($b, '@media (prefers-color-scheme: dark)') && str_contains($b, '@media only screen and (max-width:620px)'), "$n: modo oscuro y adaptable a movil");
		t_ok(str_contains($b, 'Mi &lt;b&gt;sitio&lt;/b&gt; &amp; &quot;co&quot;') && !str_contains($b, 'Mi <b>sitio</b>'), "$n: el nombre del sitio va escapado");
		t_ok(!str_contains($b, 'alert(2)') && !str_contains($b, 'alert(1)'), "$n: datos dinamicos con HTML activo limpiados");
		t_ok(str_contains($b, 'class="eg-card') && str_contains($b, 'class="eg-conf"'), "$n: tarjeta y aviso presentes");
		t_ok(!str_contains($b, 'Possible Spam</a>') && !str_contains($b, 'Spam and Delete</a>') && !str_contains($b, '>Delete</a>') , "$n: los botones sin direccion valida (#, vacio, javascript:) desaparecen");
		t_ok(str_contains($alt, 'texto') && str_contains($alt, 'Mi <b>sitio</b>') , "$n: la parte de texto plano conserva el original y anade el pie");
	}
}

echo "B) Aviso de confidencialidad y texto plano\n";
foreach (['en-GB' => $en, 'es-ES' => $es] as $lang => $i) {
	$tx = W::texts($tr($i), 'Mi sitio', 'info@example.com', 'https://www.example.com/unsub?a=1&b=2', true, false);
	[$b, $alt] = W::compose($ctxBase($i, 'com_engage.notify_author', true, ['lang' => $lang, 'sitename' => 'Mi sitio']), '<p>Hola</p>', "Hola\n", true);
	$txtHtml = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<style.*?</style>#s', '', $b)))), ENT_QUOTES, 'UTF-8');
	$norm = fn($s) => trim(preg_replace('/\s+/', ' ', $s));
	foreach (['conf_title', 'conf_body', 'conf_privacy'] as $k) {
		t_ok(str_contains($txtHtml, $norm($tx[$k])) && str_contains($norm($alt), $norm($tx[$k])), "$lang: «{$k}» esta igual en el HTML y en el texto plano");
	}
	t_ok(str_contains($tx['conf_privacy'], 'info@example.com') && str_contains($tx['conf_privacy'], 'Mi sitio'), "$lang: nombre del sitio y correo de contacto en el texto de proteccion de datos");
	t_ok(str_contains($txtHtml, $norm($tx['unsub_text'])) && str_contains($b, 'href="https://www.example.com/unsub?a=1&amp;b=2"') && str_contains($alt, 'https://www.example.com/unsub?a=1&b=2'), "$lang: suscriptor sin enlace de baja en la plantilla: texto y enlace firmado de baja en HTML y en texto plano");
	$sinC = W::texts($tr($i), 'Mi sitio', '', '', false, false);
	t_ok(!str_contains($sinC['conf_privacy'], '@') && $sinC['unsub_text'] === '', "$lang: sin correo de contacto no se inventa; moderadores sin frase de baja");
	$sinLink = W::texts($tr($i), 'Mi sitio', '', '', true, false);
	t_ok($sinLink['unsub_text'] !== '' && $sinLink['unsub_url'] === '', "$lang: suscriptor sin base fiable: frase de baja sin enlace");
}
$e2 = $es['COM_ENGAGE_MAILWRAP_CONF_BODY'] . ' ' . $es['COM_ENGAGE_MAILWRAP_CONF_PRIVACY'] . ' ' . $es['COM_ENGAGE_MAILWRAP_UNSUB'];
t_ok(preg_match('/\busted\b/i', $e2) === 1 && preg_match('/confidencial/', $e2) && preg_match('/exclusivamente a la persona destinataria/', $e2) && preg_match('/por error/', $e2) && preg_match('/RGPD/', $e2) && preg_match('/seguridad ni la integridad/', $e2) && preg_match('/gestionar los comentarios y las notificaciones/', $e2), 'es-ES: aviso completo y en tono de «usted»');
$e3 = $en['COM_ENGAGE_MAILWRAP_CONF_BODY'] . ' ' . $en['COM_ENGAGE_MAILWRAP_CONF_PRIVACY'];
t_ok(preg_match('/confidential/', $e3) && preg_match('/exclusively for the person/', $e3) && preg_match('/in error/', $e3) && preg_match('/GDPR/', $e3) && preg_match('/security and integrity/', $e3), 'en-GB: aviso completo');
$claves = array_filter(array_keys($es), fn($k) => str_starts_with($k, 'COM_ENGAGE_MAILWRAP_') || str_starts_with($k, 'COM_ENGAGE_MAILPREVIEW_'));
$faltan = array_diff($claves, array_keys($en)) + array_diff(array_filter(array_keys($en), fn($k) => str_starts_with($k, 'COM_ENGAGE_MAILWRAP_') || str_starts_with($k, 'COM_ENGAGE_MAILPREVIEW_')), array_keys($es));
t_ok(count($claves) >= 25 && !$faltan, 'es-ES y en-GB tienen las mismas cadenas MAILWRAP/MAILPREVIEW (' . count($claves) . ')');
foreach (['MANAGE', 'SPAM', 'NOTIFY', 'NOTIFY_MANAGERS', 'NOTIFY_AUTHOR', 'TEST'] as $K) { t_ok(isset($es["COM_ENGAGE_MAILWRAP_HEAD_$K"], $en["COM_ENGAGE_MAILWRAP_HEAD_$K"]), "titulo de cabecera $K en los dos idiomas"); }
// falta de traduccion: el traductor cae a la clave... el cargador de Joomla (TemplateEmails::translator) carga en-GB primero; aqui se comprueba el codigo
t_ok(str_contains($lee("$root/component/backend/src/Helper/TemplateEmails.php"), "createLanguage(\$tag, false)") && str_contains($lee("$root/component/backend/src/Helper/TemplateEmails.php"), "->load('com_engage', JPATH_ADMINISTRATOR, \$tag, true, true)"), 'el traductor carga en-GB como respaldo ($default = true) y los overrides del idioma');

echo "C) Acento, contraste y opciones\n";
$lum = function (string $hex): float { $c = array_map(fn($x) => hexdec($x) / 255, str_split(ltrim($hex, '#'), 2)); $c = array_map(fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c); return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; };
$cr  = fn(string $a, string $b): float => (max($lum($a), $lum($b)) + 0.05) / (min($lum($a), $lum($b)) + 0.05);
foreach (W::ACCENTS as $n => $h) { t_ok($cr($h, '#ffffff') >= 4.5, "acento $n ($h): contraste con el texto blanco " . round($cr($h, '#ffffff'), 2)); }
t_ok($cr('#111827', '#ffffff') >= 7 && $cr('#475569', '#f1f5f9') >= 4.5 && $cr('#475569', '#e2e8f0') >= 4.5 && $cr('#475569', '#f8fafc') >= 4.5, 'claro: texto y texto secundario con contraste suficiente');
t_ok($cr('#e5e7eb', '#1e293b') >= 7 && $cr('#cbd5e1', '#0b1220') >= 7 && $cr('#cbd5e1', '#111827') >= 7 && $cr('#93c5fd', '#1e293b') >= 4.5, 'oscuro: texto, texto secundario y enlaces con contraste suficiente');
t_ok(W::accent('blue') === '#1d4ed8' && W::accent('red;background:url(x)') === '#1d4ed8' && W::accent(null) === '#1d4ed8' && W::accent(['x']) === '#1d4ed8' && W::accent('"><script>') === '#1d4ed8' && W::accent('violet') === '#6d28d9', 'acento: lista cerrada; cualquier otra cosa = azul');
[$b] = W::compose($ctxBase($en, 'com_engage.notify', false, ['accent' => 'x";}</style><script>alert(1)</script>']), '<p>x</p>', '', true);
t_ok(!str_contains($b, 'alert(1)') && str_contains($b, '#1d4ed8'), 'un acento hostil no llega al CSS');
[$b, $alt] = W::compose($ctxBase($en, 'com_engage.notify', true, ['rich' => false, 'confidential' => false]), '<p>x</p>', "x\n", true);
t_ok($b === '<p>x</p>' && $alt === "x\n", 'sin diseño ni aviso: el mensaje sale tal cual');
[$b, $alt] = W::compose($ctxBase($en, 'com_engage.notify', true, ['rich' => false]), '<p>x</p>', "x\n", true);
t_ok(str_starts_with($b, '<p>x</p>') && str_contains($b, 'Confidentiality notice') && !str_contains($b, '<table') && str_contains($alt, 'Confidentiality notice'), 'solo aviso (sin diseño enriquecido): se añade al final del HTML y del texto');
[$b, $alt] = W::compose($ctxBase($en, 'com_engage.notify', true, ['confidential' => false]), '<p>x</p>', "x\n", true);
t_ok(str_contains($b, 'eg-card') && !str_contains($b, 'Confidentiality notice') && !str_contains($alt, 'Confidentiality notice'), 'solo diseño (sin aviso)');
[$b, $alt] = W::compose($ctxBase($en, 'com_engage.notify', true), "Hello\nplain", '', false);
t_ok(str_contains($b, 'Hello') && str_contains($b, 'Confidentiality notice') && $alt === '', 'correo solo de texto plano: el aviso va al final del cuerpo');
[$b] = W::compose($ctxBase($en, 'com_engage.notify', false, ['siteurl' => '']), '<p>x</p>', '', true);
t_ok(!preg_match('~<a [^>]*href="https?://[^"]*example\.com/"[^>]*>Mi~', $b), 'sin base fiable la cabecera no enlaza al sitio');

$cfg = $lee("$root/component/backend/config.xml");
t_ok(preg_match('/<field name="email_rich"\s+type="radio"[^>]*default="1"[^>]*switcher/s', $cfg) === 1 && preg_match('/<field name="email_confidential"[^>]*?type="radio"[^>]*default="1"[^>]*switcher/s', $cfg) === 1 && preg_match('/<field name="email_accent"\s+type="list"[^>]*default="blue"[^>]*validate="options"/s', $cfg) === 1, 'config.xml: email_rich, email_confidential y email_accent (lista cerrada)');
t_ok(preg_match('/(<option value="[a-z]+">COM_ENGAGE_EMAIL_ACCENT_[A-Z]+<\/option>\s*){6}/', $cfg) === 1, 'el acento ofrece justo los 6 tonos del panel');
$lay = $lee("$root/component/backend/src/Helper/SettingsLayout.php");
t_ok(str_contains($lay, "[\$c, 'email_rich'], [\$c, 'email_confidential'], [\$c, 'email_accent']"), 'panel moderno: grupo Notificaciones');
$pl = $lee("$root/plugins/engage/email/src/Extension/Email.php");
t_ok(str_contains($pl, "!in_array(ComponentHelper::getParams('com_engage')->get('email_rich', 1), [0, '0', false], true)") && str_contains($pl, "!in_array(ComponentHelper::getParams('com_engage')->get('email_confidential', 1), [0, '0', false], true)") && str_contains($pl, "'subscriber'   => in_array(\$type, ['com_engage.notify', 'com_engage.notify_author'], true)"), 'plugin Email: validacion cerrada de las opciones; suscriptores = notify y notify_author');
$te = $lee("$root/component/backend/src/Helper/TemplateEmails.php");
t_ok(str_contains($te, 'EngageMailer::from($mailer, $wrap)') && !str_contains($te, 'UPDATE #__mail_templates') , 'sendMail decora el mensaje al enviarlo, no reescribe plantillas');
$mm = $lee("$root/component/backend/src/Helper/EngageMailer.php");
t_ok(str_contains($mm, 'catch (Throwable $e)') && str_contains($mm, 'parent::send()'), 'si la decoracion falla el mensaje sale como estaba');
t_ok(!preg_match('/https?:\/\/(?!www\.w3\.org)[a-z0-9.\-]+\/[^\s\'"]*\.(png|gif|jpg|svg|woff2?|css)/i', $lee("$root/component/backend/src/Helper/EmailWrapper.php")), 'el envoltorio no referencia ninguna imagen, fuente ni hoja de estilo externa');
t_fin();
