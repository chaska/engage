<?php
/**
 * 0.8.3 (H2): los enlaces firmados de moderacion y los enlaces al contenido de los correos se construian con la cabecera Host de la peticion que provocaba
 * el correo (SignedURL: Route::_(..., TLS_IGNORE, absoluta); Email: Uri::base()), asi que quien comentaba con un Host falso hacia que el correo llevara
 * enlaces (con el token firmado) a su propio dominio, y TLS_IGNORE permitia http. Ahora solo vale el `live_site` de configuration.php.
 *  A) TrustedBase::fromConfig: live_site valido o nada; https forzado con force_ssl = 2; nunca se baja a http
 *  B) TrustedBase::absolute: solo ruta, consulta y ancla; ningun anfitrion ajeno, ningun protocolo raro; sin base = cadena vacia
 *  C) el codigo: SignedURL, plugin Email, Akismet y semaforo del panel
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
require __DIR__ . '/stubs/otros-componentes.php';
$root = dirname(__DIR__) . '/src';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/component/backend/src/Helper/TrustedBase.php";

use Akeeba\Component\Engage\Administrator\Helper\TrustedBase;

$base = fn(string $l, int $f = 0) => TrustedBase::fromConfig($l, $f);
echo "A) fromConfig\n";
t_ok($base('https://www.example.com')['origin'] === 'https://www.example.com' && $base('https://www.example.com')['root'] === 'https://www.example.com/', 'https simple');
t_ok($base('https://www.example.com/')['root'] === 'https://www.example.com/', 'barra final');
t_ok($base('HTTPS://WWW.Example.COM/Sub/dir/')['root'] === 'https://www.example.com/Sub/dir/', 'subcarpeta, anfitrion en minusculas');
t_ok($base('https://example.com:8443')['origin'] === 'https://example.com:8443', 'puerto no estandar');
t_ok($base('https://example.com:443')['origin'] === 'https://example.com', 'puerto 443 de https se omite');
t_ok($base('http://example.com')['origin'] === 'http://example.com', 'http sin force_ssl: se respeta (no es decision nuestra)');
t_ok($base('http://example.com', 2)['origin'] === 'https://example.com', 'force_ssl = 2 (TLS_FORCE): http de live_site sube a https');
t_ok($base('http://example.com:80/sub', 2)['root'] === 'https://example.com/sub/', 'force_ssl = 2: el puerto 80 desaparece');
t_ok($base('https://example.com', 2)['origin'] === 'https://example.com' && $base('https://example.com', 1)['origin'] === 'https://example.com', 'https nunca baja a http, con cualquier force_ssl');
t_ok($base('http://example.com', 1)['origin'] === 'http://example.com', 'force_ssl = 1 (solo administracion): el sitio publico sigue como dice live_site');
t_ok($base('http://[::1]:8080')['origin'] === 'http://[::1]:8080', 'IPv6');
foreach (['', '   ', 'example.com', '//example.com', 'ftp://example.com', 'javascript:alert(1)', 'https://', 'https://user@example.com', 'https://user:pw@example.com', 'https://example.com?x=1', 'https://example.com#a',
	'https://exa mple.com', 'https://exam"ple.com', "https://example.com/'x", 'https://example.com/<b>', 'https://example.com\\evil', 'https://-example.com', 'https://example..com', 'https://exa_mple.com',
	'https://example.com:0', 'https://example.com:99999', 'https://example.com/a/../b', 'https://example.com/%2e%2e/x', 'https://example.com//x', 'https://example.com/a%2fb', 'https://exämple.com', str_repeat('a', 300)] as $mal) {
	t_ok($base($mal) === null, 'live_site rechazado: ' . json_encode(substr($mal, 0, 50)));
}

echo "B) absolute\n";
TrustedBase::$configOverride = ['https://www.example.com/sub', 0];
$abs = fn($u) => TrustedBase::absolute($u);
t_ok($abs('index.php?option=com_x&id=1') === 'https://www.example.com/sub/index.php?option=com_x&id=1', 'relativa no SEF: raiz fiable + ruta');
t_ok($abs('/sub/index.php/component/engage?task=x&amp;y=1') === 'https://www.example.com/sub/index.php/component/engage?task=x&amp;y=1', 'ruta absoluta: origen fiable');
t_ok($abs('http://evil.test/sub/a?b=1#c') === 'https://www.example.com/sub/a?b=1#c', 'URL absoluta con OTRO anfitrion (y http): solo se conservan ruta, consulta y ancla; el anfitrion y el protocolo son los fiables');
t_ok($abs('https://www.example.com:8080/x') === 'https://www.example.com/x', 'el puerto de la URL tampoco se hereda');
foreach (['', null, [], 'javascript:alert(1)', 'data:text/html,x', '//evil.test/x', 'http://user:pw@evil.test/x', 'mailto:a@b.c', "/x\ny", '/x"y', '/x y', '/x<y', "http://ok.test/x\r\nBcc: a@b.c", 'http://', str_repeat('a', 3000), new stdClass()] as $mal) {
	t_ok($abs($mal) === '', 'sin enlace: ' . json_encode(is_object($mal) ? 'objeto' : (is_string($mal) ? substr($mal, 0, 40) : $mal)));
}
TrustedBase::$configOverride = ['', 0];
t_ok($abs('index.php?x=1') === '' && $abs('https://evil.test/x') === '' && $abs('/x') === '' && TrustedBase::root() === '' && TrustedBase::available() === false, 'sin live_site: nada de enlaces (no se usa el Host de la peticion)');
TrustedBase::$configOverride = ['https://www.example.com', 0];
t_ok(TrustedBase::available() && TrustedBase::root() === 'https://www.example.com/', 'available() y root()');
TrustedBase::$configOverride = null;

echo "C) El codigo\n";
$su = $lee("$root/component/frontend/src/Helper/SignedURL.php");
t_ok(preg_match('/function getAbsoluteSignedURL.*?TrustedBase::available\(\).*?return \'\';.*?TrustedBase::absolute\(Route::_\(\$signedURL, true, Route::TLS_IGNORE, false\)\)/s', $su) === 1, 'SignedURL: sin base fiable no hay enlace; con ella solo se usa la ruta (Route::_ ya no pide la URL absoluta con el Host)');
t_ok(!preg_match('/Route::_\([^;]*,\s*true\s*\)\s*;/', $su) && !str_contains($su, 'Uri::base') && !str_contains($su, 'Uri::root'), 'SignedURL no usa Uri::base, Uri::root ni la URL absoluta de Route::_');
$em = $lee("$root/plugins/engage/email/src/Extension/Email.php");
t_ok(!str_contains($em, 'Uri::base()') && !str_contains($em, "'SITEURL'           => Uri::"), 'el plugin Email ya no usa Uri::base()');
t_ok(str_contains($em, "'SITEURL'           => \$includeLinks ? TrustedBase::root() : ''") && str_contains($em, "TrustedBase::absolute((string) (\$meta['public_url'] ?? ''))"), 'SITEURL y enlaces al contenido salen de la base fiable (public_url nulo = cadena vacia, sin deprecations)');
t_ok(str_contains($em, "strip_tags((string) (\$meta['title'] ?? ''))") && str_contains($em, "strip_tags((string) (\$meta['category'] ?? ''))"), 'titulo y categoria nulos (fichas de terceros) se convierten a cadena antes de strip_tags (PHP 8.1: sin deprecations)');
$ph = $lee("$root/component/backend/src/Helper/PanelHealth.php");
t_ok(preg_match("/array_key_exists\('trusted_base', \\\$f\) && !\\\$f\['trusted_base'\].*?'state' => 'nobase'/s", $ph) === 1, 'el semaforo de los correos avisa (ambar) si no hay base fiable');
foreach (['en-GB', 'es-ES'] as $l) {
	$ini = $lee("$root/component/backend/language/$l/com_engage.ini");
	t_ok(str_contains($ini, 'COM_ENGAGE_PANEL_H_PLUGIN_EMAIL_NOBASE="') && str_contains($ini, 'live_site'), "$l: texto del aviso");
}
t_ok(preg_match('/NOBASE="[^"]*abra el panel de control de Engage una vez como administrador[^"]*escriba la URL del sitio/', $lee("$root/component/backend/language/es-ES/com_engage.ini")) === 1, 'es-ES: en tono de «usted»');
require_once "$root/component/backend/src/Helper/PanelHealth.php";
use Akeeba\Component\Engage\Administrator\Helper\PanelHealth;
$f = ['plugins' => ['engage/email' => ['exists' => true, 'enabled' => true, 'id' => 5]]];
$it = fn(array $f) => array_values(array_filter(PanelHealth::evaluate($f)['items'], fn($i) => $i['id'] === 'plugin_email'))[0];
t_ok($it($f + ['trusted_base' => false])['level'] === 'warn' && $it($f + ['trusted_base' => false])['args']['state'] === 'nobase', 'PanelHealth: plugin activo sin base fiable = aviso');
t_ok($it($f + ['trusted_base' => true])['level'] === 'ok' && $it($f)['level'] === 'ok', 'PanelHealth: con base fiable (o hecho desconocido) = correcto');
t_ok($it(['plugins' => ['engage/email' => ['exists' => true, 'enabled' => false, 'id' => 5]], 'trusted_base' => false])['args']['state'] === 'disabled', 'PanelHealth: plugin desactivado sigue diciendo «desactivado»');
t_fin();
