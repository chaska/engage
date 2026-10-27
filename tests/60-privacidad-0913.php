<?php
/**
 * 0.9.13: retencion de IP y navegador, informacion de privacidad en el formulario, enlace de IP vacio por defecto, Gravatar decidido por el servidor,
 * adopcion de comentarios con correo verificado. Estatica; lo que necesita Joomla y base de datos esta en tests/joomla-live/lib/privacidad-0913.php.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$c/backend/src/Helper/Privacy.php";
use Akeeba\Component\Engage\Administrator\Helper\Privacy as P;

echo "A) Retencion: meses y fecha de corte\n";
foreach ([['12', 12], [12, 12], ['1', 1], ['6', 6], [' 3 ', 3], ['0', 12], [0, 12], ['13', 12], [13, 12], ['-1', 12], ['999', 12], ['abc', 12], ['', 12], [null, 12], [[], 12], ['6.5', 12], ['1e1', 12], [true, 12], ['０６', 12], ["6\n", 6]] as [$in, $exp]) {
	t_ok(P::months($in) === $exp, 'months(' . json_encode($in) . ') = ' . $exp);
}
$now = strtotime('2026-10-27 12:00:00 UTC');
t_ok(P::cutoff(12, $now) === '2025-10-27 12:00:00' && P::cutoff(1, $now) === '2026-09-27 12:00:00' && P::cutoff(99, $now) === '2025-10-27 12:00:00', 'fecha de corte (UTC): 12 meses, 1 mes y un valor invalido = 12');
t_ok(P::MAX_MONTHS === 12 && P::DEFAULT_MONTHS === 12, 'maximo y defecto 12: no hay «para siempre»');

echo "B) Enlace a la politica de privacidad\n";
foreach (['' => '', 'https://www.example.com/?option=com_content&view=article&id=45' => 'https://www.example.com/?option=com_content&view=article&id=45', '/privacidad' => '/privacidad', '/index.php?option=com_content&view=article&id=45' => '/index.php?option=com_content&view=article&id=45',
	'//evil.com/x' => '', 'http://example.com/p' => '', 'javascript:alert(1)' => '', 'data:text/html,x' => '', 'https://user:pw@example.com/' => '', 'https://exa mple.com/' => '', '/a b' => '', '/x"onclick=1' => '', "/x\ny" => '', '/\\evil.com' => '', 'privacidad' => '', 'https://' => '', 'https://example.com/<script>' => '',
	'ftp://example.com' => '', 'HTTPS://example.com/p' => '', 'https://-bad.com/' => '', 'https://example.com:99999x/' => ''] as $in => $exp) {
	t_ok(P::policyUrl($in) === $exp, 'policyUrl ' . json_encode($in) . ' -> ' . json_encode($exp));
}
t_ok(P::policyUrl(['x']) === '' && P::policyUrl(null) === '' && P::policyUrl('https://example.com/' . str_repeat('a', 600)) === '', 'no cadenas y longitud excesiva: vacio');

echo "C) Opciones: config.xml, esquema, idiomas, plantilla\n";
$cfg = $lee("$c/backend/config.xml");
t_ok(preg_match('/name="iplookup".*?default=""/s', $cfg) === 1 && !str_contains($cfg, 'whatismyipaddress'), 'iplookup: valor por defecto vacio en instalaciones nuevas (sin enlace)');
t_ok(preg_match('/name="retention_months"[^>]*?AdminOnlyFilter::retention_months[^>]*?validate="adminonly"[^>]*?type="integer"\s+first="1"\s+last="12"\s+step="1"\s+default="12"/s', $cfg) === 1, 'retention_months: entero 1-12, defecto 12, solo administracion');
t_ok(preg_match('/name="privacy_url"[^>]*?AdminOnlyFilter::privacy_url[^>]*?validate="adminonly"[^>]*?type="text"\s+default=""/s', $cfg) === 1, 'privacy_url: texto vacio por defecto, solo administracion');
require_once "$c/backend/src/Helper/SettingsSchema.php";
use Akeeba\Component\Engage\Administrator\Helper\SettingsSchema as S;
t_ok(S::requiresAdmin('com_engage', 'retention_months') && S::requiresAdmin('com_engage', 'privacy_url'), 'ambas opciones exigen core.admin');
$def = S::parse($cfg, 'com_engage')['fields'];
foreach (['6', '1', '12'] as $v) { t_ok(S::validate($def['retention_months'], $v) === $v, "retention_months $v valido"); }
foreach (['0', '13', '-1', 'abc', '', '6.5', '99999', " 6;DROP"] as $v) { $ex = false; try { S::validate($def['retention_months'], $v); } catch (\InvalidArgumentException $e) { $ex = true; } t_ok($ex, 'retention_months ' . json_encode($v) . ' rechazado'); }
foreach (['', '/privacidad', 'https://www.example.com/?option=com_content&view=article&id=45'] as $v) { t_ok(S::validate($def['privacy_url'], $v) === $v, 'privacy_url ' . json_encode($v) . ' valido'); }
foreach (['//evil.com', 'http://a.com', 'javascript:alert(1)', '/a b', 'https://a.com/"x', "/x\ny", 'privacidad'] as $v) { $ex = false; try { S::validate($def['privacy_url'], $v); } catch (\InvalidArgumentException $e) { $ex = true; } t_ok($ex, 'privacy_url ' . json_encode($v) . ' rechazado'); }
foreach (['es-ES', 'en-GB'] as $l) {
	$sys = parse_ini_string($lee("$c/backend/language/$l/com_engage.sys.ini"), false, INI_SCANNER_RAW);
	$fr  = parse_ini_string($lee("$c/frontend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW);
	$bk  = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW);
	t_ok(isset($sys['COM_ENGAGE_CONFIG_RETENTION_MONTHS_LABEL'], $sys['COM_ENGAGE_CONFIG_RETENTION_MONTHS_DESC'], $sys['COM_ENGAGE_CONFIG_PRIVACY_URL_LABEL'], $sys['COM_ENGAGE_CONFIG_PRIVACY_URL_DESC']), "$l: cadenas de las opciones");
	t_ok(isset($fr['COM_ENGAGE_PRIVACY_NOTICE_TITLE'], $fr['COM_ENGAGE_PRIVACY_NOTICE_TEXT'], $fr['COM_ENGAGE_PRIVACY_NOTICE_MONTHS'], $fr['COM_ENGAGE_PRIVACY_NOTICE_MONTHS_1'], $fr['COM_ENGAGE_PRIVACY_NOTICE_LINK']) && substr_count($fr['COM_ENGAGE_PRIVACY_NOTICE_TEXT'], '%s') === 1, "$l: texto del aviso (nombre, correo, texto, IP y navegador; para que; plazo; avisos por correo)");
	t_ok(isset($bk['COM_ENGAGE_PANEL_H_RETENTION_TITLE'], $bk['COM_ENGAGE_PANEL_H_RETENTION_OK']), "$l: cadenas del semaforo de retencion");
}
$fr = parse_ini_string($lee("$c/frontend/language/es-ES/com_engage.ini"), false, INI_SCANNER_RAW);
$tx = $fr['COM_ENGAGE_PRIVACY_NOTICE_TEXT'];
t_ok(str_contains($tx, 'nombre') && str_contains($tx, 'correo') && str_contains($tx, 'IP') && str_contains($tx, 'navegador') && str_contains($tx, 'spam') && str_contains($tx, 'moderar') && str_contains($tx, 'darse de baja'), 'es-ES: dice que se guarda, para que, el plazo y los avisos con baja');
$form = $lee("$c/frontend/tmpl/comments/default_form.php");
t_ok(str_contains($form, 'role="note"') && str_contains($form, 'aria-labelledby="akengage-privacy-info-title"') && str_contains($form, 'Privacy::policyUrl(') && str_contains($form, 'Privacy::months(') && str_contains($form, 'rel="noopener noreferrer nofollow"'), 'plantilla: bloque informativo accesible, enlace validado, sin recursos externos');
t_ok(!preg_match('/accept_tos|tos_accept/', substr($form, strpos($form, 'akengage-privacy-info'), 1500)), 'el bloque no depende de la casilla de aceptacion');
t_ok(str_contains($lee("$c/backend/src/Helper/SettingsLayout.php"), "[\$c, 'retention_months'], [\$c, 'privacy_url']"), 'el panel moderno incluye las dos opciones (Privacidad)');
$cp = $lee($root . '/src/plugins/content/engage/src/Extension/Engage.php');
t_ok(str_contains($cp, 'Privacy::purge(') && str_contains($cp, "Privacy::months(\$cParams->get('retention_months', 12))"), 'la purga cuelga del mismo mecanismo periodico (cada 8 horas) y usa el plazo validado');
$pv = $lee("$c/backend/src/Helper/Privacy.php");
t_ok(str_contains($pv, "->bind(':cutoff'") && str_contains($pv, 'whereIn(') && str_contains($pv, '$db->setQuery($sel, 0, $batch)') && str_contains($pv, "user_agent') . \" = ''\""), 'purga: consultas ligadas, por lotes, vacia ip y navegador sin borrar el comentario');
t_ok(str_contains($lee("$c/backend/src/Helper/PanelHealth.php"), 'self::retention($f)') && str_contains($lee("$c/backend/src/Helper/PanelData.php"), "\$facts['retention_months']"), 'el panel muestra el plazo vigente');

echo "D) Gravatar: decide el servidor\n";
$ac = $lee("$c/frontend/src/Controller/AvatarController.php");
t_ok(str_contains($ac, "['token', 'resolve', 'consent']") && str_contains($ac, "'error' => 'login'") && str_contains($ac, "'error' => 'consent'") && str_contains($ac, 'self::hasConsent($uid)'), 'avatar.resolve exige sesion y consentimiento guardado; avatar.consent nuevo');
$res = substr($ac, strpos($ac, 'public function resolve'), 2600);
t_ok(strpos($res, "'error' => 'login'") < strpos($res, 'new AvatarResolver') && strpos($res, "'error' => 'consent'") < strpos($res, 'new AvatarResolver'), 'las comprobaciones van antes de resolver nada');
$cons = substr($ac, strpos($ac, 'public function consent'), 2300);
t_ok(str_contains($cons, "Session::checkToken('post')") && str_contains($cons, "'POST'") && str_contains($cons, 'UserProfileKey::set(') && str_contains($cons, "\$grant !== '0' && \$grant !== '1'"), 'avatar.consent: POST + token CSRF + valor cerrado 0/1, solo con sesion');
$av = $lee("$c/backend/src/Helper/Avatar.php");
t_ok(str_contains($av, "!self::viewerHasSession()") && str_contains($av, "\$data['deferred'] = '';"), 'sin sesion no hay referencia diferida en el HTML');
$js = $lee("$c/media/js/gravatar.js");
t_ok(str_contains($js, 'avatar.consent') && str_contains($js, 'noServerConsent'), 'gravatar.js guarda el consentimiento en el servidor y vuelve a preguntar si el servidor no lo tiene');

echo "E) Adopcion de comentarios con correo verificado\n";
$up = $lee($root . '/src/plugins/user/engage/src/Extension/Engage.php');
t_ok(str_contains($up, "'onUserAfterSave'    => 'onUserAfterSave'") && str_contains($up, 'self::sameVerified($this->getDatabase(), (int) $userObject->id, (string) $userObject->email)'), 'al iniciar sesion solo se adopta si el correo actual es el verificado');
t_ok(str_contains($up, "in_array(\$task, ['registration.activate', 'activate'], true)") && str_contains($up, "isClient('site')") && str_contains($up, '!$this->isActivationRequest()'), 'solo se verifica en el clic del enlace de activacion del sitio (no desde administracion)');
t_ok(str_contains($up, 'hash_equals($pending, $email)') && str_contains($up, 'if ($isNew)'), 'el correo verificado es el de registro (pendiente) y solo si no cambio');
t_ok(str_contains($up, 'UserProfileKey::GRAVATAR_CONSENT, null'), 'al borrar la cuenta se borran las claves');
$own = substr($up, strpos($up, 'public function onUserLogin'), 1800);
t_ok(strpos($own, "own_comments") < strpos($own, 'sameVerified') && strpos($own, 'sameVerified') < strpos($own, 'ownComments('), 'se respeta own_comments y despues se comprueba el correo verificado, antes de adoptar');
t_fin();
