<?php
/**
 * 0.9.8: la foto del usuario en sus comentarios: campo personalizado de usuario (`customfield_avatar`, valor NO fiable) y la foto real gana al avatar
 * generico local de Gravatar, sea cual sea el orden de los plugins. Funciones puras, valores hostiles.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/backend/src/Helper/AvatarField.php";

use Akeeba\Component\Engage\Administrator\Helper\AvatarField as A;

echo "A) validId\n";
t_ok(A::validId('12') === '12' && A::validId(' 7 ') === '7' && A::validId('') === '' && A::validId('0') === '' && A::validId('01') === '' && A::validId('1;DROP') === '' && A::validId('12345678901') === '' && A::validId([1]) === '' && A::validId(null) === '', 'solo un entero positivo de hasta 10 cifras (o vacio = ninguno)');

echo "B) Valores validos\n";
$ok = [
	'<img src="/images/avatars/chaska.jpg" alt="" class="x">' => '/images/avatars/chaska.jpg',
	'<img src="images/avatars/chaska.png?width=200&amp;height=200#joomlaImage://local-images/avatars/chaska.png?width=200">' => '/images/avatars/chaska.png',
	'images/avatars/a.webp#joomlaImage://local-images/avatars/a.webp?width=1&height=1' => '/images/avatars/a.webp',
	'/images/a.AVIF' => '/images/a.AVIF', '/images/a%20b.gif' => '/images/a%20b.gif',
	'{"imagefile":"images/f/foto.jpeg#joomlaImage://x","alt_text":""}' => '/images/f/foto.jpeg',
	'<p><a href="/x"><img src="/images/z.jpg"></a></p>' => '/images/z.jpg',
];
foreach ($ok as $in => $out) { t_ok(A::imagePath($in) === $out, 'valido: ' . substr($in, 0, 60) . ' => ' . A::imagePath($in)); }
t_ok(A::imagePath('https://www.example.com/images/a.jpg', 'https://www.example.com') === '/images/a.jpg', 'URL https del propio sitio (base fiable) => ruta del sitio');
t_ok(A::imagePath('images/a.jpg', '', '/sub') === '/sub/images/a.jpg' && A::imagePath('/sub/images/a.jpg', '', '/sub') === '/sub/images/a.jpg', 'sitio en subcarpeta: la carpeta se antepone una sola vez');
t_ok(A::imagePath(['/images/a.jpg', 'otra']) === '/images/a.jpg', 'valor en lista: el primero');

echo "C) Valores hostiles: no hay foto\n";
$mal = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)//x.jpg', 'data:image/png;base64,AAAA', 'data:text/html,<script>alert(1)</script>.jpg', 'https://evil.example/a.jpg', 'http://www.example.com/a.jpg', '//evil.example/a.jpg', '/\\evil.example/a.jpg', '../../etc/passwd.jpg', '/images/../../a.jpg', '/images/%2e%2e/a.jpg', '/images/..%2fa.jpg', '/images/a.php', '/images/a.jpg.php', '/images/a.svg', '/images/a', '/images/a.jpg"onerror="alert(1)', "/images/a.jpg'", '/images/a b.jpg', "/images/a\0.jpg", '<img src="javascript:alert(1)">', '<img src="https://evil.example/x.jpg">', '<img onerror=alert(1)>', '<script>alert(1)</script>', '', '   ', str_repeat('a', 600) . '.jpg', '/' . str_repeat('a/', 300) . 'a.jpg', 'file:///etc/a.jpg', 'ftp://a/b.jpg', 'chrome://a.jpg', '/images/<b>.jpg', '{"imagefile":"javascript:alert(1)"}', '{"imagefile":["x"]}', '{no es json', null, 12, true];
$colados = [];
foreach ($mal as $v) { $r = A::imagePath($v, 'https://www.example.com'); if ($r !== '') { $colados[] = (string) json_encode($v) . ' => ' . $r; } }
t_ok(!$colados, count($mal) . ' valores hostiles: ninguno pasa' . ($colados ? ' [' . implode(' | ', $colados) . ']' : ''));
t_ok(A::imagePath('https://www.example.com.evil.example/a.jpg', 'https://www.example.com') === '' && A::imagePath('https://www.example.com@evil.example/a.jpg', 'https://www.example.com') === '', 'el dominio de la base no se puede imitar (sufijo, usuario@)');
t_ok(A::imagePath('https://www.example.com/a.jpg', '') === '', 'sin base fiable ninguna URL absoluta');
foreach (['/images/ok.jpg', 'images/ok.png'] as $v) { $r = A::imagePath($v); t_ok($r !== '' && !preg_match('/["\'<>\s\\\\]/', $r), "lo que sale no lleva caracteres que rompan un atributo: $r"); }

echo "D) Avatar generico y pickAvatar\n";
t_ok(A::isGeneric('/media/com_engage/images/avatar-generico.svg') && A::isGeneric('/sub/media/com_engage/images/avatar-blanco.svg?x=1') && !A::isGeneric('/images/avatar-generico.svg') && !A::isGeneric('/images/chaska.jpg') && !A::isGeneric('https://www.gravatar.com/avatar/x'), 'solo los dos avatares locales de Engage son genericos');
$av = $lee("$root/backend/src/Helper/Avatar.php");
t_ok(str_contains($av, 'AvatarField::rank') && str_contains($av, 'AvatarField::imagePath($field->value') && str_contains($av, 'AvatarField::validId(') && !str_contains($av, 'src\\s*=\\s*"(.*)"'), 'pickAvatar prefiere la foto; el campo se lee con AvatarField (sin la expresion codiciosa de antes)');
t_ok(str_contains($av, 'TrustedBase::current()'), 'la base fiable es la de TrustedBase');
// pickAvatar real, con el orden de los plugins en los dos sentidos
require_once "$root/backend/src/Helper/Avatar.php";
$m = new ReflectionMethod(\Akeeba\Component\Engage\Administrator\Helper\Avatar::class, 'pickAvatar'); $m->setAccessible(true);
$gen = '/media/com_engage/images/avatar-generico.svg'; $foto = '/images/avatars/chaska.jpg'; $def = [$gen => ['url' => 'https://www.gravatar.com/avatar/abc?s=48', 'notice' => true, 'source' => 'engage', 'group' => '']];
$a = $m->invoke(null, ['results' => [$gen, $foto], 'deferred' => $def]); $b = $m->invoke(null, ['results' => [$foto, $gen], 'deferred' => $def]);
t_ok($a['src'] === $foto && $b['src'] === $foto && $a['deferred'] === '' && $b['deferred'] === '', 'Gravatar (generico + URL diferida) antes o despues de un plugin de fotos: gana la foto y no se pide Gravatar');
$c = $m->invoke(null, ['results' => [$gen], 'deferred' => $def]);
t_ok($c['src'] === $gen && $c['deferred'] === 'https://www.gravatar.com/avatar/abc?s=48', 'sin foto, el consentimiento de Gravatar sigue igual: generico + URL diferida');
$d = $m->invoke(null, ['results' => ['', null, $gen], 'deferred' => []]); $e = $m->invoke(null, ['results' => [], 'deferred' => []]);
t_ok($d['src'] === $gen && $e['src'] === '', 'vacios descartados; sin nada, vacio');
$f = $m->invoke(null, ['results' => ['https://www.gravatar.com/avatar/x', $foto], 'deferred' => []]);
t_ok($f['src'] === $foto, 'Gravatar (modo siempre) antes que un plugin de fotos: gana la foto del plugin');
$f2 = $m->invoke(null, ['results' => ['/images/a.jpg', '/images/b.jpg'], 'deferred' => []]);
t_ok($f2['src'] === '/images/a.jpg' && A::rank('https://secure.gravatar.com/avatar/x') === 1 && A::rank('https://evil.example/gravatar.com/x') === 0 && A::rank($gen) === 2 && A::rank($foto) === 0, 'dos fotos: la primera; rangos foto 0, Gravatar 1, generico 2');

echo "E) Opcion\n";
$cx = $lee("$root/backend/config.xml");
t_ok(preg_match('/name="customfield_avatar"\s+type="userfieldavatar"\s+addfieldprefix="[^"]+"\s+filter="[^"]*AdminOnlyFilter::customfield_avatar"\s+validate="adminonly"\s+default=""/', $cx) === 1, 'config.xml: campo cerrado, solo core.admin (filtro + validate adminonly), por defecto «Ninguno»');
t_ok(str_contains($lee("$root/backend/src/Helper/SettingsSchema.php"), "'customfield_avatar', 'filter_mode'") && str_contains($lee("$root/backend/src/Helper/SettingsLayout.php"), "[\$c, 'customfield_avatar']"), 'en ADMIN_ONLY y en el panel moderno');
t_ok(str_contains($lee("$root/backend/src/Helper/AdminOnlyFilter.php"), "AvatarFieldList::available()[\$id]"), 'el filtro del servidor solo acepta un campo existente que pueda llevar foto');
t_ok(str_contains($lee("$root/backend/src/Helper/AvatarFieldList.php"), "->whereIn(\$db->quoteName('type'), \$types, ParameterType::STRING)") && str_contains($lee("$root/backend/src/Helper/AvatarFieldList.php"), "->bind(':ctx'"), 'la lista de campos: consulta con parametros ligados');
foreach (['es-ES', 'en-GB'] as $lg) { $ini = $lee("$root/backend/language/$lg/com_engage.sys.ini"); foreach (['LABEL', 'DESC', 'NONE'] as $k) { t_ok(str_contains($ini, "COM_ENGAGE_CONFIG_CUSTOMFIELD_AVATAR_$k=\""), "$lg: $k"); } }
t_ok(is_file(dirname($root, 2) . '/docs/AVATARES.md'), 'docs/AVATARES.md');
echo "F) 0.9.9: la foto que depende del espectador no sale de la pagina interactiva\n";
$av = $lee("$root/backend/src/Helper/Avatar.php"); $em = $lee(dirname($root) . '/plugins/engage/email/src/Extension/Email.php');
t_ok(str_contains($av, "CONTEXT_PAGE = 'page'") && str_contains($av, "STATIC_CONTEXTS = ['email', 'static']") && str_contains($av, 'if ($context !== self::CONTEXT_PAGE)'), 'Avatar: contexto «page» (interactivo) y los demas (email, static) sin plugins');
$i = strpos($av, 'if ($context !== self::CONTEXT_PAGE)'); $j = strpos($av, '// Get the user and the normalised user ID.');
t_ok($i !== false && $j !== false && $i < $j && !str_contains(substr($av, $i, $j - $i), 'getAvatarFromPluginEvents') && str_contains(substr($av, $i, $j - $i), 'getAvatarFromCustomField'), 'en contexto no interactivo se sale ANTES de consultar a los plugins; solo el campo de usuario validado');
t_ok(str_contains($av, 'new Event($eventName, [$user, $size, self::CONTEXT_PAGE])'), 'el evento lleva el contexto como tercer argumento');
t_ok(str_contains($em, "Avatar::getUserAvatar(\$commentUser->id, 48, \$commentUser->email, 'email')"), 'el correo pide el avatar en contexto «email»');
t_ok(!preg_match('/getUserAvatar(Data)?\([^;]*\)/', $lee(dirname($root) . '/component/backend/src/Helper/EmailPreview.php')), 'la vista previa y el correo de prueba no piden ningun avatar (AVATAR_URL vacio)');
$pa = $m->invoke(null, ['results' => ['/index.php?option=com_mifoto&task=photo.show&u=0123456789abcdef&s=64&v=00000000', $gen], 'deferred' => $def]);
t_ok($pa['src'] === '/index.php?option=com_mifoto&task=photo.show&u=0123456789abcdef&s=64&v=00000000' && $pa['deferred'] === '' && A::rank($pa['src']) === 0, 'una URL del sitio SIN extension y con parametros (como MiFoto) es una foto: gana a Gravatar y al generico, sin referencia diferida');
$pb = $m->invoke(null, ['results' => [$gen, '/sub/index.php?option=com_mifoto&task=photo.show&u=0123456789abcdef&s=64&v=00000000'], 'deferred' => $def]);
t_ok(str_starts_with($pb['src'], '/sub/index.php?option=com_mifoto') && $pb['deferred'] === '', 'con subcarpeta y en el otro orden, igual');
t_ok(A::rank('/index.php?x=/media/com_engage/images/avatar-generico.svg') === 0 && A::rank('/media/com_engage/images/avatar-generico.svg') === 2, 'el nombre del generico en la consulta de una URL no la convierte en generica');
t_fin();
