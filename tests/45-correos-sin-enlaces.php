<?php
/**
 * 0.8.3: opcion «Incluir enlaces en los correos» (email_include_links, lista cerrada 1/0, por defecto 1).
 * Con 0 los correos no llevan ningun enlace de moderacion, ni al contenido ni al comentario; solo se mantiene el de baja (firmado y con la base fiable).
 *  A) la opcion: config.xml, panel moderno (SettingsLayout), textos en-GB y es-ES, validacion cerrada
 *  B) las plantillas por defecto (5 x en-GB y es-ES) con los enlaces vacios: sin `href=` ni «#» salvo el de baja; con todos los enlaces no cambian
 *  C) una plantilla PERSONALIZADA (comillas simples, botones, enlaces anidados en texto)
 *  D) el plugin: con 0 no se calcula ninguna URL firmada y el de baja sigue
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
eval('namespace Joomla\CMS\Mail { class MailTemplate { protected function replaceTags($t, $tags, $h = false) { return $t; } } }');
$root = dirname(__DIR__) . '/src';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/component/backend/src/Helper/EngageMailTemplate.php";

use Akeeba\Component\Engage\Administrator\Helper\EngageMailTemplate as T;

echo "A) La opcion\n";
$cfg = $lee("$root/component/backend/config.xml");
t_ok(preg_match('#<field name="email_include_links"\s+type="radio"[^>]*default="1"[^>]*layout="joomla.form.field.radio.switcher"[^>]*>\s*<option value="0">JNo</option>\s*<option value="1">JYes</option>#s', $cfg) === 1, 'config.xml: interruptor 0/1 con 1 por defecto');
t_ok(str_contains($lee("$root/component/backend/src/Helper/SettingsLayout.php"), "[\$c, 'email_include_links']"), 'aparece en el panel moderno, en el grupo de notificaciones');
foreach (['en-GB', 'es-ES'] as $l) {
	$ini = $lee("$root/component/backend/language/$l/com_engage.sys.ini");
	t_ok(preg_match('/^COM_ENGAGE_CONFIG_EMAIL_INCLUDE_LINKS_LABEL="[^"]+"$/m', $ini) === 1 && preg_match('/^COM_ENGAGE_CONFIG_EMAIL_INCLUDE_LINKS_DESC="[^"]+"$/m', $ini) === 1, "$l: etiqueta y ayuda");
	t_ok(preg_match('/EMAIL_INCLUDE_LINKS_DESC="[^"]*(unsubscribe|baja)[^"]*(live_site)/s', $ini) === 1, "$l: la ayuda explica la excepcion de la baja y el live_site");
}
t_ok(preg_match('/EMAIL_INCLUDE_LINKS_DESC="[^"]*\bdebe poder darse de baja\b/', $lee("$root/component/backend/language/es-ES/com_engage.sys.ini")) === 1 && !preg_match('/EMAIL_INCLUDE_LINKS_(LABEL|DESC)="[^"]*\b(puedes|tu |tus )/i', $lee("$root/component/backend/language/es-ES/com_engage.sys.ini")), 'es-ES: de «usted»');
$em = $lee("$root/plugins/engage/email/src/Extension/Email.php");
t_ok(str_contains($em, "!in_array(ComponentHelper::getParams('com_engage')->get('email_include_links', 1), [0, '0', false], true)"), 'validacion cerrada: solo 0, «0» o false apagan; cualquier otro valor = 1');
foreach ([0, '0', false] as $v) { t_ok(!(!in_array($v, [0, '0', false], true)), 'valor ' . var_export($v, true) . ' = sin enlaces'); }
foreach ([1, '1', true, null, '', 'no', 'false', '00', ' 0', 2, -1, [], 0.0, 'off'] as $v) { t_ok(!in_array($v, [0, '0', false], true), 'valor ' . json_encode($v) . ' = con enlaces (1)'); }

echo "B) Plantillas por defecto\n";
$tags = ['SITEURL', 'CONTENT_LINK', 'COMMENT_LINK', 'PUBLISH_URL', 'UNPUBLISH_URL', 'DELETE_URL', 'POSSIBLESPAM_URL', 'SPAM_URL', 'UNSPAM_URL', 'UNSUBSCRIBE_URL'];
$render = function (string $plantilla, array $valores): string { foreach ($valores as $k => $v) { $plantilla = str_replace('{' . $k . '}', $v, $plantilla); } return preg_replace('/\{[A-Z_]+\}/', 'dato', $plantilla); };
foreach (['en-GB', 'es-ES'] as $l) {
	$ini = $lee("$root/component/backend/language/$l/com_engage.ini");
	foreach (['MANAGE', 'SPAM', 'NOTIFY', 'NOTIFY_MANAGERS', 'NOTIFY_AUTHOR'] as $k) {
		preg_match('/^COM_ENGAGE_MAIL_' . $k . '_BODY_HTML="(.*)"$/m', $ini, $m); $html = str_replace(['\\"', '\\n'], ['"', "\n"], $m[1] ?? '');
		preg_match('/^COM_ENGAGE_MAIL_' . $k . '_BODY="(.*)"$/m', $ini, $mp); $txt = str_replace(['\\"', '\\n'], ['"', "\n"], $mp[1] ?? '');
		t_ok($html !== '' && $txt !== '', "$l $k: plantilla HTML y de texto leidas");
		// todos los enlaces vacios (marcados con # como los envia TemplateEmails) = ninguno queda
		$vacios = array_fill_keys($tags, '#');
		$h = T::stripDeadAnchors($render($html, $vacios));
		t_ok(!preg_match('/href\s*=/i', $h) && !str_contains($h, 'engageemail-button"') && !str_contains($h, 'href="#"'), "$l $k con 0 enlaces: el HTML no tiene ningun href ni boton (" . strlen($h) . ' bytes)');
		t_ok(preg_match('/\{[A-Z_]+\}/', $h) === 0 && str_contains($h, 'dato'), "$l $k: el resto del mensaje (quien, que, donde) sigue ahi");
		$p = T::stripDeadLines($render($txt, array_fill_keys($tags, '')));
		t_ok(!preg_match('/^.*(URL|link|enlace|unsubscribe|baja)[^\n]*:\s*$/im', $p) && !str_contains($p, 'http'), "$l $k: el texto plano no deja lineas «URL:» vacias");
		// solo la baja (con 0 en un correo a suscriptores)
		$solo = array_fill_keys($tags, '#'); $solo['UNSUBSCRIBE_URL'] = 'https://sitio.test/baja?token=abc';
		$h = T::stripDeadAnchors($render($html, $solo));
		$n = preg_match_all('/href\s*=\s*"([^"]*)"/i', $h, $mm);
		$esperado = str_contains($html, 'href="{UNSUBSCRIBE_URL}"') ? ['https://sitio.test/baja?token=abc'] : [];
		t_ok($mm[1] === $esperado, "$l $k solo con la baja: " . ($esperado ? 'queda unicamente el enlace de baja' : 'esta plantilla no tiene baja: ningun href'));
		// con todos los enlaces el resultado no cambia
		$todos = array_combine($tags, array_map(fn($t) => 'https://sitio.test/' . strtolower($t), $tags));
		$pleno = $render($html, $todos);
		t_ok(T::stripDeadAnchors($pleno) === $pleno, "$l $k con todos los enlaces: el HTML queda exactamente igual");
	}
}

echo "C) Plantilla personalizada\n";
$custom = "<p>Hola <a href='{CONTENT_LINK}' target='_blank'>{CONTENT_TITLE}</a>.</p>\n<table><tr><td><a class='engageemail-button engageemail-button-success' style='x' href='{PUBLISH_URL}'><b>Publicar</b></a></td><td><A HREF=\"{DELETE_URL}\" CLASS=\"mi-boton engageemail-button\">Borrar</A></td></tr></table>\n<a href=\"{COMMENT_LINK}\" class=\"otro\">ver</a> <a href=\"https://externo.test/x\">externo</a> <a href=\"{UNSUBSCRIBE_URL}\">baja</a>";
$h = T::stripDeadAnchors($render($custom, ['CONTENT_LINK' => '#', 'PUBLISH_URL' => '#', 'DELETE_URL' => '#', 'COMMENT_LINK' => '#', 'UNSUBSCRIBE_URL' => 'https://sitio.test/b']));
t_ok(!str_contains($h, 'Publicar') && !str_contains($h, 'Borrar') && str_contains($h, 'dato.</p>') && str_contains($h, 'ver ') && substr_count($h, 'href') === 2 && str_contains($h, 'https://externo.test/x') && str_contains($h, 'https://sitio.test/b'), 'botones (comillas simples o dobles, mayusculas) fuera; enlaces de texto quedan como texto; el enlace externo y la baja se conservan: ' . preg_replace('/\s+/', ' ', $h));
t_ok(T::stripDeadAnchors('<a href="">x</a><a href=\'\'>y</a><a href="#">z</a><a href=" # ">w</a>') === 'xyzw', 'href vacio y «#» (con o sin espacios, comillas simples o dobles) se tratan igual');
t_ok(T::stripDeadAnchors('<a href="#ancla">x</a><a href="/p#a">y</a>') === '<a href="#ancla">x</a><a href="/p#a">y</a>', 'un ancla interna real (#ancla) no se toca');
t_ok(T::stripDeadAnchors("<a\nhref=\"#\"\nclass=\"engageemail-button\">\nBoton\n</a>fin") === 'fin', 'atributos en varias lineas');
t_ok(T::stripDeadLines("Hola\nComment URL: \nTexto: importante\nIf you do not wish to receive these emails you can unsubscribe: \nAdios") === "Hola\nTexto: importante\nAdios", 'texto plano: solo caen las lineas de enlace vacias, no «Texto: importante»');
t_ok(T::stripDeadLines("URL del comentario:\nPuede darse de baja:   \nUser Agent: x") === "User Agent: x", 'texto plano en espanol (URL, baja)');

echo "D) El plugin de correo\n";
t_ok(preg_match('/\$includeLinks\s*=.*?\$publicUrl\s*=\s*\$includeLinks \? TrustedBase::absolute/s', $em) === 1, 'con 0, la URL del contenido ni se calcula');
t_ok(str_contains($em, "'SITEURL'           => \$includeLinks ? TrustedBase::root() : ''"), 'SITEURL vacio con 0');
t_ok(str_contains($em, '$canState    = $includeLinks && $this->recipientCan($recipient, \'core.edit.state\');') && str_contains($em, '$canDelete   = $includeLinks && $this->recipientCan($recipient, \'core.delete\');'), 'con 0 nadie es «moderador» a efectos de enlaces: no se llama a getAbsoluteSignedURL de moderacion (el ternario no la evalua)');
t_ok(preg_match("/\\\$unsubUrl\s*=\s*SignedURL::getAbsoluteSignedURL\(sprintf\(\\\$protoUrl, 'comments\.unsubscribe'.*?'UNSUBSCRIBE_URL'\s*=>\s*\\\$unsubUrl/s", $em) === 1, 'la baja se sigue firmando siempre (sin condicion), sobre la base fiable');
$te = $lee("$root/component/backend/src/Helper/TemplateEmails.php");
t_ok(str_contains($te, 'self::hasEmptyLinks($data) && get_class($templateMailer) === MailTemplate::class') && str_contains($te, 'new EngageMailTemplate('), 'TemplateEmails usa EngageMailTemplate cuando falta algun enlace');
t_ok(preg_match("/LINK_TAGS = \['SITEURL', 'CONTENT_LINK', 'COMMENT_LINK', 'PUBLISH_URL', 'UNPUBLISH_URL', 'DELETE_URL', 'POSSIBLESPAM_URL', 'SPAM_URL', 'UNSPAM_URL', 'UNSUBSCRIBE_URL'\]/", $te) === 1, 'las etiquetas de enlace son exactamente las de las plantillas (AVATAR_URL es una imagen y no cuenta)');
t_fin();
