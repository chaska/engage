<?php
/**
 * 0.6.19: Gravatar con el consentimiento gobernado por el modulo JBCookies (parametros consent_source y jbcookies_group
 * del plugin). Solo tiene efecto con mode=ask; por defecto (engage) nada cambia. En modo jbcookies no hay aviso propio.
 * Parte PHP: plugin real con stubs. Parte JS: tests/24-gravatar-jbcookies.js (gravatar.js real en `vm`, tabla de la regla
 * de concesion con entradas hostiles).
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
		$attrs = $d['deferred'] === '' ? '' : (' data-engage-gravatar="' . $h($d['deferred']) . '"' . ($d['notice'] ? '' : ' data-engage-gravatar-notice="0"')
			. (($d['source'] ?? 'engage') === 'jbcookies' ? ' data-engage-gravatar-source="jbcookies" data-engage-gravatar-group="' . $h((string) ($d['group'] ?? '')) . '"' : ''));
		return '<img src="' . $h($d['src']) . '"' . $attrs . ' alt="">';
	}
	/** Contenido de atributos que el navegador carga solo (src, srcset, href, poster...) con gravatar.com. */
	function cargables(string $html): array
	{
		preg_match_all('/\s(src|srcset|href|poster|data-src|style)\s*=\s*"([^"]*)"/i', $html, $m, PREG_SET_ORDER);
		return array_values(array_filter($m, fn($x) => stripos(html_entity_decode($x[2]), 'gravatar.com') !== false));
	}


	$hash = hash('sha256', 'persona@ejemplo.com');
	$base = ['mode' => 'ask'];

	// --- consent_source: solo con mode=ask; ausente/raro = engage ---
	foreach ([[], ['consent_source' => ''], ['consent_source' => 'JBCookies'], ['consent_source' => 'jbcookies '], ['consent_source' => ['jbcookies']], ['consent_source' => 1], ['consent_source' => null], ['consent_source' => 'engage']] as $params) {
		t_ok(plugin($base + $params)->getConsentSource() === 'engage', 'consent_source ' . json_encode($params) . ' => engage');
	}
	t_ok(plugin($base + ['consent_source' => 'jbcookies'])->getConsentSource() === 'jbcookies', 'consent_source=jbcookies con mode=ask => jbcookies');
	t_ok(plugin(['consent_source' => 'jbcookies'])->getConsentSource() === 'jbcookies', 'consent_source=jbcookies con mode ausente (ask por defecto) => jbcookies');
	foreach (['off', 'always', 'x'] as $m) {
		$esperado = $m === 'x' ? 'jbcookies' : 'engage';   // un mode desconocido es ask
		t_ok(plugin(['mode' => $m, 'consent_source' => 'jbcookies'])->getConsentSource() === $esperado, "mode=$m + consent_source=jbcookies => $esperado");
	}

	// --- jbcookies_group: filtro estricto [a-z0-9_-] ---
	$grupos = [
		'terceros' => 'terceros', 'avatares' => 'avatares', 'marketing' => 'marketing', 'a_b-c9' => 'a_b-c9', ' marketing ' => 'marketing',
		'' => '', 'Terceros' => '', 'terceros!' => '', 'a b' => '', 'a.b' => '', 'a"><script>' => '', "a\nb" => '', 'ñandú' => '', '../x' => '', 'a;b' => '',
		'necessary' => '', '__proto__' => '', 'constructor' => '', 'prototype' => '', str_repeat('a', 64) => str_repeat('a', 64), str_repeat('a', 65) => '',
	];
	foreach ($grupos as $in => $out) { t_ok(plugin($base)->sanitizeGroup($in) === $out, 'sanitizeGroup(' . json_encode($in) . ') => ' . json_encode($out)); }
	foreach ([null, 1, ['terceros'], true, 1.5] as $raro) { t_ok(Gravatar::sanitizeGroup($raro) === '', 'sanitizeGroup de un valor no texto ' . json_encode($raro) . " => ''"); }
	t_ok(plugin($base + ['jbcookies_group' => 'terceros'])->getJbcookiesGroup() === 'terceros' && plugin($base)->getJbcookiesGroup() === '', 'getJbcookiesGroup: valor y defecto vacio');

	// --- modo engage (defecto): identico a 0.6.17/0.6.18 ---
	$antiguos = ['profile_link' => 1, 'rating' => 'G', 'default_image' => 'identicon', 'custom_default' => '', 'force_default' => '1'];
	$r0 = avatar(plugin($antiguos));
	$d0 = array_values($r0[1])[0];
	t_ok($d0['notice'] === true && $d0['source'] === 'engage' && $d0['group'] === '', 'engage: deferred con aviso propio, source=engage, sin grupo');
	$h0 = html($r0);
	t_ok(strpos($h0, 'data-engage-gravatar-source') === false && strpos($h0, 'data-engage-gravatar-group') === false && strpos($h0, 'data-engage-gravatar-notice') === false, 'engage: el HTML no lleva ningun atributo nuevo (igual que 0.6.18)');
	$rg = avatar(plugin($antiguos + ['jbcookies_group' => 'terceros']));
	t_ok(html($rg) === $h0, 'engage: jbcookies_group se ignora si consent_source no es jbcookies');
	t_ok(html(avatar(plugin(['mode' => 'off', 'consent_source' => 'jbcookies', 'jbcookies_group' => 'terceros']))) === html(avatar(plugin(['mode' => 'off']))), 'off: consent_source/jbcookies_group no cambian nada');
	t_ok(html(avatar(plugin(['mode' => 'always', 'consent_source' => 'jbcookies', 'jbcookies_group' => 'terceros']))) === html(avatar(plugin(['mode' => 'always']))), 'always: consent_source/jbcookies_group no cambian nada');
	t_ok(strpos(html(avatar(plugin(['mode' => 'always', 'consent_source' => 'jbcookies']))), 'data-engage-gravatar') === false, 'always con consent_source=jbcookies: sigue el src directo, sin data-*');

	// --- modo jbcookies ---
	foreach (['con grupo' => ['jbcookies_group' => 'terceros'], 'sin grupo' => []] as $n => $extra) {
		$r = avatar(plugin($base + $antiguos + ['consent_source' => 'jbcookies', 'show_notice' => 1] + $extra));
		$d = array_values($r[1])[0];
		$h = html($r);
		t_ok($d['notice'] === false && $d['source'] === 'jbcookies', "jbcookies ($n): sin aviso propio aunque show_notice=1");
		t_ok($r[0][0] === '/sub/media/com_engage/images/avatar-generico.svg' && cargables($h) === [], "jbcookies ($n): src local y ninguna URL de gravatar.com en atributos cargables");
		t_ok(strpos($h, 'data-engage-gravatar-notice="0"') !== false && strpos($h, 'data-engage-gravatar-source="jbcookies"') !== false, "jbcookies ($n): atributos notice=0 y source=jbcookies en el HTML");
		t_ok(strpos($h, 'data-engage-gravatar-group="' . ($extra['jbcookies_group'] ?? '') . '"') !== false, "jbcookies ($n): data-engage-gravatar-group con el valor filtrado");
		t_ok(perfil(plugin($base + ['consent_source' => 'jbcookies'])) === [null], "jbcookies ($n): sigue sin enlace de perfil");
	}
	foreach (['Terceros', 'a"><b', 'necessary', '__proto__'] as $malo) {
		$h = html(avatar(plugin($base + ['consent_source' => 'jbcookies', 'jbcookies_group' => $malo])));
		t_ok(strpos($h, 'data-engage-gravatar-group=""') !== false && stripos($h, $malo === 'a"><b' ? '<b' : 'zzzz') === false, 'jbcookies con grupo ' . json_encode($malo) . ' => grupo vacio en el HTML (solo "Aceptar todo" concede)');
	}
	// La capa del componente tambien filtra lo que reciba (plugin de terceros o manipulado)
	require_once dirname(__DIR__) . '/src/component/backend/src/Helper/Avatar.php';
	$m = new ReflectionMethod(\Akeeba\Component\Engage\Administrator\Helper\Avatar::class, 'pickAvatar');
	$m->setAccessible(true);
	$u = 'https://www.gravatar.com/avatar/' . $hash . '?s=48';
	foreach ([['source' => 'JBCookies', 'group' => 'x'], ['source' => ['jbcookies'], 'group' => 'x'], ['source' => 'engage', 'group' => 'x'], []] as $d) {
		$x = $m->invoke(null, ['results' => ['/l.svg'], 'deferred' => ['/l.svg' => ['url' => $u] + $d]]);
		t_ok($x['source'] === 'engage', 'Avatar::pickAvatar: source ' . json_encode($d['source'] ?? null) . ' => engage');
	}
	foreach (['A', 'a b', '"><x', str_repeat('a', 65), 5, null] as $g) {
		$x = $m->invoke(null, ['results' => ['/l.svg'], 'deferred' => ['/l.svg' => ['url' => $u, 'source' => 'jbcookies', 'group' => $g]]]);
		t_ok($x['source'] === 'jbcookies' && $x['group'] === '', 'Avatar::pickAvatar: grupo ' . json_encode($g) . " => ''");
	}
	$x = $m->invoke(null, ['results' => [], 'deferred' => []]);
	t_ok($x['source'] === 'engage' && $x['group'] === '' && $x['deferred'] === '', 'Avatar::pickAvatar: sin avatares => engage vacio');

	// --- manifiesto, idiomas, plantilla ---
	$xml = simplexml_load_file($root . '/plugins/engage/gravatar/gravatar.xml');
	$fs = $xml->xpath('//field[@name="consent_source"]')[0]; $fg = $xml->xpath('//field[@name="jbcookies_group"]')[0];
	t_ok((string) $fs['default'] === 'engage' && ['engage', 'jbcookies'] === array_map(fn($o) => (string) $o['value'], iterator_to_array($fs->option, false)) && (string) $fs['showon'] === 'mode:ask', 'gravatar.xml: consent_source con defecto engage, valores engage/jbcookies y showon mode:ask');
	t_ok((string) $fg['type'] === 'text' && (string) $fg['default'] === '' && (string) $fg['showon'] === 'mode:ask[AND]consent_source:jbcookies', 'gravatar.xml: jbcookies_group texto, vacio por defecto, solo visible con ask + jbcookies');
	foreach (['en-GB', 'es-ES'] as $l) {
		$sys = file_get_contents("$root/plugins/engage/gravatar/language/$l/plg_engage_gravatar.sys.ini");
		foreach (['CONSENT_SOURCE_LABEL', 'CONSENT_SOURCE_DESC', 'CONSENT_SOURCE_ENGAGE', 'CONSENT_SOURCE_JBCOOKIES', 'JBCOOKIES_GROUP_LABEL', 'JBCOOKIES_GROUP_DESC'] as $k) {
			t_ok(preg_match('/^PLG_ENGAGE_GRAVATAR_' . $k . '="[^"]+"$/m', $sys) === 1, "$l: PLG_ENGAGE_GRAVATAR_$k");
		}
	}
	$tpl = file_get_contents($root . '/component/frontend/tmpl/comments/default_list.php');
	t_ok(strpos($tpl, 'data-engage-gravatar-source="jbcookies"') !== false && preg_match('/data-engage-gravatar-group="\' \. htmlspecialchars\(/', $tpl) === 1, 'plantilla: atributos source/group (el grupo, escapado)');
	$js = file_get_contents($root . '/component/media/js/gravatar.js');
	t_ok(preg_match('/innerHTML|eval\(|new Function|document\.write/', $js) === 0 && preg_match('/document\.cookie\s*=(?!=)/', $js) === 0, 'gravatar.js: sigue sin eval/innerHTML y no escribe cookies');
	t_ok(substr_count($js, 'setAttribute("src", url)') === 1, 'gravatar.js: una sola asignacion de URL externa (tras la lista blanca)');
	// El filtro de grupo de PHP y de JS es el mismo
	t_ok(strpos($js, 'var JB_GROUP = /^[a-z0-9_-]{1,64}$/;') !== false && strpos(file_get_contents($root . '/plugins/engage/gravatar/src/Extension/Gravatar.php'), "'/^[a-z0-9_-]{1,64}\$/D'") !== false, 'el filtro del grupo es la misma expresion en PHP y en JS');

	// --- JS real (tabla de la regla de concesion) ---
	if (trim((string) shell_exec('command -v node'))) {
		$out = []; $rc = 0;
		exec('node ' . escapeshellarg(__DIR__ . '/24-gravatar-jbcookies.js') . ' 2>&1', $out, $rc);
		foreach ($out as $l) { if (strpos($l, 'FALLO') !== false) { echo $l . "\n"; } }
		$ult = end($out);
		t_ok($rc === 0, 'gravatar.js real en vm (tests/24-gravatar-jbcookies.js): ' . trim((string) $ult));
	} else {
		echo "  NO PROBADO: no hay node, no se ejecuta la parte JS\n";
	}
	t_fin();
}
