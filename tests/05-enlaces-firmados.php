<?php
/**
 * 0.4.3: los enlaces firmados (SignedURL) atan el ID del comentario (S5), usan HMAC-SHA-256, y
 * ControllerFrontendCommentsTrait::checkToken solo acepta el enlace firmado para UN comentario.
 */
namespace {
	require __DIR__ . '/aserciones.php';
	require __DIR__ . '/vendor/autoload.php';
	require __DIR__ . '/stubs/joomla.php';
}

namespace Joomla\CMS\Uri { class Uri extends \Joomla\Uri\Uri {} }
namespace Joomla\CMS\Application { class CMSApplication {} }
namespace Joomla\Utilities {
	class ArrayHelper { public static function toInteger($a) { return array_map('intval', (array) $a); } }
}
namespace Akeeba\Component\Engage\Administrator\Service { class CacheCleaner {} }
namespace Akeeba\Component\Engage\Administrator\Table { class CommentTable {} }

namespace {
	/** Entrada falsa: $request = $_REQUEST (mezclado), $get = solo $_GET. */
	class FInput
	{
		public $get;
		public function __construct(public array $request, array $get) { $this->get = new FInputGet($get); }
		public function get($n, $d = null, $f = null) { return $this->request[$n] ?? $d; }
		public function getInt($n, $d = 0) { return (int) ($this->request[$n] ?? $d); }
	}
	class FInputGet
	{
		public function __construct(public array $d) {}
		public function get($n, $d = null, $f = null) { return $this->d[$n] ?? $d; }
		public function getString($n, $d = null) { return isset($this->d[$n]) ? (string) $this->d[$n] : $d; }
		public function getInt($n, $d = null) { return isset($this->d[$n]) ? (int) $this->d[$n] : $d; }
		public function getCmd($n, $d = null) { return isset($this->d[$n]) ? preg_replace('/[^A-Z0-9_.-]/i', '', (string) $this->d[$n]) : $d; }
	}
	class FTable
	{
		public $asset_id = 77;
		public function load($id) { $this->id = $id; return true; }
	}
	class BaseCtl { public function checkToken($method = 'request', $redirect = true): bool { return false; /* ruta del token de formulario */ } }
}

namespace {
	$root = dirname(__DIR__) . '/src/component';
	require "$root/frontend/src/Helper/SignedURL.php";
	require "$root/frontend/src/Mixin/ControllerFrontendCommentsTrait.php";
	use Akeeba\Component\Engage\Site\Helper\SignedURL;

	class Ctl extends BaseCtl
	{
		use \Akeeba\Component\Engage\Site\Mixin\ControllerFrontendCommentsTrait;
		public $input;
		public function getModel() { return new class { public function getTable() { return new FTable(); } }; }
	}

	function ctl(array $get, ?array $request = null): Ctl
	{
		$c = new Ctl();
		$c->input = new FInput($request ?? $get, $get);
		return $c;
	}
	/** Convierte la URL firmada en el array $_GET que PHP produciría. */
	function query(string $url): array
	{
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		return $q;
	}

	$comment = (object) ['id' => 5, 'asset_id' => 77];
	$otro    = (object) ['id' => 6, 'asset_id' => 77];
	$email   = 'mod@example.com';
	$url     = SignedURL::getSignedURL('index.php?option=com_engage&task=comments.publish', $comment, $email);
	$q       = query($url);

	t_ok(strlen($q['token']) === 64 && ctype_xdigit($q['token']), 'el token es HMAC-SHA-256 (64 hex)');
	t_ok($q['cid'] === ['5'], 'la URL lleva cid[]=5');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '77', (int) $q['expires'], 5) === true, 'verifyToken acepta el token propio');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '77', (int) $q['expires'], 6) === false, 'verifyToken rechaza el mismo token para otro comentario (cid 6)');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '77', (int) $q['expires'], null) === false, 'verifyToken rechaza si no se indica el cid');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '77', (int) $q['expires'], 0) === false, 'verifyToken rechaza cid 0');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.delete', $email, '77', (int) $q['expires'], 5) === false, 'rechaza otra tarea');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', 'otro@example.com', '77', (int) $q['expires'], 5) === false, 'rechaza otro correo');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '78', (int) $q['expires'], 5) === false, 'rechaza otro asset_id');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '77', (int) $q['expires'] + 1, 5) === false, 'rechaza otra caducidad');
	t_ok(SignedURL::verifyToken(null, null, null, null, null, null) === false, 'todo null -> false (sin TypeError)');
	$viejo = hash_hmac('sha1', 'comments.publish-' . $email . '-77-' . $q['expires'], 'secreto-de-prueba');
	t_ok(SignedURL::verifyToken($viejo, 'comments.publish', $email, '77', (int) $q['expires'], 5) === false, 'un token del formato anterior (SHA-1 sin cid) ya no vale');
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '77', time() - 10, 5) === false, 'caducado -> false');
	$GLOBALS['T_SECRET'] = 'otro-secreto';
	t_ok(SignedURL::verifyToken($q['token'], 'comments.publish', $email, '77', (int) $q['expires'], 5) === false, 'otra clave secreta -> false');
	$GLOBALS['T_SECRET'] = 'secreto-de-prueba';

	echo "checkToken del controlador\n";
	$base = $q;
	t_ok(ctl($base)->checkToken() === true, 'enlace firmado legítimo: aceptado');

	$x = $base; $x['cid'] = ['5', '6'];
	t_ok(ctl($x)->checkToken() === false, 'cid[]=5&cid[]=6 con el token de 5: rechazado (cae al token de formulario)');
	$x = $base; $x['cid'] = ['6'];
	t_ok(ctl($x)->checkToken() === false, 'cid[]=6 con el token de 5: rechazado');
	$x = $base; $x['id'] = '9';
	t_ok(ctl($x)->checkToken() === false, 'id=9 añadido al enlace de 5: rechazado');
	$x = $base; $x['id'] = '5';
	t_ok(ctl($x)->checkToken() === true, 'id=5 coincidente con cid[]=5: aceptado');
	t_ok(ctl($base, array_merge($base, ['cid' => ['5', '6']]))->checkToken() === false, 'POST/REQUEST con cid[] extra (GET legítimo): rechazado');
	$x = $base; $x['cid'] = [['5']];
	t_ok(ctl($x)->checkToken() === false, 'cid[][]: rechazado');
	$x = $base; $x['cid'] = ['5abc'];
	t_ok(ctl($x)->checkToken() === false, 'cid no numérico: rechazado');
	$x = $base; unset($x['token']);
	t_ok(ctl($x)->checkToken() === false, 'sin token: rechazado');
	$x = $base; $x['task'] = 'comments.delete';
	t_ok(ctl($x)->checkToken() === false, 'cambiando la tarea a delete: rechazado');
	$q6 = query(SignedURL::getSignedURL('index.php?option=com_engage&task=comments.publish', $otro, $email));
	$x = $base; $x['token'] = $q6['token'];
	t_ok(ctl($x)->checkToken() === false, 'token del comentario 6 usado en el 5: rechazado');
	$q6['cid'] = ['6'];
	t_ok(ctl($q6)->checkToken() === true, 'el enlace del comentario 6 vale para el 6');

	// Email: los enlaces de moderación solo se firman para quien tiene permiso
	$email = file_get_contents($root . '/../plugins/engage/email/src/Extension/Email.php');
	t_ok(preg_match_all('/\$can(State|Delete) \? SignedURL::getAbsoluteSignedURL/', $email) === 6, 'Email.php: 6 enlaces de moderación condicionados a core.edit.state / core.delete');
	t_ok(strpos($email, "'UNSUBSCRIBE_URL'  => SignedURL::getAbsoluteSignedURL") !== false, 'Email.php: el enlace de baja se firma para todos');
	t_fin();
}
