<?php
/**
 * 0.6.17: Gravatar con consentimiento previo (RGPD/ePrivacy). Modos off / ask (defecto) / always, con `mode` ausente o
 * desconocido = ask. En off y ask el HTML no puede hacer que el navegador contacte con gravatar.com: ni src, ni srcset,
 * ni href; la URL solo va en data-engage-gravatar (modo ask). Lista blanca de la URL (PHP y JS con la misma regla).
 * Se ejecuta el plugin real con stubs mínimos y se renderizan los atributos como lo hace default_list.php.
 */
namespace Joomla\CMS\Plugin {
	class CMSPlugin
	{
		public $params;
		protected $allowLegacyListeners = false;
		public function __construct($subject = null, array $config = []) { $this->params = new class($config['params'] ?? []) {
			public function __construct(private array $d) {}
			public function get($k, $def = null) { return array_key_exists($k, $this->d) ? $this->d[$k] : $def; }
		}; }
	}
}
namespace Joomla\CMS\Uri {
	class Uri
	{
		public static function base($pathonly = false) { return 'https://sitio.example/'; }
		public static function root($pathonly = false) { return $pathonly ? '/sub' : 'https://sitio.example/sub/'; }
	}
}
namespace Joomla\CMS\User { if (!class_exists(User::class)) { class User { public $email = ''; } } }
namespace Joomla\Event {
	interface SubscriberInterface {}
	class Event
	{
		public array $a;
		public function __construct(public string $n, array $a = []) { $this->a = $a; }
		public function getArguments() { return $this->a; }
		public function getArgument($k, $d = null) { return $this->a[$k] ?? $d; }
		public function setArgument($k, $v) { $this->a[$k] = $v; return $this; }
	}
}
namespace {
	use Joomla\Event\Event;
	use Joomla\CMS\User\User;
	require __DIR__ . '/aserciones.php';
	if (!defined('_JEXEC')) { define('_JEXEC', 1); }
	$root = dirname(__DIR__) . '/src';
	require $root . '/plugins/engage/gravatar/src/Extension/Gravatar.php';
	use Akeeba\Plugin\Engage\Gravatar\Extension\Gravatar;

	function plugin(array $params): Gravatar { return new Gravatar(null, ['params' => $params]); }
	function avatar(Gravatar $p, string $email = 'Persona@Ejemplo.com', int $size = 48): array
	{
		$u = new User(); $u->email = $email;
		$e = new Event('onAkeebaEngageUserAvatarURL', [$u, $size]);
		$p->onAkeebaEngageUserAvatarURL($e);
		return [$e->getArgument('result', []), $e->getArgument('deferred', [])];
	}
	function perfil(Gravatar $p): array
	{
		$u = new User(); $u->email = 'persona@ejemplo.com';
		$e = new Event('onAkeebaEngageUserProfileURL', [$u]);
		$p->onAkeebaEngageUserProfileURL($e);
		return $e->getArgument('result', []);
	}
	/** Misma lógica que Avatar::pickAvatar + default_list.php (se invoca el Avatar real por reflexión). */
	function html(array $res): string
	{
		require_once dirname(__DIR__) . '/src/component/backend/src/Helper/Avatar.php';
		$m = new ReflectionMethod(\Akeeba\Component\Engage\Administrator\Helper\Avatar::class, 'pickAvatar');
		$m->setAccessible(true);
		$d = $m->invoke(null, ['results' => $res[0], 'deferred' => $res[1]]);
		$h = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$attrs = $d['deferred'] === '' ? '' : (' data-engage-gravatar="' . $h($d['deferred']) . '"' . ($d['notice'] ? '' : ' data-engage-gravatar-notice="0"'));
		return '<img src="' . $h($d['src']) . '"' . $attrs . ' alt="">';
	}
	/** Contenido de atributos que el navegador carga solo (src, srcset, href, poster...) con gravatar.com. */
	function cargables(string $html): array
	{
		preg_match_all('/\s(src|srcset|href|poster|data-src|style)\s*=\s*"([^"]*)"/i', $html, $m, PREG_SET_ORDER);
		return array_values(array_filter($m, fn($x) => stripos(html_entity_decode($x[2]), 'gravatar.com') !== false));
	}

	$hash = hash('sha256', 'persona@ejemplo.com');

	// --- mode ausente / desconocido = ask ---
	foreach ([[], ['mode' => ''], ['mode' => 'ALWAYS'], ['mode' => 'x'], ['mode' => null], ['mode' => 0], ['mode' => ['always']]] as $i => $params) {
		t_ok(plugin($params)->getMode() === 'ask', 'mode ' . json_encode($params) . ' => ask');
	}
	t_ok(plugin(['mode' => 'off'])->getMode() === 'off' && plugin(['mode' => 'always'])->getMode() === 'always' && plugin(['mode' => 'ask'])->getMode() === 'ask', 'off, ask y always se reconocen');

	// --- ask (también con mode ausente y con parametros antiguos de una instalacion existente) ---
	$antiguos = ['profile_link' => 1, 'rating' => 'G', 'default_image' => 'identicon', 'custom_default' => '', 'force_default' => '1'];
	foreach (['mode ausente con parametros antiguos' => $antiguos, 'mode=ask' => ['mode' => 'ask'] + $antiguos] as $nombre => $params) {
		$r = avatar(plugin($params));
		$h = html($r);
		t_ok($r[0][0] === '/sub/media/com_engage/images/avatar-generico.svg', "ask ($nombre): el avatar servido es el SVG local del componente");
		t_ok(cargables($h) === [], "ask ($nombre): ningun src/srcset/href cargable contiene gravatar.com");
		t_ok(preg_match('#data-engage-gravatar="https://www\.gravatar\.com/avatar/' . $hash . '\?s=48&amp;r=g&amp;d=identicon&amp;f=y"#', $h) === 1, "ask ($nombre): la URL de Gravatar va en data-engage-gravatar (escapada)");
		t_ok(stripos($h, '<link') === false && stripos($h, 'srcset') === false && stripos($h, '<picture') === false, "ask ($nombre): sin link, srcset ni picture");
		t_ok(perfil(plugin($params)) === [null], "ask ($nombre): no se emite el enlace de perfil (nunca)");
	}
	foreach (['avatar-generico.svg', 'avatar-blanco.svg'] as $f) { t_ok(is_file("$root/component/media/images/$f"), "existe media/images/$f"); }
	$svg = file_get_contents("$root/component/media/images/avatar-generico.svg");
	t_ok(strpos($svg, '<svg') === 0 && stripos($svg, 'http://www.w3.org/2000/svg') !== false && !preg_match('#(href|src)\s*=#i', $svg) && stripos($svg, 'gravatar') === false, 'el SVG local es autonomo: sin href/src y sin gravatar');
	t_ok(html(avatar(plugin([]), '', 48)) === '<img src="' . avatar(plugin([]), '', 48)[0][0] . '" alt="">', 'ask sin email: avatar local y sin data-engage-gravatar');
	$r = avatar(plugin(['show_notice' => 0]));
	t_ok(strpos(html($r), 'data-engage-gravatar-notice="0"') !== false, 'show_notice=0 se transmite a la imagen (el aviso propio se oculta, la API sigue)');
	t_ok(strpos(html(avatar(plugin(['show_notice' => 1]))), 'data-engage-gravatar-notice') === false && strpos(html(avatar(plugin([]))), 'data-engage-gravatar-notice') === false, 'show_notice por defecto = 1 (sin atributo)');
	// default_image custom local y blank: no terceros
	$r = avatar(plugin(['default_image' => 'custom', 'custom_default' => 'images/mi avatar.png#joomlaImage://local-images/x.png?width=1']));
	t_ok(cargables(html($r)) === [] && $r[0][0] === '/sub/media/com_engage/images/avatar-generico.svg', 'custom con caracteres no permitidos (espacio) => silueta local');
	$r = avatar(plugin(['default_image' => 'custom', 'custom_default' => 'images/yo.png#joomlaImage://local-images/yo.png?width=1&height=1']));
	t_ok($r[0][0] === '/sub/images/yo.png' && cargables(html($r)) === [], 'custom valido => imagen del propio sitio (ruta local), sin tercero');
	$r = avatar(plugin(['default_image' => 'custom', 'custom_default' => '//evil.example/x.png']));
	t_ok($r[0][0] === '/sub/evil.example/x.png', "custom '//evil.example/x.png' => ruta local del propio sitio (nunca un host externo)");
	foreach (['../etc/passwd.png', 'http://evil.example/x.png', 'javascript:alert(1).png', 'images/x.php', 'images/..%2fx.png'] as $malo) {
		$r = avatar(plugin(['default_image' => 'custom', 'custom_default' => $malo]));
		t_ok($r[0][0] === '/sub/media/com_engage/images/avatar-generico.svg', "custom '$malo' rechazado => silueta local");
	}
	$r = avatar(plugin(['default_image' => 'blank']));
	t_ok($r[0][0] === '/sub/media/com_engage/images/avatar-blanco.svg' && trim(file_get_contents("$root/component/media/images/avatar-blanco.svg")) === '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="48" height="48"></svg>', 'blank => SVG vacio local');
	t_ok(strpos(array_values($r[1])[0]['url'], 'd=blank') !== false, 'blank: el d= de Gravatar se conserva solo en la URL diferida');

	// --- off ---
	$r = avatar(plugin(['mode' => 'off']));
	$h = html($r);
	t_ok($r[1] === [] && stripos($h, 'gravatar') === false, 'off: ni data-engage-gravatar ni ninguna mencion de gravatar en el HTML');
	t_ok($r[0][0] === '/sub/media/com_engage/images/avatar-generico.svg', 'off: avatar local');
	t_ok(perfil(plugin(['mode' => 'off'])) === [null], 'off: sin enlace de perfil');

	// --- always (comportamiento anterior) ---
	$r = avatar(plugin(['mode' => 'always'] + $antiguos));
	$h = html($r);
	t_ok($r[0][0] === "https://www.gravatar.com/avatar/$hash?s=48&r=g&d=identicon&f=y" && $r[1] === [], 'always: src directo igual que antes (URL identica a la de 0.6.16)');
	t_ok(count(cargables($h)) === 1 && strpos($h, 'data-engage-gravatar') === false, 'always: gravatar.com si aparece en src y no hay data-engage-gravatar');
	t_ok(perfil(plugin(['mode' => 'always'])) === ["https://www.gravatar.com/$hash"], 'always: enlace de perfil como antes');
	t_ok(perfil(plugin(['mode' => 'always', 'profile_link' => 0])) === [null], 'always con profile_link=0: sin enlace');
	$r = avatar(plugin(['mode' => 'always', 'default_image' => 'custom', 'custom_default' => 'images/x.png']));
	t_ok(strpos($r[0][0], '&d=' . urlencode('https://sitio.example/images/x.png')) !== false, 'always con custom: d= como antes');
	$r = avatar(plugin(['mode' => 'always', 'rating' => 'PG', 'default_image' => 'zzz']));
	t_ok(strpos($r[0][0], '&r=pg&d=mp') !== false, 'valores raros (default_image) caen en valores permitidos');
	$r = avatar(plugin(['mode' => 'always', 'rating' => 'x" onerror="1']));
	t_ok(strpos($r[0][0], '&r=g&') !== false, 'rating con comillas => g');

	// --- lista blanca ---
	$ok = ["https://www.gravatar.com/avatar/$hash", "https://www.gravatar.com/avatar/$hash?s=48&r=g&d=mp", 'https://www.gravatar.com/avatar/' . md5('a') . '?d=https%3A%2F%2Fsitio.example%2Fx.png'];
	$mal = ["http://www.gravatar.com/avatar/$hash", "https://gravatar.com/avatar/$hash", "https://www.gravatar.com.evil.example/avatar/$hash", "https://evil.example/https://www.gravatar.com/avatar/$hash", "https://www.gravatar.com@evil.example/avatar/$hash", "https://www.gravatar.com/avatar/$hash\"onerror=\"x", "https://www.gravatar.com/avatar/$hash?x=<s>", "https://www.gravatar.com/avatar/$hash\n", "https://www.gravatar.com/avatar/", "https://www.gravatar.com/avatar/zz", "https://www.gravatar.com/profile/$hash", "javascript:alert(1)", "data:text/html,x", " https://www.gravatar.com/avatar/$hash", "https://www.gravatar.com/avatar/$hash#x"];
	foreach ($ok as $u) { t_ok(Gravatar::isAllowedGravatarURL($u), 'lista blanca acepta ' . substr($u, 0, 70)); }
	foreach ($mal as $u) { t_ok(!Gravatar::isAllowedGravatarURL($u), 'lista blanca rechaza ' . json_encode(substr($u, 0, 70))); }

	// --- la misma regla en JS ---
	$js = file_get_contents($root . '/component/media/js/gravatar.js');
	preg_match('#var ALLOWED_URL = /(.*)/;#', $js, $mm);
	$phpSrc = file_get_contents($root . '/plugins/engage/gravatar/src/Extension/Gravatar.php');
	preg_match("#'\\#(\\^https.*?)\\#D'#", $phpSrc, $pm);
	$normJs  = str_replace('\\/', '/', $mm[1] ?? '');
	$normPhp = str_replace('\\\\', '\\', $pm[1] ?? '');
	t_ok($normJs !== '' && $normJs === $normPhp, 'la expresion de lista blanca es la misma en PHP y en gravatar.js');
	t_ok(preg_match('/\.src\s*=|innerHTML|eval\(|new Function|document\.write|outerHTML|insertAdjacentHTML/', $js) === 0, 'gravatar.js: sin eval, innerHTML ni asignaciones directas a .src');
	t_ok(substr_count($js, 'setAttribute("src", url)') === 1 && strpos($js, 'ALLOWED_URL.test(url)') < strpos($js, 'setAttribute("src", url)'), 'gravatar.js: la unica asignacion de una URL externa va tras la lista blanca');
	t_ok(strpos($js, 'engage_gravatar_consent') !== false && strpos($js, 'engage:gravatar-consent') !== false && strpos($js, 'window.AkeebaEngageGravatar') !== false && preg_match('/document\.cookie\s*=(?!=)/', $js) === 0, 'gravatar.js: clave de localStorage, evento y API; nunca escribe cookies (0.6.19: solo lee la de JBCookies)');
	$rc = 0; $out = [];
	if (trim((string) shell_exec('command -v node'))) { exec('node --check ' . escapeshellarg($root . '/component/media/js/gravatar.js') . ' 2>&1', $out, $rc); t_ok($rc === 0, 'gravatar.js: node --check'); }

	// --- integracion: plantilla, activo, idiomas ---
	$tpl = file_get_contents($root . '/component/frontend/tmpl/comments/default_list.php');
	t_ok(substr_count($tpl, 'src="<?= htmlspecialchars($avatar') === 2 && strpos($tpl, 'src="<?= $avatar ?>"') === false, 'plantilla: el src del avatar se escapa en las dos ramas');
	t_ok(substr_count($tpl, '<?= $avatarAttrs ?>') === 2, 'plantilla: data-engage-gravatar en las dos ramas');
	t_ok(strpos($tpl, "useScript('com_engage.gravatar')") !== false && strpos($tpl, 'if ($avatarDeferred !== \'\')') !== false, 'plantilla: el JS solo se registra si hay avatares diferidos (sin el plugin no cambia nada)');
	$asset = json_decode(file_get_contents($root . '/component/media/joomla.asset.json'), true);
	$a = array_values(array_filter($asset['assets'], fn($x) => $x['name'] === 'com_engage.gravatar'));
	t_ok(count($a) === 1 && $a[0]['type'] === 'script' && is_file($root . '/component/media/js/basename-placeholder') === false && is_file($root . '/component/media/js/' . basename($a[0]['uri'])), 'joomla.asset.json registra com_engage.gravatar y el archivo existe');
	foreach (['en-GB', 'es-ES'] as $l) {
		$ini = file_get_contents("$root/component/frontend/language/$l/com_engage.ini");
		foreach (['NOTICE_TEXT', 'BTN_ACCEPT', 'BTN_REVOKE', 'STATUS_ON', 'NOTICE_LABEL'] as $k) {
			t_ok(strpos($ini, "COM_ENGAGE_GRAVATAR_$k=") !== false && strpos($js, "COM_ENGAGE_GRAVATAR_$k") !== false && strpos($tpl, "COM_ENGAGE_GRAVATAR_$k") !== false, "$l: cadena COM_ENGAGE_GRAVATAR_$k definida, usada por el JS y pasada con Text::script");
		}
		$sys = file_get_contents("$root/plugins/engage/gravatar/language/$l/plg_engage_gravatar.sys.ini");
		foreach (['MODE_LABEL', 'MODE_DESC', 'MODE_ASK', 'MODE_OFF', 'MODE_ALWAYS', 'SHOW_NOTICE_LABEL', 'SHOW_NOTICE_DESC'] as $k) { t_ok(strpos($sys, "PLG_ENGAGE_GRAVATAR_$k=") !== false, "$l: PLG_ENGAGE_GRAVATAR_$k"); }
	}
	t_ok(strpos(file_get_contents("$root/component/frontend/language/es-ES/com_engage.ini"), 'Mostrar fotos de Gravatar (esto envía tu IP a gravatar.com)') !== false, 'es-ES: texto del boton acordado');
	$x = simplexml_load_file($root . '/plugins/engage/gravatar/gravatar.xml');
	$modo = $x->xpath('//field[@name="mode"]')[0]; $notice = $x->xpath('//field[@name="show_notice"]')[0];
	t_ok((string) $modo['default'] === 'ask' && ['ask', 'off', 'always'] === array_map(fn($o) => (string) $o['value'], iterator_to_array($modo->option, false)), 'gravatar.xml: mode con defecto ask y valores ask/off/always');
	t_ok((string) $notice['default'] === '1', 'gravatar.xml: show_notice por defecto 1');
	$eng = file_get_contents($root . '/plugins/system/engagecache/src/Extension/Engagecache.php');
	t_ok(strpos($eng, "isEnabled('engage', 'gravatar')") !== false && strpos($eng, 'COM_ENGAGE_GRAVATAR_BTN_ACCEPT') !== false, 'engagecache: textos del aviso solo con el plugin Gravatar activo');
	// Ninguna aparicion de gravatar.com en plantillas ni JS fuera de la lista blanca
	t_ok(strpos($tpl, 'gravatar.com') === false, 'plantilla sin URLs de gravatar.com');
	t_fin();
}
