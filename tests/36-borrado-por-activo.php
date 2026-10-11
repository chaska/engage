<?php
/**
 * 0.8.2: Meta::deleteAssetData(), el borrado que debe llamar el componente DUENO de un contenido (Engage solo limpia los articulos).
 * Se ejecuta el codigo REAL con una base de datos falsa que REGISTRA cada consulta y cada parametro ligado: nada recibido llega al SQL,
 * un id invalido no hace ninguna consulta, un fallo deshace la transaccion, y no hay ruta HTTP hasta el metodo.
 * La prueba con MariaDB real (comentarios, reacciones y bajas de aviso de un contenido de otro componente) esta en tests/joomla-live/21-otros-componentes.sh.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/otros-componentes.php';

use Akeeba\Component\Engage\Site\Helper\Meta;

$root = dirname(__DIR__);
require_once $root . '/src/component/frontend/src/Helper/Meta.php';

class FakeQ2
{
	public string $sql = '';
	public array $bind = [];
	public function select($x) { $this->sql .= 'SELECT ' . implode(',', (array) $x) . ' '; return $this; }
	public function from($x) { $this->sql .= 'FROM ' . $x . ' '; return $this; }
	public function delete($x) { $this->sql .= 'DELETE FROM ' . $x . ' '; return $this; }
	public function where($x) { $this->sql .= 'WHERE ' . $x . ' '; return $this; }
	public function bind($k, &$v, $t = null) { $this->bind[$k] = [$v, $t]; return $this; }
	public function __toString(): string { return trim($this->sql); }
}
class FakeDb2
{
	public array $log = [];
	public array $tx = [];
	public bool $fallarEn = false;
	private $q;
	public function createQuery() { return new FakeQ2(); }
	public function qn($n, $as = null) { return '`' . $n . '`' . ($as ? " AS `$as`" : ''); }
	public function setQuery($q) { $this->q = $q; return $this; }
	public function execute() { if ($this->fallarEn && str_contains((string) $this->q, '#__engage_comments` WHERE')) { throw new RuntimeException('fallo simulado'); } $this->log[] = [(string) $this->q, $this->q->bind]; return true; }
	public function getAffectedRows() { return 4; }
	public function transactionStart() { $this->tx[] = 'start'; }
	public function transactionCommit() { $this->tx[] = 'commit'; }
	public function transactionRollback() { $this->tx[] = 'rollback'; }
}

echo "A) Un asset_id valido borra comentarios, reacciones y bajas con parametros ligados\n";
$GLOBALS['T_DB'] = $db = new FakeDb2();
$n = Meta::deleteAssetData(4321);
t_ok($n === 4, 'devuelve el numero de comentarios borrados (4 en la base falsa)');
t_ok(count($db->log) === 3, 'tres consultas: reacciones, comentarios, bajas');
t_ok(str_contains($db->log[0][0], '#__engage_reactions') && str_contains($db->log[0][0], 'IN (SELECT') && str_contains($db->log[0][0], '#__engage_comments'), 'reacciones de los comentarios de ese contenido');
t_ok(str_contains($db->log[1][0], 'DELETE FROM `#__engage_comments`') && str_contains($db->log[2][0], 'DELETE FROM `#__engage_unsubscribe`'), 'comentarios y bajas');
foreach ($db->log as [$sql, $bind]) {
	t_ok(str_contains($sql, ':asset') && !str_contains($sql, '4321') && ($bind[':asset'][0] ?? null) === 4321 && $bind[':asset'][1] === 'int', 'el id va ligado como entero, no pegado al SQL: ' . substr($sql, 0, 40));
}
t_ok($db->tx === ['start', 'commit'], 'una transaccion confirmada');
t_ok(!str_contains(implode(' ', array_column($db->log, 0)), '#__assets'), 'NO toca #__assets (es del componente dueno)');

echo "B) Ids invalidos: -1 y ni una consulta\n";
foreach ([0, -1, -99999, PHP_INT_MIN, 4294967296, PHP_INT_MAX] as $mal) {
	$GLOBALS['T_DB'] = $db = new FakeDb2();
	t_ok(Meta::deleteAssetData($mal) === -1 && $db->log === [] && $db->tx === [], "asset_id $mal: -1 y sin consultas");
}
$GLOBALS['T_DB'] = new FakeDb2();
$r = null;
try { Meta::deleteAssetData('12abc'); } catch (TypeError $e) { $r = 'TypeError'; }
t_ok($r === 'TypeError', 'un texto no numerico ni siquiera entra (firma int): TypeError');

echo "C) Un fallo deshace todo\n";
$GLOBALS['T_DB'] = $db = new FakeDb2();
$db->fallarEn = true;
t_ok(Meta::deleteAssetData(77) === -1 && $db->tx === ['start', 'rollback'], 'fallo a mitad: -1 y rollback, sin commit');

echo "D) Solo se puede llamar desde codigo PHP\n";
$usos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	if ($f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), 'deleteAssetData')) { $usos[] = substr($f->getPathname(), strlen($root) + 1); }
}
t_ok($usos === ['src/component/frontend/src/Helper/Meta.php'], 'ningun controlador, tarea, evento ni plugin la llama: solo existe la definicion (' . implode(', ', $usos) . ')');
$m = (new ReflectionClass(Meta::class))->getMethod('deleteAssetData');
t_ok($m->isPublic() && $m->isStatic() && $m->isFinal() === false, 'metodo publico y estatico de Meta (clase final)');

t_fin();
