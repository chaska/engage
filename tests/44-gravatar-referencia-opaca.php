<?php
/**
 * 0.8.3 (H4): en modo ask el HTML publico llevaba `data-engage-gravatar="https://www.gravatar.com/avatar/<sha256(correo)>..."` de cada comentarista, tambien de
 * los invitados, ANTES de que nadie consintiera. Ahora lleva una referencia opaca `<id>.<tamano>.<token>` (HMAC con el secreto del sitio) y la URL real solo la
 * da un endpoint propio (POST + token CSRF, ACL de ver el comentario, limite de frecuencia) tras el consentimiento. Ejecuta el codigo REAL de AvatarResolver
 * y de Reactions con un almacen simulado.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src/component';
require_once "$root/backend/src/Helper/ReactionStoreInterface.php";
require_once "$root/backend/src/Helper/Reactions.php";
require_once "$root/backend/src/Helper/AvatarResolver.php";

use Akeeba\Component\Engage\Administrator\Helper\AvatarResolver as R;
use Akeeba\Component\Engage\Administrator\Helper\ReactionStoreInterface;

$secret = 'secreto-del-sitio-' . str_repeat('x', 20);
$tok = fn(int $id, int $size = 48) => R::token($secret, $id, $size);
$ref = fn(int $id, int $size = 48) => "$id.$size." . $tok($id, $size);

class FakeStore implements ReactionStoreInterface
{
	public function __construct(public array $rows) {}
	public function comments(array $ids): array { $o = []; foreach ($ids as $i) { if (isset($this->rows[$i])) { $o[$i] = $this->rows[$i] + ['id' => $i]; } } return $o; }
	public function userTypes(int $userId, array $commentIds): array { return []; }
	public function counts(array $commentIds): array { return []; }
	public function add(int $commentId, int $userId, int $type, string $created): void {}
	public function remove(int $commentId, int $userId, int $type): void {}
	public function transaction(callable $fn): void { $fn(); }
	public function lockComment(int $commentId): bool { return true; }
}
$rows = [
	1 => ['asset_id' => 10, 'enabled' => 1, 'created_by' => 0, 'email' => 'ana@example.com', 'parent_id' => 0],
	2 => ['asset_id' => 10, 'enabled' => 0, 'created_by' => 0, 'email' => 'oculto@example.com', 'parent_id' => 0],
	3 => ['asset_id' => 10, 'enabled' => -3, 'created_by' => 0, 'email' => 'spam@example.com', 'parent_id' => 0],
	4 => ['asset_id' => 11, 'enabled' => 1, 'created_by' => 0, 'email' => 'restringido@example.com', 'parent_id' => 0],
	5 => ['asset_id' => 10, 'enabled' => 1, 'created_by' => 0, 'email' => 'respuesta@example.com', 'parent_id' => 2],
	6 => ['asset_id' => 10, 'enabled' => 1, 'created_by' => 7, 'email' => '', 'parent_id' => 1],
];
$gr = fn(string $email, int $size) => 'https://www.gravatar.com/avatar/' . hash('sha256', strtolower($email)) . '?s=' . $size . '&r=g&d=mp';
$ctx = function (array $o = []) use ($rows, $secret, $gr) {
	return $o + [
		'store' => new FakeStore($rows), 'secret' => $secret, 'canViewAsset' => fn(int $a) => $a === 10,
		'allowRead' => null, 'urlFor' => fn(array $c, int $s) => $gr(((int) $c['created_by'] > 0) ? 'usuario7@example.com' : (string) $c['email'], $s),
	];
};
$res = fn(array $ctx, $raw) => (new R())->resolve($ctx, $raw);

echo "A) Referencia y token\n";
t_ok(preg_match('/^1\.48\.[0-9a-f]{32}$/D', R::reference($secret, 1, 48)) === 1, 'la referencia es <id>.<tamano>.<token de 32 hex>');
t_ok(R::reference('', 1, 48) === '' && R::reference($secret, 0, 48) === '' && R::reference($secret, -1, 48) === '' && R::reference($secret, 1, 0) === '' && R::reference($secret, 1, 5000) === '', 'sin secreto, o con id o tamano no validos: no hay referencia');
t_ok($tok(1) === $tok(1) && $tok(1) !== $tok(2) && $tok(1, 48) !== $tok(1, 64) && R::token('otro-secreto', 1, 48) !== $tok(1), 'el token depende del id, del tamano y del secreto');
$h = R::reference($secret, 1, 48);
t_ok(stripos($h, hash('sha256', 'ana@example.com')) === false && stripos($h, 'ana') === false && stripos($h, md5('ana@example.com')) === false, 'la referencia no contiene nada del correo ni de su hash');
t_ok(count(array_unique(array_map(fn($i) => $tok($i), range(1, 200)))) === 200, '200 comentarios, 200 tokens distintos (sin colisiones)');

echo "B) Lectura de la peticion\n";
t_ok(R::parse($ref(1)) === ['1.48' => [1, 48, $tok(1)]], 'una referencia valida');
t_ok(array_keys(R::parse($ref(1) . ',' . $ref(2) . ',' . $ref(1))) === ['1.48', '2.48'], 'varias, sin repetidas');
foreach ([null, '', [], ['x'], 1, '1', '1.48', '1.48.' . str_repeat('a', 31), '1.48.' . str_repeat('A', 32), '1.48.' . str_repeat('g', 32), '0.48.' . $tok(1), '01.48.' . $tok(1), '1.0.' . $tok(1), '1.5000.' . $tok(1), '1.48.' . $tok(1) . 'x', ' 1.48.' . $tok(1), $ref(1) . ',', ',' . $ref(1), $ref(1) . ",x", "1.48." . $tok(1) . "\n", '-1.48.' . $tok(1), '1e2.48.' . $tok(1), str_repeat('1', 19) . '.48.' . $tok(1)] as $mal) {
	t_ok(R::parse($mal) === null, 'rechazada entera: ' . json_encode(is_string($mal) ? substr($mal, 0, 50) : $mal));
}
$muchas = implode(',', array_map(fn($i) => $ref($i), range(1, 101)));
t_ok(R::parse($muchas) === null && R::parse(implode(',', array_map(fn($i) => $ref($i), range(1, 100)))) !== null, 'mas de 100 referencias: se rechaza; 100: se acepta');

echo "C) Quien recibe la URL\n";
$r = $res($ctx(), $ref(1) . ',' . $ref(2) . ',' . $ref(3) . ',' . $ref(4) . ',' . $ref(5) . ',' . $ref(6) . ',' . $ref(99));
$u = (array) $r['body']['urls'];
t_ok($r['status'] === 200 && $r['body']['ok'] === true, 'respuesta 200');
t_ok($u['1.48'] === $gr('ana@example.com', 48), 'comentario publicado de contenido visible: su URL de Gravatar');
t_ok($u['6.48'] === $gr('usuario7@example.com', 48), 'comentario de usuario registrado: la URL de la cuenta (la dice el servidor, no la peticion)');
foreach (['2.48' => 'sin publicar', '3.48' => 'spam', '4.48' => 'contenido que el visitante no puede ver', '5.48' => 'respuesta cuyo padre esta sin publicar', '99.48' => 'que no existe'] as $k => $porque) {
	t_ok(array_key_exists($k, $u) && $u[$k] === null, "comentario $porque: null");
}
t_ok(array_keys($u) === ['1.48', '2.48', '3.48', '4.48', '5.48', '6.48', '99.48'], 'una entrada por referencia pedida, en el mismo orden');
$json = json_encode($r['body']);
t_ok(stripos($json, 'oculto@') === false && stripos($json, 'spam@') === false && stripos($json, 'restringido@') === false && stripos($json, hash('sha256', 'oculto@example.com')) === false && stripos($json, hash('sha256', 'respuesta@example.com')) === false, 'la respuesta no contiene nada de los comentarios no visibles');
// Respuesta identica para «no existe», «no visible» y «token malo»
$a = $res($ctx(), $ref(99)); $b = $res($ctx(), $ref(2)); $c = $res($ctx(), '1.48.' . str_repeat('0', 32));
t_ok(json_encode($a['body']) === str_replace('2.48', '99.48', json_encode($b['body'])) && $a['status'] === $b['status'] && (array) $c['body']['urls'] === ['1.48' => null] && $c['status'] === 200, 'inexistente, no visible y token falso dan la misma respuesta (null, 200): no revelan nada');
$r = $res($ctx(), '1.64.' . $tok(1, 48));
t_ok((array) $r['body']['urls'] === ['1.64' => null], 'un token de otro tamano no vale (no se puede cambiar el tamano de la peticion)');
$r = $res($ctx(), '2.48.' . $tok(1));
t_ok((array) $r['body']['urls'] === ['2.48' => null], 'el token de un comentario no vale para otro');
$r = $res($ctx(['secret' => 'otro-secreto']), $ref(1));
t_ok((array) $r['body']['urls'] === ['1.48' => null], 'con otro secreto del sitio las referencias ya no valen');
$r = $res($ctx(['secret' => '']), $ref(1));
t_ok($r['status'] === 200 && (array) $r['body']['urls'] === ['1.48' => null], 'sin secreto: todo null');
$r = $res($ctx(['canViewAsset' => fn() => false]), $ref(1));
t_ok((array) $r['body']['urls'] === ['1.48' => null], 'el visitante no puede ver el contenido: null');
$calls = 0; $r = $res($ctx(['canViewAsset' => function ($a) use (&$calls) { $calls++; return true; }]), $ref(1) . ',' . $ref(6));
t_ok($calls === 1, 'la ACL del contenido se evalua una vez por contenido (no por comentario)');
$r = $res($ctx(['urlFor' => fn() => 'https://evil.example/x.png']), $ref(1));
t_ok((array) $r['body']['urls'] === ['1.48' => null], 'una URL que no cumple la lista blanca de Gravatar nunca sale');
foreach (["https://www.gravatar.com/avatar/" . str_repeat('a', 64) . "\"onerror=\"x", 'javascript:alert(1)', 'http://www.gravatar.com/avatar/' . str_repeat('a', 64), 'https://www.gravatar.com.evil.example/avatar/' . str_repeat('a', 64)] as $mala) {
	$r = $res($ctx(['urlFor' => fn() => $mala]), $ref(1));
	t_ok((array) $r['body']['urls'] === ['1.48' => null], 'lista blanca rechaza ' . substr($mala, 0, 50));
}
$r = $res($ctx(['urlFor' => function () { throw new RuntimeException('x'); }]), $ref(1));
t_ok($r['status'] === 500 && $r['body'] === ['ok' => false, 'error' => 'failed'], 'un fallo interno: 500 generico, sin detalles');

echo "D) Peticiones no validas y limite de frecuencia\n";
foreach ([null, '', 'x', '1.48'] as $mal) { $r = $res($ctx(), $mal); t_ok($r['status'] === 400 && $r['body'] === ['ok' => false, 'error' => 'invalid'], '400 generico: ' . json_encode($mal)); }
$permitir = true; $n = 0;
$r = $res($ctx(['allowRead' => function () use (&$permitir, &$n) { $n++; return $permitir; }]), $ref(1));
t_ok($r['status'] === 200 && $n === 1, 'con cupo: 200 y se cuenta una vez');
$permitir = false;
$r = $res($ctx(['allowRead' => function () use (&$permitir) { return $permitir; }]), $ref(1));
t_ok($r['status'] === 429 && $r['body'] === ['ok' => false, 'error' => 'rate'], 'sin cupo: 429');
$n = 0; $r = $res($ctx(['allowRead' => function () use (&$n) { $n++; return true; }]), 'basura');
t_ok($n === 0 && $r['status'] === 400, 'una peticion no valida no gasta cupo');
$rows2 = []; foreach (range(1, 12) as $i) { $rows2[$i] = ['asset_id' => 100 + $i, 'enabled' => 1, 'created_by' => 0, 'email' => "u$i@example.com", 'parent_id' => 0]; }
$r = $res($ctx(['store' => new FakeStore($rows2)]), implode(',', array_map($ref, range(1, 12))));
t_ok($r['status'] === 400, 'mas de 10 contenidos distintos en una peticion: se rechaza entera (como las reacciones)');

echo "E) El codigo\n";
$lee = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
$av = $lee("$root/backend/src/Helper/Avatar.php");
t_ok(preg_match('/function getUserAvatarData\(.*?\?int \$commentId = null, string \$context = self::CONTEXT_PAGE\).*?AvatarResolver::reference\(\$secret, \$commentId, \$size\)/s', $av) === 1, 'Avatar::getUserAvatarData devuelve la referencia opaca, no la URL');
t_ok(preg_match('/function getDeferredURL.*?resolveAvatarData/s', $av) === 1, 'la URL real solo sale por Avatar::getDeferredURL (la usa el endpoint)');
$tpl = $lee("$root/frontend/tmpl/comments/default_list.php");
t_ok(str_contains($tpl, '(int) $comment->id);') && str_contains($tpl, "addScriptOptions('akeeba.Engage.Gravatar.endpoint'"), 'la plantilla pasa el id del comentario y registra el endpoint');
$ct = $lee("$root/frontend/src/Controller/AvatarController.php");
t_ok(preg_match("/in_array\(strtolower\(\(string\) \\\$task\), \['token', 'resolve', 'consent'\], true\).*?403/s", $ct) === 1, 'AvatarController: solo token, resolve y consent');
t_ok(preg_match('/function resolve\(\): void.*?refuseCrossSite\(\).*?!== \'POST\'.*?405.*?Session::checkToken\(\'post\'\).*?403.*?canView\(.*?limiter/s', $ct) === 1, 'resolve: cabecera cross-site, solo POST, token CSRF, ACL (ReactionsController::canView) y limite de frecuencia');
t_ok(str_contains($ct, "header('Cache-Control: no-store, private, max-age=0')") && str_contains($ct, "header('Vary: Cookie')"), 'respuestas sin cache');
$js = $lee("$root/media/js/gravatar.js");
t_ok(preg_match('/\.style\b|cssText|setAttribute\(\s*["\']style|innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function|\.src\s*=/', $js) === 0, 'gravatar.js: sin estilos en linea (.style, cssText, setAttribute style), sin innerHTML/outerHTML/insertAdjacentHTML/document.write/eval y sin asignar .src');
t_ok(substr_count($js, 'textContent') >= 3, 'gravatar.js: los textos del aviso se ponen con textContent');
t_ok(preg_match('/fetch\(/', $js) === 1 || substr_count($js, 'window.fetch(') >= 2, 'gravatar.js: solo habla con el propio sitio (fetch a la ruta del endpoint, validada: empieza por una barra, no por //)');
t_ok(str_contains($js, 'base.charAt(0) !== "/" || base.charAt(1) === "/"'), 'gravatar.js: el endpoint tiene que ser una ruta del propio sitio');
t_ok(is_file("$root/media/css/comments.css") && !preg_match('#gravatar\.com|data-engage-gravatar\s*=\s*"https#i', (string) file_get_contents("$root/media/css/comments.css")), 'comments.css: sin referencias a gravatar.com');
t_fin();
