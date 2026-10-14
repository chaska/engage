<?php
/**
 * 0.8.4: la «base fiable» de los enlaces de los correos ya NO exige editar configuration.php. Prioridad: opcion `email_base_url` > live_site >
 * base aprendida (la primera peticion de administracion autenticada al panel de control de Engage). Nunca el Host de una peticion publica.
 *  A) validacion estricta de la URL con variantes hostiles
 *  B) validateOption (lo que guarda el panel moderno)
 *  C) prioridad de las fuentes y semaforo
 *  D) aprendizaje: solo admin con core.admin por GET sin AJAX; una sola vez; nunca sobrescribe
 *  E) el codigo y los textos
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
require __DIR__ . '/stubs/otros-componentes.php';
$root = dirname(__DIR__) . '/src';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/component/backend/src/Helper/TrustedBase.php";
require_once "$root/component/backend/src/Helper/SettingsSchema.php";
require_once "$root/component/backend/src/Helper/PanelHealth.php";

use Akeeba\Component\Engage\Administrator\Helper\PanelHealth;
use Akeeba\Component\Engage\Administrator\Helper\SettingsSchema;
use Akeeba\Component\Engage\Administrator\Helper\TrustedBase;

$parse = fn(string $u, int $f = 0, string $m = 'save', string $h = '') => TrustedBase::parse($u, $f, $m, $h);
$opt   = function (string $v, int $force = 0, string $host = '') {
	TrustedBase::$requestHint = ['force' => $force, 'host' => $host];
	try { return TrustedBase::validateOption($v); } catch (InvalidArgumentException $e) { return null; } finally { TrustedBase::$requestHint = null; }
};

echo "A) Validacion estricta\n";
foreach ([
	'https://www.example.com' => 'https://www.example.com', 'https://www.example.com/' => 'https://www.example.com', 'HTTPS://WWW.Example.COM/Blog/' => 'https://www.example.com/Blog',
	'https://example.com:443' => 'https://example.com', 'https://example.com:8443/sub' => 'https://example.com:8443/sub', 'http://example.com' => 'http://example.com',
	'http://example.com:80/' => 'http://example.com', 'https://xn--exmple-cua.com' => 'https://xn--exmple-cua.com', '  https://example.com/a/b  ' => 'https://example.com/a/b',
] as $in => $out) {
	t_ok(($parse($in)['stored'] ?? null) === $out, 'valida y normaliza: ' . json_encode($in) . ' -> ' . $out);
}
t_ok($parse('https://example.com/sub')['root'] === 'https://example.com/sub/' && $parse('https://example.com/sub')['origin'] === 'https://example.com', 'root con barra final, origen sin ruta');
$mal = ['', '   ', 'example.com', '//example.com', '///example.com', 'https:example.com', 'https:/example.com', 'javascript:alert(1)', 'JaVaScRiPt://example.com/%0aalert(1)', 'data:text/html,x', 'ftp://example.com', 'file:///etc/passwd',
	'https://user@example.com', 'https://user:pw@example.com', 'https://example.com@evil.test', 'https://evil.test@example.com', 'https://example.com/@evil.test', 'https://@example.com',
	'https://exa mple.com', 'https://example.com/a b', "https://example.com/\ta", "https://example.com/\na", "https://example.com/\r\nBcc: a@b.c", "https://exam\0ple.com", 'https://exam"ple.com', "https://example.com/'x", 'https://example.com/<b>',
	'https://example.com\\evil.test', 'https:\\\\example.com', 'https://example.com/a\\b', 'https://example.com/`x',
	'https://example.com?', 'https://example.com?x=1', 'https://example.com/?x=1', 'https://example.com#', 'https://example.com#a', 'https://example.com/a#b',
	'https://example.com:0', 'https://example.com:65536', 'https://example.com:99999', 'https://example.com:-1', 'https://example.com:abc', 'https://example.com:', 'https://example.com:80:80', 'https://example.com:+443',
	'https://exämple.com', 'https://пример.рф', 'https://例え.jp', 'https://ｅxample.com', 'https://example.com/ñ', 'https://-example.com', 'https://example-.com', 'https://example..com', 'https://exa_mple.com', 'https://.example.com', 'https://example.com.',
	'https://example.com//x', 'https://example.com/a//b', 'https://example.com/a/../b', 'https://example.com/./a', 'https://example.com/%2e%2e/x', 'https://example.com/a%2fb', 'https://example.com/a%5cb',
	'https://localhost', 'https://LOCALHOST', 'https://foo.localhost', 'https://intranet', 'https://127.0.0.1', 'https://127.1', 'https://2130706433', 'https://0x7f.0.0.1', 'https://0177.0.0.1', 'https://10.0.0.5', 'https://192.168.1.1',
	'https://172.16.0.1', 'https://169.254.169.254', 'https://0.0.0.0', 'https://[::1]', 'https://[::]', 'https://[fe80::1]', 'https://[fd00::1]', 'https://[::ffff:127.0.0.1]', 'https://[zz::1]', 'https://[::1', 'https://::1',
	'https://' . str_repeat('a', 64) . '.com', 'https://example.com/' . str_repeat('a', 300), 'https://example.com:8080:9090', 'https://example.com/%', 'https://[1.2.3.4]'];
foreach ($mal as $m) {
	t_ok($parse($m) === null, 'rechazada (guardar): ' . json_encode(substr($m, 0, 60)));
}
t_ok($parse('https://93.184.216.34')['stored'] === 'https://93.184.216.34', 'una IP publica si vale');
t_ok($parse('http://example.com', 2) === null && $parse('http://example.com:80', 2) === null, 'guardar: http se rechaza si el sitio fuerza SSL (force_ssl = 2)');
t_ok($parse('http://example.com', 1) !== null && $parse('http://example.com', 0) !== null, 'guardar: http se admite si el sitio no fuerza SSL (force_ssl 0 o 1)');
t_ok($parse('https://example.com', 2)['stored'] === 'https://example.com', 'guardar: https siempre');
t_ok($parse('http://example.com/sub', 2, 'read')['stored'] === 'https://example.com/sub' && $parse('http://example.com:80', 2, 'read')['stored'] === 'https://example.com', 'leer: con force_ssl = 2 un http guardado sube a https (nunca baja)');
t_ok($parse('https://example.com', 0, 'read')['origin'] === 'https://example.com', 'leer: https nunca baja a http');
t_ok($parse('https://localhost', 0, 'save', 'localhost') !== null && $parse('https://127.0.0.1:8080', 0, 'save', '127.0.0.1')['stored'] === 'https://127.0.0.1:8080' && $parse('https://[::1]:8443', 0, 'save', '[::1]') !== null, 'guardar: una direccion interna solo si es la del propio sitio (el Host de la peticion de administracion)');
t_ok($parse('https://localhost', 0, 'save', 'otro.example.com') === null && $parse('https://127.0.0.1', 0, 'save', 'localhost') === null, 'guardar: interna distinta de la del sitio = rechazada');
t_ok($parse('https://localhost', 0, 'learn', 'localhost') === null && $parse('https://10.0.0.5', 0, 'learn', '10.0.0.5') === null, 'aprender: una direccion interna nunca se aprende');
t_ok($parse('https://127.0.0.1', 0, 'read') !== null, 'leer un valor ya guardado: no se vuelve a exigir lo de las direcciones internas (lo valido quien lo guardo)');
t_ok(TrustedBase::fromConfig('http://[::1]:8080')['origin'] === 'http://[::1]:8080', 'live_site: sin cambios (modo permisivo con direcciones internas)');

echo "B) validateOption (panel moderno)\n";
t_ok($opt('') === '' && $opt('   ') === '', 'vacio = borrar');
t_ok($opt('https://www.example.com/') === 'https://www.example.com' && $opt('https://www.example.com/blog/') === 'https://www.example.com/blog', 'normaliza (sin barra final)');
t_ok($opt('http://example.com', 2) === null && $opt('http://example.com', 0) === 'http://example.com', 'http solo si el sitio no fuerza SSL');
t_ok($opt('https://localhost') === null && $opt('https://localhost', 0, 'localhost') === 'https://localhost', 'localhost solo si es el del propio sitio');
t_ok($opt('javascript:alert(1)') === null && $opt('https://evil.test@example.com') === null && $opt('https://example.com/?a=1') === null, 'variantes hostiles rechazadas');
$def = SettingsSchema::parse((string) file_get_contents("$root/component/backend/config.xml"), 'com_engage')['fields']['email_base_url'] ?? null;
t_ok($def !== null && $def['control'] === 'text' && $def['maxlength'] === 255, 'el esquema del panel moderno conoce email_base_url (texto, 255)');
t_ok(!isset(SettingsSchema::parse((string) file_get_contents("$root/component/backend/config.xml"), 'com_engage')['fields']['email_base_learned']), 'el parametro aprendido (campo oculto) NO se puede cambiar desde el panel moderno');
TrustedBase::$requestHint = ['force' => 0, 'host' => ''];
t_ok(SettingsSchema::validate($def, 'https://example.com/') === 'https://example.com' && SettingsSchema::validate($def, '') === '', 'SettingsSchema::validate aplica y normaliza');
foreach (['javascript:alert(1)', 'https://u:p@example.com', 'https://example.com?x', 'https://127.0.0.1', str_repeat('a', 256), "https://example.com/\na"] as $m) {
	try { SettingsSchema::validate($def, $m); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
	t_ok($ok, 'SettingsSchema::validate rechaza ' . json_encode(substr($m, 0, 40)));
}
TrustedBase::$requestHint = null;

echo "C) Prioridad de las fuentes\n";
$cur = function (string $live, int $f = 0, string $o = '', string $l = '') { TrustedBase::$configOverride = [$live, $f, $o, $l]; return TrustedBase::current(); };
t_ok($cur('', 0, '', '') === null && !TrustedBase::available() && TrustedBase::source() === '' && TrustedBase::root() === '', 'sin nada: sin base');
t_ok($cur('', 0, '', 'https://aprendida.example.com')['origin'] === 'https://aprendida.example.com' && TrustedBase::source() === 'learned', 'solo aprendida');
t_ok($cur('https://live.example.com', 0, '', 'https://aprendida.example.com')['origin'] === 'https://live.example.com' && TrustedBase::source() === 'live_site', 'live_site manda sobre la aprendida');
t_ok($cur('https://live.example.com', 0, 'https://opcion.example.com', 'https://aprendida.example.com')['origin'] === 'https://opcion.example.com' && TrustedBase::source() === 'option', 'la opcion manda sobre live_site y sobre la aprendida');
t_ok($cur('', 0, 'https://opcion.example.com')['root'] === 'https://opcion.example.com/' && TrustedBase::absolute('index.php?x=1') === 'https://opcion.example.com/index.php?x=1', 'solo la opcion; absolute() usa esa base');
t_ok($cur('https://live.example.com', 0, 'javascript:alert(1)')['origin'] === 'https://live.example.com' && TrustedBase::optionIsInvalid(), 'una opcion no valida (escrita fuera del formulario) se ignora y se avisa; cae a live_site');
t_ok($cur('', 0, 'https://u@evil.test', 'basura') === null && TrustedBase::optionIsInvalid(), 'opcion y aprendida no validas: sin base (nunca se usa algo dudoso)');
t_ok(!$cur('https://l.example.com', 0, '') || !TrustedBase::optionIsInvalid(), 'opcion vacia no es «no valida»');
t_ok($cur('', 2, 'http://opcion.example.com')['origin'] === 'https://opcion.example.com' && $cur('', 2, '', 'http://a.example.com:80')['origin'] === 'https://a.example.com', 'force_ssl = 2: http guardado sube a https');
t_ok($cur('', 0, 'https://opcion.example.com')['origin'] === 'https://opcion.example.com' && $cur('', 2, 'https://opcion.example.com')['origin'] === 'https://opcion.example.com', 'https nunca baja a http');
t_ok($cur('', 0, 'https://op.example.com/sub')['root'] === 'https://op.example.com/sub/', 'subcarpeta');
TrustedBase::$configOverride = null;

$f = ['plugins' => ['engage/email' => ['exists' => true, 'enabled' => true, 'id' => 5]]];
$it = fn(array $x) => array_values(array_filter(PanelHealth::evaluate($f + $x)['items'], fn($i) => $i['id'] === 'plugin_email'))[0];
t_ok($it(['trusted_base' => false])['level'] === 'warn' && $it(['trusted_base' => false])['args']['state'] === 'nobase', 'semaforo: sin base = ambar (nobase)');
foreach (['option' => 'okoption', 'live_site' => 'oklive', 'learned' => 'oklearned'] as $src => $st) {
	$r = $it(['trusted_base' => true, 'base_url' => 'https://x.example.com', 'base_source' => $src]);
	t_ok($r['level'] === 'ok' && $r['args']['state'] === $st && $r['args']['url'] === 'https://x.example.com', "semaforo: correcto y dice de donde viene la base ($src)");
}
t_ok($it(['trusted_base' => true, 'base_url' => 'https://x.example.com', 'base_source' => 'option', 'base_option_bad' => true])['args']['state'] === 'badoption', 'semaforo: opcion no valida = ambar');
t_ok($it(['trusted_base' => true])['level'] === 'ok', 'semaforo: sin datos de origen no avisa');

echo "D) Aprendizaje\n";
// Candidato (funcion pura)
$lc = fn(string $root, bool $ssl = false, int $f = 0, string $live = '', string $o = '', string $l = '') => TrustedBase::learnCandidate($root, $ssl, $f, $live, $o, $l);
t_ok($lc('https://www.example.com/') === 'https://www.example.com', 'aprende la base de la peticion (https)');
t_ok($lc('https://www.example.com/blog/') === 'https://www.example.com/blog', 'subcarpeta');
t_ok($lc('http://www.example.com/') === 'http://www.example.com', 'http solo si el sitio no fuerza SSL y la peticion no llego por HTTPS');
t_ok($lc('http://www.example.com/', true) === 'https://www.example.com', 'la peticion llego por HTTPS (segun Joomla): esquema https');
t_ok($lc('http://www.example.com/', false, 2) === 'https://www.example.com' && $lc('http://www.example.com:80/', false, 2) === 'https://www.example.com', 'el sitio fuerza SSL: nunca se aprende http');
t_ok($lc('https://www.example.com:8443/') === 'https://www.example.com:8443', 'puerto');
t_ok($lc('https://www.example.com/', false, 0, 'https://live.example.com') === null, 'con live_site valido no se aprende nada');
t_ok($lc('https://www.example.com/', false, 0, 'javascript:x') === 'https://www.example.com', 'un live_site no valido no cuenta');
t_ok($lc('https://www.example.com/', false, 0, '', 'https://opcion.example.com') === null, 'con la opcion no se aprende nada');
t_ok($lc('https://www.example.com/', false, 0, '', '', 'https://ya.example.com') === null, 'nunca sobrescribe la ya aprendida');
t_ok($lc('https://www.example.com/', false, 0, '', '', 'basura') === 'https://www.example.com', 'una aprendida corrupta se sustituye');
foreach (['https://localhost/', 'http://127.0.0.1:8080/', 'https://10.0.0.7/', 'https://[::1]/', 'https://intranet/', 'javascript:alert(1)', 'https://u@evil.test/', 'https://example.com/?x=1', '//evil.test/', '', 'https://exa mple.com/'] as $r) {
	t_ok($lc($r) === null, 'no se aprende: ' . json_encode($r));
}

// Peticiones reales simuladas
require_once "$root/component/backend/src/Helper/SettingsStorage.php";
class T46Input { public function __construct(private string $method = 'GET', private array $c = []) {} public function getMethod() { return $this->method; } public function getCmd($k, $d = '') { return $this->c[$k] ?? $d; } }
class T46Session { public function __construct(private string $st = 'active') {} public function getState() { return $this->st; } }
class T46User extends Joomla\CMS\User\User { public $guest = false; public $id = 7; public function __construct(private bool $admin = true) {} public function authorise($a, $b = null) { return $this->admin && $a === 'core.admin' && $b === 'com_engage'; } }
$app = function (array $o = []) {
	return new class($o) {
		public function __construct(public array $o) {}
		public function isClient($c) { return ($this->o['client'] ?? 'administrator') === $c; }
		public function getInput() { return new T46Input($this->o['method'] ?? 'GET', $this->o['cmd'] ?? []); }
		public function getIdentity() { return $this->o['user'] ?? new T46User(); }
		public function getSession() { return new T46Session($this->o['session'] ?? 'active'); }
		public function isSslConnection() { return $this->o['ssl'] ?? true; }
		public function get($k, $d = null) { return $this->o['cfg'][$k] ?? $d; }
	};
};
$try = function (array $o, string $uriRoot = 'https://www.example.com/', array $cfg = [], ?array $store = null) use ($app) {
	$GLOBALS['T_APP'] = $app($o + ['cfg' => $cfg]);
	$GLOBALS['T_URI_ROOT'] = $uriRoot;
	TrustedBase::$configOverride = [$cfg['live_site'] ?? '', (int) ($cfg['force_ssl'] ?? 0), $cfg['option'] ?? '', $store['learned'] ?? ''];
	TrustedBase::$learnStore = $store ?? ['learned' => ''];
	unset($_SERVER['HTTP_X_REQUESTED_WITH']);
	$r = TrustedBase::learnFromAdminRequest();
	$res = [$r, TrustedBase::$learnStore['learned']];
	TrustedBase::$learnStore = null; TrustedBase::$configOverride = null;
	return $res;
};
[$r, $v] = $try([]);
t_ok($r === true && $v === 'https://www.example.com', 'administrador con core.admin abre el panel por GET: aprende');
[$r, $v] = $try(['client' => 'site'], 'https://evil.test/');
t_ok($r === false && $v === '', 'peticion PUBLICA (cliente site) con Host hostil: no aprende nada');
[$r, $v] = $try(['client' => 'cli'], 'https://evil.test/');
t_ok($r === false && $v === '', 'consola/cron (ni administrator ni site): no aprende, y lee la base guardada como cualquiera');
TrustedBase::$configOverride = ['', 0, '', 'https://guardada.example.com'];
t_ok(TrustedBase::root() === 'https://guardada.example.com/', 'consola: usa la base guardada'); TrustedBase::$configOverride = null;
[$r, $v] = $try(['user' => new T46User(false)], 'https://evil.test/');
t_ok($r === false && $v === '', 'usuario sin core.admin: no aprende');
$g = new T46User(true); $g->guest = true; $g->id = 0;
[$r, $v] = $try(['user' => $g], 'https://evil.test/');
t_ok($r === false && $v === '', 'anonimo: no aprende');
[$r, $v] = $try(['method' => 'POST']);
t_ok($r === false && $v === '', 'POST: no aprende');
[$r, $v] = $try(['cmd' => ['format' => 'json']]);
t_ok($r === false && $v === '', 'format=json: no aprende');
[$r, $v] = $try(['cmd' => ['format' => 'raw']]);
t_ok($r === false && $v === '', 'format=raw: no aprende');
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$GLOBALS['T_APP'] = $app([]); TrustedBase::$configOverride = ['', 0, '', '']; TrustedBase::$learnStore = ['learned' => ''];
$r = TrustedBase::learnFromAdminRequest(); $v = TrustedBase::$learnStore['learned']; TrustedBase::$learnStore = null; TrustedBase::$configOverride = null; unset($_SERVER['HTTP_X_REQUESTED_WITH']);
t_ok($r === false && $v === '', 'AJAX (X-Requested-With): no aprende');
[$r, $v] = $try(['session' => 'expired']);
t_ok($r === false && $v === '', 'sesion no activa: no aprende');
[$r, $v] = $try([], 'https://www.example.com/', ['live_site' => 'https://live.example.com']);
t_ok($r === false && $v === '', 'con live_site: no aprende');
[$r, $v] = $try([], 'https://www.example.com/', ['option' => 'https://opcion.example.com']);
t_ok($r === false && $v === '', 'con la opcion: no aprende');
[$r, $v] = $try([], 'https://otro.example.com/', [], ['learned' => 'https://www.example.com']);
t_ok($r === false && $v === 'https://www.example.com', 'ya aprendida: no se sobrescribe aunque otro admin entre por otro dominio (alias)');
[$r, $v] = $try([], 'http://www.example.com/', ['force_ssl' => 2], null);
$app2 = $app(['ssl' => false]);
t_ok($r === true && $v === 'https://www.example.com', 'sitio con SSL forzado: se aprende https aunque la peticion fuera http');
[$r, $v] = $try(['ssl' => false], 'http://www.example.com/');
t_ok($r === true && $v === 'http://www.example.com', 'sitio sin SSL forzado y peticion http: http (el sitio no fuerza SSL)');
[$r, $v] = $try([], 'https://localhost/');
t_ok($r === false && $v === '', 'direccion interna: no se aprende');
[$r, $v] = $try([], 'https://www.example.com/blog/');
t_ok($r === true && $v === 'https://www.example.com/blog', 'subcarpeta de Joomla (Uri::root la incluye)');
// olvidar y volver a aprender
TrustedBase::$learnStore = ['learned' => 'https://www.example.com'];
TrustedBase::forgetLearned();
t_ok(TrustedBase::$learnStore['learned'] === '', 'olvidar: queda vacia');
TrustedBase::$learnStore = null;
[$r, $v] = $try([], 'https://nuevo.example.com/');
t_ok($r === true && $v === 'https://nuevo.example.com', 'tras olvidar vuelve a aprender en la siguiente visita');
// Host hostil en peticiones publicas: la base vigente no cambia
$GLOBALS['T_URI_ROOT'] = 'https://evil.test/';
TrustedBase::$configOverride = ['', 0, '', 'https://www.example.com'];
t_ok(TrustedBase::root() === 'https://www.example.com/' && TrustedBase::absolute('index.php?x=1') === 'https://www.example.com/index.php?x=1' && TrustedBase::absolute('https://evil.test/x') === 'https://www.example.com/x', 'con Host hostil en la peticion los enlaces siguen siendo los de la base aprendida');
TrustedBase::$configOverride = null; unset($GLOBALS['T_URI_ROOT']);

echo "E) El codigo y los textos\n";
$tb = $lee("$root/component/backend/src/Helper/TrustedBase.php");
t_ok(!preg_match('/HTTP_HOST|SERVER_NAME|X_FORWARDED|FORWARDED|HTTP_X_|REMOTE_ADDR/', preg_replace('/HTTP_X_REQUESTED_WITH/', '', $tb)), 'TrustedBase no lee cabeceras de la peticion (Host, X-Forwarded-*)');
t_ok(preg_match('/isClient\(\'administrator\'\).*?getMethod\(\).*?!== \'GET\'.*?xmlhttprequest.*?\'format\'.*?core\.admin.*?getState\(\) !== \'active\'/s', $tb) === 1, 'el aprendizaje exige cliente administrator, GET, sin AJAX/format, core.admin y sesion activa');
$callers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $fi) {
	if ($fi->getExtension() === 'php' && str_contains((string) file_get_contents($fi->getPathname()), 'learnFromAdminRequest(')) { $callers[] = str_replace($root . '/', '', $fi->getPathname()); }
}
sort($callers);
t_ok($callers === ['component/backend/src/Helper/TrustedBase.php', 'component/backend/src/View/Controlpanel/HtmlView.php'], 'solo el panel de control de Engage del administrador aprende: ' . implode(', ', $callers));
$hv = $lee("$root/component/backend/src/View/Controlpanel/HtmlView.php");
t_ok(preg_match('/TrustedBase::learnFromAdminRequest\(\$user\);.*?PanelHealth::evaluate/s', $hv) === 1, 'el panel aprende antes de calcular el semaforo');
$cc = $lee("$root/component/backend/src/Controller/ControlpanelController.php");
t_ok(str_contains($cc, "return ['main', 'display', 'forgetbase'];") && str_contains($cc, "Session::checkToken('post')") && str_contains($cc, "authorise('core.admin', 'com_engage')") && preg_match('/forgetLearned\(\).*?setRedirect\(\'index\.php\'/s', $cc) === 1, 'olvidar: tarea en la lista cerrada, POST + token + core.admin; no vuelve al panel (no reaprende en la misma peticion)');
$tp = $lee("$root/component/backend/tmpl/controlpanel/default.php");
t_ok(str_contains($tp, "\$this->baseSource === 'learned' && \$this->can['superadmin']") && str_contains($tp, 'task=controlpanel.forgetbase') && str_contains($tp, '$quickToken'), 'el panel ofrece olvidar la base aprendida (solo si es la aprendida y con permiso) con token');
$cfg = $lee("$root/component/backend/config.xml");
t_ok(preg_match('/<field name="email_base_url"[^>]*?type="text"[^>]*default=""[^>]*maxlength="255"/s', $cfg) === 1 && preg_match('/<field name="email_base_learned"[^>]*?type="hidden"/', $cfg) === 1, 'config.xml: opcion de texto y parametro oculto (el formulario clasico no lo pierde al guardar)');
t_ok(str_contains($lee("$root/component/backend/src/Helper/SettingsLayout.php"), "[\$c, 'email_base_url']"), 'aparece en el panel moderno, grupo Notificaciones');
$su = $lee("$root/component/backend/src/Helper/SettingsSchema.php");
t_ok(str_contains($su, "'callback' => [TrustedBase::class, 'validateOption']"), 'el esquema valida la opcion con TrustedBase::validateOption');
foreach (['en-GB', 'es-ES'] as $l) {
	$ini = $lee("$root/component/backend/language/$l/com_engage.ini");
	$sys = $lee("$root/component/backend/language/$l/com_engage.sys.ini");
	foreach (['NOBASE', 'OKOPTION', 'OKLIVE', 'OKLEARNED', 'BADOPTION'] as $k) { t_ok(str_contains($ini, "COM_ENGAGE_PANEL_H_PLUGIN_EMAIL_$k=\""), "$l: texto del semaforo $k"); }
	foreach (['COM_ENGAGE_PANEL_BASE_FORGET', 'COM_ENGAGE_PANEL_BASE_FORGOTTEN', 'COM_ENGAGE_PANEL_BASE_FORGET_FAILED'] as $k) { t_ok(str_contains($ini, "$k=\""), "$l: $k"); }
	t_ok(str_contains($sys, 'COM_ENGAGE_CONFIG_EMAIL_BASE_URL_LABEL="') && str_contains($sys, 'COM_ENGAGE_CONFIG_EMAIL_BASE_URL_DESC="'), "$l: etiqueta y ayuda de la opcion");
	t_ok(!preg_match('/NOBASE="[^"]*(Escriba la opción live_site en configuration|Set the live_site option in configuration)/', $ini), "$l: ya no se pide tocar configuration.php");
}
t_ok(preg_match('/NOBASE="[^"]*abra el panel de control de Engage una vez como administrador[^"]*escriba la URL del sitio en Opciones/', $lee("$root/component/backend/language/es-ES/com_engage.ini")) === 1, 'es-ES (usted): «abra el panel de control de Engage una vez como administrador o escriba la URL del sitio»');
t_ok(preg_match('/NOBASE="[^"]*open the Engage control panel once as an administrator[^"]*type the site URL/', $lee("$root/component/backend/language/en-GB/com_engage.ini")) === 1, 'en-GB: la misma instruccion');
t_ok(preg_match('/BASE_URL_DESC="[^"]*usted/', $lee("$root/component/backend/language/es-ES/com_engage.sys.ini")) === 1, 'es-ES: ayuda de la opcion en tono de «usted»');
$pd = $lee("$root/component/backend/src/Helper/PanelData.php");
t_ok(str_contains($pd, "'base_source'      => TrustedBase::source()") && str_contains($pd, "'base_url'         => rtrim(TrustedBase::root(), '/')"), 'PanelData: hechos de la base y su origen');
t_end:
t_fin();
