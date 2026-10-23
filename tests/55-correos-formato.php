<?php
/**
 * 0.9.6: los correos de Engage SIEMPRE en HTML + texto (multipart/alternative) con cualquier «Formato de correo» de Joomla, botones con etiqueta sin direcciones a la vista,
 * direcciones en el texto una por linea con etiqueta, comentario como texto limpio, aviso en todos los correos. Funciones puras y codigo.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/component/backend/src/Helper/EmailWrapper.php";

use Akeeba\Component\Engage\Administrator\Helper\EmailWrapper as W;

$ini = function (string $lang) use ($root): array { $a = parse_ini_file("$root/component/backend/language/$lang/com_engage.ini", false, INI_SCANNER_RAW); return array_map(fn($v) => str_replace(['\n', '\"'], ["\n", '"'], trim((string) $v, '"')), $a); };
$tr  = fn(array $i) => fn(string $k, ...$a): string => $a ? vsprintf((string) ($i[$k] ?? $k), $a) : (string) ($i[$k] ?? $k);

echo "A) Comentario como texto limpio\n";
$tt = W::htmlToText('<p>Hueveros &amp; viajeros!!!</p><p>Segunda <b>l</b>inea<br>tres <a href="https://x.es/a?b=1&amp;c=2">enlace</a></p><ul><li>uno</li><li>dos</li></ul>');
t_ok($tt === "Hueveros & viajeros!!!\n\nSegunda linea\ntres enlace (https://x.es/a?b=1&c=2)\n\n- uno\n- dos", 'parrafos, saltos, lista y enlace; sin etiquetas; entidades decodificadas una vez (' . json_encode($tt) . ')');
t_ok(W::htmlToText('<p>a &amp;amp; b</p>') === 'a &amp; b' && W::htmlToText('<p>&lt;script&gt;x&lt;/script&gt;</p>') === '<script>x</script>', 'un comentario que dice «&amp;» o «<script>» lo sigue diciendo en el texto (decodifica UNA vez, sin doble decodificado)');
t_ok(!preg_match('~<[a-z/]|&amp;|&nbsp;~i', W::htmlToText('<div><p>a&nbsp;b</p><script>alert(1)</script><style>p{}</style></div>')) && W::htmlToText('<div><p>a&nbsp;b</p></div>') === 'a b', 'sin etiquetas, sin script ni style, espacio duro como espacio');
t_ok(W::htmlToText('') === '' && W::htmlToText('texto') === 'texto', 'casos limite');

echo "B) Acciones del texto: cada direccion en su linea con su etiqueta\n";
$es = $ini('es-ES'); $en = $ini('en-GB');
$acts = [['COM_ENGAGE_MAILWRAP_ACT_VIEW', 'https://s.es/a?x=1&amp;akengage_cid=2#c'], ['COM_ENGAGE_MAILWRAP_ACT_PUBLISH', 'https://s.es/p?t=1&amp;u=2'], ['COM_ENGAGE_MAILWRAP_ACT_DELETE', ''], ['COM_ENGAGE_MAILWRAP_ACT_SPAM', 'javascript:alert(1)'], ['NO_ES_UNA_CLAVE', 'https://s.es/x'], ['COM_ENGAGE_MAILWRAP_ACT_UNSPAM', "https://s.es/x y"]];
$pa = W::plainActions($acts, $tr($es), 'https://s.es/baja?e=1&t=2', true);
t_ok($pa === [['Ver comentarios', 'https://s.es/a?x=1&akengage_cid=2#c'], ['Publicar', 'https://s.es/p?t=1&u=2'], ['Darse de baja', 'https://s.es/baja?e=1&t=2']], 'etiquetas traducidas, &amp; deshecho, vacias/javascript:/claves ajenas/espacios descartadas y la baja del suscriptor (' . json_encode($pa) . ')');
t_ok(W::plainActions($acts, $tr($en), '', false) === [['View comments', 'https://s.es/a?x=1&akengage_cid=2#c'], ['Publish', 'https://s.es/p?t=1&u=2']], 'en-GB; sin suscriptor ni baja no se anade');
$ctx = fn(array $i, array $o = []) => $o + ['rich' => true, 'confidential' => true, 'accent' => 'blue', 'lang' => 'es-ES', 'key' => 'com_engage.manage', 'sitename' => 'Mi sitio', 'siteurl' => 'https://s.es/', 'contact' => 'a@b.es', 'unsubscribe' => 'https://s.es/baja', 'subscriber' => false, 'tr' => $tr($i), 'actions' => $acts];
[$h, $alt] = W::compose($ctx($es), '<p>Hola</p><a href="https://s.es/a">Ver</a>', "Hola\nURL: https://s.es/a?x=1&akengage_cid=2#c", true);
t_ok(str_contains($alt, "Publicar:\nhttps://s.es/p?t=1&u=2\n") && substr_count($alt, 'https://s.es/a?x=1&akengage_cid=2#c') === 1 && !str_contains($alt, 'Ver comentarios:'), 'texto: «Publicar:» + direccion en su linea; la que la plantilla ya traia no se repite');
t_ok(str_contains($alt, 'confidenciales y van dirigidos') && str_contains($h, 'confidenciales y van dirigidos') && !str_contains(preg_replace('#<(a|link)\b[^>]*>#', '', strip_tags(preg_replace('#<style.*?</style>#s', '', $h))), 'https://'), 'aviso en HTML y texto; el HTML no muestra ninguna direccion');
[$h2, $alt2] = W::compose($ctx($es, ['subscriber' => true]), '<p>Hola</p>', 'Hola', true);
t_ok(preg_match('~<a href="https://s\.es/baja"[^>]*>Darse de baja</a>~', $h2) === 1 && str_contains($alt2, "Darse de baja:\nhttps://s.es/baja") && substr_count($alt2, 'https://s.es/baja') === 1, 'suscriptor: boton «Darse de baja» en el HTML (texto, sin la direccion a la vista) y la direccion UNA vez en el texto');
[$h3, $alt3] = W::compose($ctx($es, ['subscriber' => false]), '<p>Hola</p>', 'Hola', true);
t_ok(!str_contains($h3, 'Darse de baja</a>') && !str_contains($alt3, 'baja'), 'moderador: sin boton ni linea de baja');
[$b4, $a4] = W::compose($ctx($en, ['lang' => 'en-GB', 'subscriber' => true]), 'Hello', '', false);
t_ok(str_contains($b4, "Publish:\nhttps://s.es/p?t=1&u=2") && str_contains($b4, 'confidential and intended exclusively') && $a4 === '', 'mensaje solo de texto (Joomla «plano»): las acciones y el aviso van igualmente al texto');

echo "C) El codigo: formato forzado, plantillas, idiomas\n";
$te = $lee("$root/component/backend/src/Helper/TemplateEmails.php");
t_ok(str_contains($te, "'mail_style' => 'both', 'disable_htmllayout' => '0', 'alternative_mailconfig' => 0") && str_contains($te, "ComponentHelper::getParams('com_mails')") && str_contains($te, 'finally') && str_contains($te, '$mailsParams->remove($k)'), 'sendMail fuerza mail_style=both solo en memoria y lo restaura (finally); no guarda nada');
t_ok(str_contains($te, "\$data['COMMENT_SANITIZED'] = \$data['COMMENT_PLAINTEXT']"), 'el texto plano usa el comentario limpio tambien en {COMMENT_SANITIZED}');
$pl = $lee("$root/plugins/engage/email/src/Extension/Email.php");
t_ok(str_contains($pl, 'EmailWrapper::htmlToText($processedComment)') && !str_contains($pl, 'new Html2Text(') && str_contains($pl, '$wrap[\'actions\']'), 'el plugin pasa el comentario por htmlToText y las acciones al texto');
t_ok(str_contains($lee("$root/component/backend/src/Helper/EngageMailer.php"), "error_log('Engage: the email was sent without the HTML design"), 'si el diseno falla, el correo sale igual y el motivo queda en el registro');
preg_match("~private const ACTIONS = \[(.*?)\n\t\];~s", $pl, $mm); preg_match_all("~'(com_engage\.[a-z_]+)'\s*=>~", $mm[1] ?? '', $km); preg_match_all("~'(COM_ENGAGE_MAILWRAP_ACT_[A-Z]+)'~", $mm[1] ?? '', $kl);
t_ok(array_values(array_unique($km[1])) === ['com_engage.manage', 'com_engage.spam', 'com_engage.notify_managers', 'com_engage.notify', 'com_engage.notify_author'], 'las cinco plantillas de correo tienen sus acciones');
foreach (['es-ES' => $es, 'en-GB' => $en] as $lg => $i) {
	foreach (array_unique(array_merge($kl[1], ['COM_ENGAGE_MAILWRAP_UNSUB_BUTTON', 'COM_ENGAGE_MAILWRAP_ACT_UNSUB'])) as $k) { t_ok(($i[$k] ?? '') !== '', "$lg: $k"); }
	foreach (['MANAGE', 'SPAM', 'NOTIFY', 'NOTIFY_MANAGERS', 'NOTIFY_AUTHOR'] as $p) {
		$h = $i["COM_ENGAGE_MAIL_{$p}_BODY_HTML"] ?? ''; $b = $i["COM_ENGAGE_MAIL_{$p}_BODY"] ?? '';
		t_ok(str_contains($h, "\n" . ($lg === 'es-ES' ? 'Ver comentarios' : 'View comments') . "\n") && !str_contains($h, "\nView Comment\n") && !str_contains($h, "\nVer comentario\n"), "$lg $p: el boton se llama «" . ($lg === 'es-ES' ? 'Ver comentarios' : 'View comments') . "»");
		if (str_contains($b, '{UNSUBSCRIBE_URL}')) { t_ok(str_contains($b, ":\n{UNSUBSCRIBE_URL}"), "$lg $p: la baja del texto va en su linea"); }
		t_ok(!preg_match('~>\s*\{(COMMENT_LINK|PUBLISH_URL|DELETE_URL|UNSUBSCRIBE_URL|SPAM_URL|UNSPAM_URL|UNPUBLISH_URL|POSSIBLESPAM_URL)\}\s*<~', $h), "$lg $p: ninguna direccion como texto visible del HTML");
	}
}
echo "D) Aviso en cada plantilla (por defecto, personalizada, antigua, vacia)\n";
foreach (['MANAGE', 'SPAM', 'NOTIFY', 'NOTIFY_MANAGERS', 'NOTIFY_AUTHOR'] as $p) {
	foreach (['es-ES' => ['confidenciales y van dirigidos', $es], 'en-GB' => ['confidential and intended exclusively', $en]] as $lg => [$frase, $i]) {
		foreach (['por defecto' => [$i["COM_ENGAGE_MAIL_{$p}_BODY_HTML"], $i["COM_ENGAGE_MAIL_{$p}_BODY"]], 'personalizada' => ['<p>Hola <b>{X}</b></p>', 'Hola'], 'antigua' => ['<div>Texto</div>', ''], 'vacia' => ['', '']] as $tipo => [$bh, $bt]) {
			[$ho, $to] = W::compose($ctx($i, ['lang' => $lg, 'key' => 'com_engage.' . strtolower($p), 'subscriber' => in_array($p, ['NOTIFY', 'NOTIFY_AUTHOR'], true)]), $bh, $bt, true);
			$vis = preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<style.*?</style>#s', '', $ho)));
			t_ok(str_contains($vis, $frase) && ($bt === '' || str_contains(preg_replace('/\s+/', ' ', $to), $frase)), "$p $lg $tipo: aviso en el HTML" . ($bt === '' ? '' : ' y en el texto'));
		}
	}
}
t_fin();
