<?php
/**
 * 0.8.3 (H1, H5 de la revision independiente de la 0.8.2): un invitado leia CUALQUIER comentario por ID (texto, nombre y CORREO del autor, tambien
 * sin publicar, spam o de contenido restringido) con `view=comment&id=N` o con cualquier tarea desconocida de `comment.*` (cae en `main` sin ACL);
 * `task=comments.display` y `task=display&view=comments` daban 500. Heredado de Akeeba Engage 3.4.2.
 *  A) CommentAccess::requestedId: solo enteros canonicos (id[]=1, signos, decimales, hex, ceros a la izquierda... = 403, no se «interpretan»)
 *  B) CommentAccess::mayOpen: invitado/registrado ajeno/sin permiso = no; core.edit = si; core.edit.own solo en lo propio; id 0 solo con datos de formulario en sesion
 *  C) ControllerEventsTrait: lista cerrada de tareas ANTES de ejecutar nada (la tarea desconocida ya no cae en `__default`)
 *  D) los tres controladores publicos declaran su lista cerrada y la vista/controlador comprueban ACL y guard
 * El Joomla real (HTTP como invitado, Registered y Manager) esta en tests/joomla-live/22-revision-082.sh.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/otros-componentes.php';

use Akeeba\Component\Engage\Site\Helper\CommentAccess;
use Joomla\Input\Input;

$root = dirname(__DIR__);
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once $root . '/src/component/frontend/src/Helper/CommentAccess.php';

// Input falso con la firma de Joomla: get($nombre, $defecto, $filtro)
$in = fn(array $d) => new class($d) { public function __construct(private array $d) {} public function get($k, $def = null, $f = null) { return array_key_exists($k, $this->d) ? $this->d[$k] : $def; } };

echo "A) CommentAccess::requestedId\n";
foreach ([[[], 0], [['id' => ''], 0], [['id' => '0'], 0], [['id' => 0], 0], [['id' => null], 0], [['id' => '7'], 7], [['id' => 12], 12], [['id' => '123456789012345678'], 123456789012345678]] as [$d, $esp]) {
	t_ok(CommentAccess::requestedId($in($d)) === $esp, 'id ' . json_encode($d) . " => $esp");
}
foreach ([[1], ['1'], '01', '-1', '+1', '1.0', '1e2', '0x1A', ' 1', '1 ', "1\n", '1abc', 'abc', '1234567890123456789', '٣', '１', true, 1.5, new stdClass(), ['a' => 1]] as $mal) {
	try { CommentAccess::requestedId($in(['id' => $mal])); t_ok(false, 'rechazado (403): ' . json_encode($mal)); }
	catch (RuntimeException $e) { t_ok($e->getCode() === 403, 'rechazado (403): ' . json_encode($mal)); }
}

echo "B) CommentAccess::mayOpen\n";
$usr = function (bool $guest, int $id, array $perm) { $u = new class extends \Joomla\CMS\User\User { public array $perm = []; public function authorise($a, $b = null) { return in_array($a, $this->perm, true); } }; $u->guest = $guest; $u->id = $id; $u->perm = $perm; return $u; };
$dueno = fn(int $id): ?int => [10 => 5, 11 => 6, 12 => null, 13 => 0][$id] ?? null;
t_ok(CommentAccess::mayOpen(10, null, true, $dueno) === false, 'sin usuario: no');
t_ok(CommentAccess::mayOpen(10, $usr(true, 0, ['core.create']), true, $dueno) === false, 'invitado: no (comentario existente)');
t_ok(CommentAccess::mayOpen(999, $usr(true, 0, []), true, $dueno) === false, 'invitado: no (comentario inexistente: mismo resultado)');
t_ok(CommentAccess::mayOpen(10, $usr(false, 9, ['core.edit.own']), true, $dueno) === false, 'registrado ajeno con core.edit.own: no');
t_ok(CommentAccess::mayOpen(10, $usr(false, 5, []), true, $dueno) === false, 'el autor sin core.edit.own: no');
t_ok(CommentAccess::mayOpen(10, $usr(false, 5, ['core.edit.own']), true, $dueno) === true, 'el autor con core.edit.own: si');
t_ok(CommentAccess::mayOpen(11, $usr(false, 5, ['core.edit.own']), true, $dueno) === false, 'el autor con core.edit.own en OTRO comentario: no');
t_ok(CommentAccess::mayOpen(12, $usr(false, 5, ['core.edit.own']), true, $dueno) === false, 'comentario sin autor (invitado): no se reclama por created_by NULL');
t_ok(CommentAccess::mayOpen(13, $usr(false, 5, ['core.edit.own']), true, $dueno) === false, 'created_by 0: no');
t_ok(CommentAccess::mayOpen(10, $usr(false, 77, ['core.edit']), true, $dueno) === true, 'core.edit: si (cualquier comentario)');
t_ok(CommentAccess::mayOpen(-3, $usr(false, 77, ['core.edit']), true, $dueno) === false, 'id negativo: no');
t_ok(CommentAccess::mayOpen(0, $usr(true, 0, []), false) === false, 'id 0 sin datos de formulario en sesion: no (antes: formulario vacio para todos)');
t_ok(CommentAccess::mayOpen(0, $usr(true, 0, []), true) === true, 'id 0 con datos de formulario en sesion (tras un guardado fallido): si');
t_ok(CommentAccess::mayOpen(10, $usr(false, 5, ['core.edit.own']), true, function () { throw new RuntimeException('bd'); }) === false, 'error al consultar al autor: no (fail-closed)');

echo "C) ControllerEventsTrait: la lista cerrada manda antes que nada\n";
require_once $root . '/src/component/backend/src/Mixin/TriggerEventTrait.php';
require_once $root . '/src/component/backend/src/Mixin/ControllerEventsTrait.php';
class T_Ctl
{
	use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
	public $task; public $doTask; public $taskMap = ['__default' => 'display', 'save' => 'save', 'getmodel' => 'getModel']; public array $log = [];
	public ?array $lista = ['main', 'save'];
	protected function triggerEvent(string $e, array $a = []): bool { $this->log[] = $e; return true; }
	protected function engageAllowedTasks(): ?array { return $this->lista; }
	public function display() { $this->log[] = 'display'; return 'D'; }
	public function save() { $this->log[] = 'save'; return 'S'; }
	public function getModel() { $this->log[] = 'getModel'; return 'M'; }
}
$c = new T_Ctl();
t_ok($c->execute('main') === 'D' && $c->execute('save') === 'S', 'las tareas de la lista funcionan (main -> default, save)');
foreach (['loadhistory', 'runTransition', 'getModel', 'GETMODEL', 'display', 'foo', 'Batch', ''] as $t) {
	$c = new T_Ctl();
	try { $c->execute($t); t_ok(false, "tarea «{$t}» rechazada"); }
	catch (RuntimeException $e) { t_ok($e->getCode() === 403 && !in_array('display', $c->log, true) && !in_array('getModel', $c->log, true) && $c->log === [], "tarea «{$t}» = 403 sin ejecutar ni disparar eventos"); }
}
$c = new T_Ctl(); $c->lista = null;
t_ok($c->execute('foo') === 'D', 'sin lista (controladores del panel) el comportamiento no cambia');

echo "D) los controladores y las vistas\n";
$cc  = $lee("$root/src/component/frontend/src/Controller/CommentController.php");
$cs  = $lee("$root/src/component/frontend/src/Controller/CommentsController.php");
$rc  = $lee("$root/src/component/frontend/src/Controller/ReactionsController.php");
$vc  = $lee("$root/src/component/frontend/src/View/Comment/HtmlView.php");
$vs  = $lee("$root/src/component/frontend/src/View/Comments/HtmlView.php");
t_ok(preg_match("/engageAllowedTasks\(\): \?array\s*\{\s*return \['main', 'edit', 'save'\];/", $cc) === 1, 'CommentController: solo main, edit y save');
t_ok(preg_match("/engageAllowedTasks\(\): \?array\s*\{\s*return \['main', 'publish', 'unpublish', 'delete', 'possiblespam', 'reportham', 'reportspam', 'unsubscribe'\];/", $cs) === 1, 'CommentsController: main + las 7 acciones de moderacion');
t_ok(preg_match("/function execute\(\\\$task\).*?in_array\(strtolower\(\(string\) \\\$task\), \['toggle', 'state'\], true\).*?403/s", $rc) === 1, 'ReactionsController: solo toggle y state');
t_ok(str_contains($cc, 'public function display(') && str_contains($cc, 'CommentAccess::requestedId($this->input)') && str_contains($cc, 'CommentAccess::mayOpen('), 'CommentController::display comprueba el ID y la ACL');
t_ok(str_contains($cc, 'public function batch($model = null)'), 'CommentController::batch: firma compatible con la llamada sin argumentos (antes 500)');
t_ok(str_contains($vc, 'CommentAccess::mayOpen('), 'la vista Comment vuelve a comprobar la ACL (cinturon y tirantes)');
t_ok(preg_match('/public function display\(\$cachable = false, \$urlparams = \[\]\)\s*\{\s*[^}]*?assertCalledByThePlugin\(\);/s', $cs) === 1, 'CommentsController::display lleva el guard del plugin (task=comments.display ya no llega al TypeError)');
t_ok(substr_count($cs, 'assertCalledByThePlugin()') >= 3, 'el guard se llama desde display y onBeforeMain (y esta definido una vez)');
t_ok(preg_match('/public function display\(\$tpl = null\)\s*\{\s*[^}]*?is_int\(\$this->assetId\)[^}]*?403/s', $vs) === 1, 'la vista Comments responde 403 si no tiene asset_id (nunca un TypeError)');
t_fin();
