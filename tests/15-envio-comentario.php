<?php
/**
 * 0.6.8: mass assignment en el envío de comentarios (PS-08/PS-17). Ejecuta el validate() REAL de
 * Site\Model\CommentModel con stubs de Joomla, de la tabla y de Meta, y comprueba:
 *  - comentario nuevo: id forzado a 0, asset_id/parent_id solo enteros, campos del servidor descartados;
 *  - parent_id: debe existir, ser del mismo asset y estar publicado (salvo moderadores); mismo mensaje en todos los fallos;
 *  - comentario existente: asset_id y parent_id los fija el servidor (no se puede mover ni re-colgar).
 */
namespace Joomla\CMS\Language { class Text { public static function _($k) { return $k; } public static function sprintf($k, ...$a) { return $k; } } }
namespace Joomla\CMS\Component { class ComponentHelper { public static function getParams($n) { return new class { public function get($k, $d = null) { return $d; } }; } } }
namespace Akeeba\Component\Engage\Administrator\Table {
	class CommentTable
	{
		public $id, $asset_id, $parent_id, $enabled;
		public function load($id) {
			$r = $GLOBALS['ROWS'][(int) $id] ?? null;
			if (!$r) { return false; }
			foreach ($r as $k => $v) { $this->$k = $v; }
			return true;
		}
	}
}
namespace Akeeba\Component\Engage\Administrator\Helper {
	class UserFetcher { public static function getUser() { return $GLOBALS['USER']; } }
}
namespace Akeeba\Component\Engage\Site\Helper {
	class Meta
	{
		public static function getAssetAccessMeta($id, $p = false) {
			$ok = in_array((int) $id, [10, 20], true);
			return ['published' => $ok, 'access' => null, 'parent_access' => null, 'parameters' => new \stdClass()];
		}
		public static function areCommentsClosed($id) { return false; }
	}
}
namespace Akeeba\Component\Engage\Administrator\Model {
	class CommentModel
	{
		public $error;
		public function setError($e) { $this->error = $e; }
		public function validate($form, $data, $group = null) { return $data; }
		public function getTable($n = '', $p = '') { return new \Akeeba\Component\Engage\Administrator\Table\CommentTable(); }
	}
}
namespace {
	define('_JEXEC', 1);
	require __DIR__ . '/aserciones.php';
	require dirname(__DIR__) . '/src/component/frontend/src/Model/CommentModel.php';
	use Akeeba\Component\Engage\Site\Model\CommentModel;

	function usuario(bool $mod, bool $guest = true) {
		return new class($mod, $guest) {
			public function __construct(public bool $m, public bool $guest) {}
			public function authorise($a, $b = null) { return $this->m && in_array($a, ['core.manage', 'core.edit.state'], true); }
			public function getAuthorisedViewLevels() { return [1]; }
		};
	}
	$GLOBALS['ROWS'] = [
		3 => ['id' => 3, 'asset_id' => 10, 'parent_id' => null, 'enabled' => 1],
		4 => ['id' => 4, 'asset_id' => 20, 'parent_id' => null, 'enabled' => 1],
		5 => ['id' => 5, 'asset_id' => 10, 'parent_id' => null, 'enabled' => 0],
		6 => ['id' => 6, 'asset_id' => 10, 'parent_id' => null, 'enabled' => -3],
		7 => ['id' => 7, 'asset_id' => 10, 'parent_id' => 3, 'enabled' => 1],
	];
	function v(array $data, bool $mod = false) {
		$GLOBALS['USER'] = usuario($mod);
		$m = new CommentModel();
		$r = $m->validate(null, $data);
		return [$r, $m->error];
	}
	$base = ['asset_id' => '10', 'parent_id' => '0', 'body' => 'x', 'name' => 'a', 'email' => 'a@b.c'];

	echo "A) Comentario nuevo\n";
	[$r] = v($base);
	t_ok(is_array($r) && $r['asset_id'] === 10 && $r['parent_id'] === 0 && $r['id'] === 0, 'envío normal: asset_id=10, parent_id=0, id=0 (enteros)');
	[$r] = v($base + ['enabled' => 1, 'created_by' => 1, 'created' => '2000-01-01', 'modified_by' => 1, 'ip' => '1.1.1.1', 'user_agent' => 'x']);
	t_ok(is_array($r) && array_diff_key($r, array_flip(['asset_id', 'parent_id', 'body', 'name', 'email', 'id'])) === [], 'campos del servidor (enabled, created_by, ip...) descartados: ' . json_encode(array_keys((array) $r)));
	[$r] = v(['parent_id' => '3'] + $base);
	t_ok(is_array($r) && $r['parent_id'] === 3, 'respuesta a comentario publicado del mismo asset: admitida');
	[$r, $e] = v(['parent_id' => '4'] + $base);
	t_ok($r === false && $e === 'COM_ENGAGE_COMMENTS_ERR_INVALID_PARENT', 'padre de OTRO asset: rechazado');
	[$r, $e] = v(['parent_id' => '5'] + $base);
	t_ok($r === false && $e === 'COM_ENGAGE_COMMENTS_ERR_INVALID_PARENT', 'padre sin publicar (invitado): rechazado');
	[$r] = v(['parent_id' => '6'] + $base);
	t_ok($r === false, 'padre spam (invitado): rechazado');
	[$r] = v(['parent_id' => '5'] + $base, true);
	t_ok(is_array($r) && $r['parent_id'] === 5, 'moderador puede responder a un comentario sin publicar');
	[$r, $e2] = v(['parent_id' => '999'] + $base);
	t_ok($r === false && $e2 === $e, 'padre inexistente: mismo mensaje que los demás (sin oráculo)');
	foreach ([['3'], '3; DROP TABLE x', '3abc', '0x3', '1e1', 3.5] as $x) {
		[$r] = v(['parent_id' => $x] + $base);
		t_ok($r === false, 'parent_id basura rechazado: ' . json_encode($x));
	}
	[$r] = v(['parent_id' => '-1'] + $base);
	t_ok(is_array($r) && $r['parent_id'] === 0, 'parent_id negativo = sin padre (como en CommentTable)');
	foreach (['12abc', '99', '', '0', '-1', '3; DROP', ['10']] as $x) {
		[$r] = v(['asset_id' => $x] + $base);
		t_ok($r === false, 'asset_id no válido/no comentable rechazado: ' . json_encode($x));
	}
	[$r] = v(['asset_id' => '20'] + $base);
	t_ok(is_array($r) && $r['asset_id'] === 20, 'asset_id de otro contenido visible y publicado: se acepta (se valida acceso)');

	echo "B) Comentario existente (edición)\n";
	[$r] = v(['id' => '7', 'asset_id' => '20', 'parent_id' => '4', 'body' => 'x']);
	t_ok(is_array($r) && $r['id'] === 7 && $r['asset_id'] === 10 && $r['parent_id'] === 3, 'asset_id y parent_id enviados se ignoran: quedan los almacenados (10 y 3)');
	[$r] = v(['id' => '3', 'asset_id' => '20', 'parent_id' => '7', 'body' => 'x']);
	t_ok(is_array($r) && $r['parent_id'] === 0 && $r['asset_id'] === 10, 'no se puede crear un ciclo ni quitar/poner padre: parent_id forzado al almacenado (0)');
	[$r] = v(['id' => '999', 'asset_id' => '10', 'body' => 'x']);
	t_ok($r === false, 'id inexistente: rechazado');
	foreach (['7abc', '-3', ['7']] as $x) { [$r] = v(['id' => $x, 'asset_id' => '10', 'body' => 'x']); t_ok($r === false, 'id basura rechazado: ' . json_encode($x)); }
	t_fin();
}
