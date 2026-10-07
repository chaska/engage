<?php
/**
 * 0.8.0: herramientas de la lista de comentarios del sitio (selector de orden, «solo mis favoritos», copiar enlace, insignias).
 * Sin Joomla: logica pura (ListOrdering, CommentTools), el modelo real con una base de datos que registra las consultas y comprobaciones
 * estaticas de plantillas, JavaScript, CSS, opciones e idiomas. Lo que necesita Joomla de verdad esta en tests/joomla-live/19-tanda2.sh.
 *
 * Ataques: valores raros en el parametro de orden y en el de favoritos (cadenas, arrays, SQL, saltos de linea, unicode), URL hostiles para el
 * enlace permanente, usuario invitado, favoritos ajenos, limites.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';

$root = dirname(__DIR__);
$c    = $root . '/src/component';
$lee  = fn(string $f): string => (string) file_get_contents($f);
$lf   = fn(string $f): string => str_replace("\r\n", "\n", $lee($f));

require "$c/backend/src/Helper/ListOrdering.php";
require "$c/backend/src/Helper/CommentTools.php";
require "$c/backend/src/Helper/Reactions.php";
require "$c/backend/src/Helper/SettingsSchema.php";
require "$c/backend/src/Helper/SettingsLayout.php";

use Akeeba\Component\Engage\Administrator\Helper\CommentTools as T;
use Akeeba\Component\Engage\Administrator\Helper\ListOrdering as L;
use Akeeba\Component\Engage\Administrator\Helper\Reactions as R;
use Akeeba\Component\Engage\Administrator\Helper\SettingsLayout as SL;
use Akeeba\Component\Engage\Administrator\Helper\SettingsSchema as SS;

$ataques = [
	'', ' ', 'NEWEST', 'Newest', 'newest ', ' newest', "newest\n", "newest\r\n", "newest\0", "newest\n--", 'newest;DROP TABLE x', "newest' OR '1'='1", 'newest,u.password',
	'c.created', 'c.created DESC', 'reaction_score', 'u.password', '1', 'RAND()', '(SELECT 1)', 'top-', 'newе' . 'st' /* e cirilica */, "n\u{0435}west", 'newest' . "\u{200B}",
	'ｎｅｗｅｓｔ' /* anchura completa */, 'newest/*x*/', '../../etc/passwd', '<script>alert(1)</script>', str_repeat('a', 5000), str_repeat('newest', 1000),
	null, true, false, 0, 1, 1.5, [], ['newest'], ['top' => 1], new stdClass,
];

echo "A) ListOrdering: modos de orden del visitante (lista cerrada, comparacion estricta)\n";
foreach (['newest', 'oldest', 'top'] as $v) { t_ok(L::sortMode($v) === $v, "sortMode('$v') se acepta"); }
$malos = 0;
foreach ($ataques as $a) { if (L::sortMode($a) !== null) { $malos++; echo '        aceptado: ' . json_encode($a) . "\n"; } }
t_ok($malos === 0, 'sortMode rechaza ' . count($ataques) . ' valores de ataque (mayusculas, espacios, saltos de linea, NUL, SQL, unicode parecido, arrays, objetos, tipos)');
t_ok(L::SORT_MODES === ['newest', 'oldest', 'top'], 'la lista de modos es cerrada y tiene exactamente 3');
t_ok(L::sortOrderBy('newest') === [['c.created', 'DESC'], ['c.id', 'DESC']] && L::sortOrderBy('oldest') === [['c.created', 'ASC'], ['c.id', 'ASC']], 'newest / oldest: fecha e id, direccion fija');
t_ok(L::sortOrderBy('top') === [['reaction_score', 'DESC'], ['c.created', 'DESC'], ['c.id', 'DESC']], 'top: valoracion, fecha e id (desempate por fecha, el mas reciente primero)');
foreach ($ataques as $a) { if (is_string($a)) { if (L::sortOrderBy($a) !== [] ) { $malos++; } } }
t_ok($malos === 0, 'sortOrderBy de cualquier otra cadena da una lista vacia (nada llega al SQL)');
foreach (['auto', 'newest', 'oldest', 'top'] as $v) { t_ok(L::defaultSort($v) === $v, "defaultSort('$v')"); }
$malos = 0;
foreach ($ataques as $a) { if (L::defaultSort($a) !== 'auto') { $malos++; } }
t_ok($malos === 0, 'defaultSort: todo valor ajeno vale auto');
// El ORDER BY de los modos solo usa columnas de la tabla fija
$todasColumnas = [];
foreach (L::SORT_MODES as $m) { foreach (L::sortOrderBy($m) as [$col, $dir]) { $todasColumnas[$col] = true; t_ok(in_array($dir, ['ASC', 'DESC'], true), "direccion de $m/$col fija"); } }
t_ok(array_keys($todasColumnas) === ['c.created', 'c.id', 'reaction_score'], 'columnas posibles del orden: c.created, c.id y el alias reaction_score');
t_ok(L::column('reaction_score') === 'c.created', 'el alias reaction_score NO esta en la lista del panel (no se puede pedir por list[fullordering])');
t_ok(L::frontendColumn('reaction_score') === 'c.created', 'ni en la del listado publico (akengage_order)');

echo "B) CommentTools::options y resolve()\n";
$def = T::options([]);
t_ok($def === ['sort_selector' => true, 'default_sort' => 'auto', 'copy_link' => true, 'show_badges' => true], 'valores por defecto: selector, enlace e insignias activos; orden inicial automatico');
$o = T::options(['sort_selector' => '0', 'copy_link' => 0, 'show_badges' => '0', 'default_sort' => 'top']);
t_ok($o === ['sort_selector' => false, 'default_sort' => 'top', 'copy_link' => false, 'show_badges' => false], 'se puede apagar todo');
$o = T::options(['sort_selector' => 'yes', 'copy_link' => [1], 'show_badges' => '2', 'default_sort' => ['top']]);
t_ok($o === $def, 'valores ajenos (yes, array, 2) vuelven al valor por defecto');
$o = T::options(fn($k, $d) => $k === 'default_sort' ? "top\n" : $d);
t_ok($o['default_sort'] === 'auto', "default_sort con salto de linea => auto");
$rOn  = ['enabled' => true, 'dislike' => true, 'favorites' => true, 'who' => 'registered'];
$rOff = ['enabled' => false, 'dislike' => true, 'favorites' => true, 'who' => 'registered'];
$rNoFav = ['enabled' => true, 'dislike' => true, 'favorites' => false, 'who' => 'registered'];

$x = T::resolve($def, $rOn, null, null, 'ASC', true);
t_ok($x['selector'] && $x['modes'] === ['newest', 'oldest', 'top'] && $x['sort'] === null && $x['explicit'] === null && $x['selected'] === 'oldest' && !$x['favorites'] && $x['favButton'], 'sin seleccion: manda comments_ordering (asc => Mas antiguos, orden de siempre)');
$x = T::resolve($def, $rOn, null, null, 'DESC', true);
t_ok($x['sort'] === null && $x['selected'] === 'newest', 'sin seleccion y comments_ordering = desc: Mas recientes marcado, orden de siempre (sort = null)');
foreach (['newest', 'oldest', 'top'] as $m) {
	$x = T::resolve($def, $rOn, $m, null, 'ASC', false);
	t_ok($x['sort'] === $m && $x['explicit'] === $m && $x['selected'] === $m, "seleccion $m");
}
$x = T::resolve($def, $rOff, 'top', null, 'ASC', true);
t_ok($x['modes'] === ['newest', 'oldest'] && $x['sort'] === null && $x['explicit'] === null && !$x['favButton'], 'con las reacciones apagadas Mas valorados no existe, no se aplica aunque se pida y no hay boton de favoritos');
$x = T::resolve(array_merge($def, ['default_sort' => 'top']), $rOff, null, null, 'ASC', true);
t_ok($x['sort'] === null && $x['selected'] === 'oldest', 'default_sort = top sin reacciones: se ignora');
$x = T::resolve(array_merge($def, ['default_sort' => 'top']), $rOn, null, null, 'ASC', true);
t_ok($x['sort'] === 'top' && $x['explicit'] === null && $x['selected'] === 'top', 'default_sort = top con reacciones: es el inicial pero NO es explicito (no viaja en los enlaces)');
$x = T::resolve(array_merge($def, ['default_sort' => 'top']), $rOn, 'oldest', null, 'ASC', true);
t_ok($x['sort'] === 'oldest', 'la eleccion del visitante manda sobre default_sort');
$x = T::resolve(array_merge($def, ['sort_selector' => false]), $rOn, 'top', '1', 'ASC', true);
t_ok(!$x['selector'] && $x['sort'] === null && $x['explicit'] === null && !$x['favorites'] && !$x['favButton'], 'sort_selector = No: se ignoran akengage_sort y akengage_fav (todo como en la 0.7.1)');
$x = T::resolve(array_merge($def, ['sort_selector' => false, 'default_sort' => 'newest']), $rOn, null, null, 'ASC', true);
t_ok($x['sort'] === 'newest', 'default_sort sigue valiendo sin selector (opcion explicita del administrador)');
$malos = 0;
foreach ($ataques as $a) {
	$x = T::resolve($def, $rOn, $a, null, 'ASC', true);
	if ($x['sort'] !== null) { $malos++; echo '        sort aceptado: ' . json_encode($a) . "\n"; }
}
t_ok($malos === 0, 'resolve: ningun valor de ataque en akengage_sort llega a sort');
$x = T::resolve($def, $rOn, null, '1', 'ASC', true);
t_ok($x['favorites'] === true, 'akengage_fav=1 con sesion: filtro activo');
$x = T::resolve($def, $rOn, null, '1', 'ASC', false);
t_ok($x['favorites'] === false && $x['favButton'], 'invitado con akengage_fav=1: se ignora (lista normal)');
$x = T::resolve($def, $rNoFav, null, '1', 'ASC', true);
t_ok($x['favorites'] === false && !$x['favButton'], 'favoritos desactivados: se ignora y no hay boton');
$x = T::resolve($def, $rOff, null, '1', 'ASC', true);
t_ok($x['favorites'] === false, 'reacciones desactivadas: se ignora');
$malos = 0;
foreach ($ataques as $a) {
	if ($a === '1') { continue; }
	$x = T::resolve($def, $rOn, null, $a, 'ASC', true);
	if ($x['favorites'] === true) { $malos++; echo '        fav aceptado: ' . json_encode($a) . "\n"; }
}
t_ok($malos === 0, 'resolve: solo la cadena exacta "1" activa los favoritos (no "01", "1\\n", " 1", true, 1, [1], "true"...)');
t_ok(T::resolve($def, $rOn, null, 1, 'ASC', true)['favorites'] === false && T::resolve($def, $rOn, null, "1\n", 'ASC', true)['favorites'] === false, 'ni el entero 1 ni "1\\n"');

echo "C) Enlaces: withQuery, cleanUrl, permalink y URL hostiles\n";
t_ok(T::withQuery('/a/b?x=1&akengage_sort=top&y.z=2&akengage_limitstart=40#frag', ['akengage_sort', 'akengage_limitstart'], ['akengage_sort' => 'oldest', 'akengage_limitstart' => '0'], 'akengage-comments-section') === '/a/b?x=1&y.z=2&akengage_sort=oldest&akengage_limitstart=0#akengage-comments-section', 'quita y anade parametros sin tocar los demas (ni sus puntos)');
t_ok(T::withQuery('/a?akengage%5Fsort=top&q=1', ['akengage_sort']) === '/a?q=1', 'parametro con nombre codificado');
t_ok(T::withQuery('/a?akengage_sort[]=x&akengage_sort[y]=z&k=1', ['akengage_sort']) === '/a?k=1', 'parametro en forma de array');
t_ok(T::withQuery('/a', [], ['akengage_sort' => "x&y=1#z\n"]) === '/a?akengage_sort=x%26y%3D1%23z%0A', 'los valores nuevos se codifican');
t_ok(T::cleanUrl('https://e.example/p?Itemid=3&akengage_cid=9&akengage_sort=top&akengage_fav=1&akengage_limitstart=20&akengage_limit=5#akengage-comment-9') === 'https://e.example/p?Itemid=3', 'cleanUrl quita orden, favoritos, paginacion propia, cid y ancla');
$pl = T::permalink('https://e.example/es/articulo?Itemid=3&akengage_sort=top&akengage_fav=1&akengage_limitstart=20', 15);
t_ok($pl === 'https://e.example/es/articulo?Itemid=3&akengage_cid=15#akengage-comment-15', 'permalink: pagina + akengage_cid + ancla, sin orden, favoritos ni pagina');
t_ok(T::permalink('https://e.example:8443/p', 7) === 'https://e.example:8443/p?akengage_cid=7#akengage-comment-7', 'con puerto');
t_ok(T::permalink('http://[::1]:8080/p', 7) === 'http://[::1]:8080/p?akengage_cid=7#akengage-comment-7', 'IPv6 entre corchetes');
$hostiles = [
	'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x', '//evil.example/p', '/ruta/relativa', 'ftp://e.example/p', 'file:///etc/passwd',
	'https://user@e.example/p', 'https://user:pass@e.example/p', 'https://e.example@evil.example/p', 'https://e.example\\@evil.example/p', "https://e.example/p\nSet-Cookie: x=1", "https://e.example/p\r\n", "https://e.example/p\0",
	'https://e.example/p"onmouseover="alert(1)', "https://e.example/p'onmouseover='alert(1)", 'https://e.example/p<script>', 'https://e.example/p>', 'https://e.example/p`', 'https://e exa.example/p', 'https://e.example/p q',
	'https://ex ample.com/', 'https://exámple.com/', 'https://例え.jp/', 'https://e.example:99999999/p', 'https://e.example:65536/p', 'https://e.example:0/p', 'https://e.example:abc/p', 'https://.example/p', 'https://-e.example/p', 'https://e.example-/p', 'https:///p', 'https://', 'http:e.example', '',
	'https://e.example/p\\x', 'https://e.example/%0d%0a', str_repeat('a', 2100), 'https://' . str_repeat('a', 300) . '.example/p',
];
$malos = 0;
foreach ($hostiles as $h) {
	$r = T::permalink($h, 5);
	if ($h === 'https://e.example/%0d%0a' || $h === 'https://e.example:99999999/p') { continue; }
	if ($r !== '') { $malos++; echo '        aceptada: ' . json_encode($h) . ' -> ' . $r . "\n"; }
}
t_ok($malos === 0, 'permalink rechaza ' . count($hostiles) . ' URL hostiles (otros esquemas, userinfo, relativas, saltos de linea, comillas, angulos, espacios, unicode, hosts raros)');
t_ok(T::permalink('https://e.example/p', 0) === '' && T::permalink('https://e.example/p', -3) === '', 'id 0 o negativo: vacio');
t_ok(preg_match('/^https?:\/\/[^"\'<> \s]+$/D', T::permalink('https://e.example/p?q=%22x%22&r=a%3Cb', 3)) === 1, 'el resultado nunca lleva comillas, angulos ni espacios');
t_ok(T::isSafeAbsoluteUrl('https://e.example/p?a=1&b=2#x') && !T::isSafeAbsoluteUrl('https://e.example/p?a="1"'), 'isSafeAbsoluteUrl');

foreach (['/index.php?x=1' => '/index.php?x=1', '/es/articulo/mi-pagina?Itemid=3&a=b' => '/es/articulo/mi-pagina?Itemid=3&a=b', '/' => '/', '' => ''] as $in => $out) { t_ok(T::safeRelative($in) === $out, "safeRelative('$in') se conserva"); }
$rel = ['//evil.example/x?a=1' => '?a=1', '/\\evil.example/x?a=1' => '?a=1', '///evil.example' => '', 'https://evil.example/x?a=1' => '?a=1', 'javascript:alert(1)' => '', "/ruta\nmal?a=1" => '?a=1', '/ruta con espacio?a=1' => '?a=1', '/ruta"onmouseover=?a=1' => '?a=1', "/ok?a=\"x\"" => '/ok', "/ok?a=<b>" => '/ok', "/ok?a='x'" => '/ok', '/ok?a=b c' => '/ok'];
foreach ($rel as $in => $out) { $r = T::safeRelative((string) $in); t_ok($r === $out, 'safeRelative(' . json_encode((string) $in) . ') -> ' . json_encode($r) . ' (una ruta que empieza por // o /\\ ni contiene cosas raras llega al enlace)'); }
t_ok(!preg_match('~^//~', T::safeRelative('//evil.example/x')) && !preg_match('~^/\\\\~', T::safeRelative('/\\evil')), 'ningun resultado empieza por // ni por /\\ (URL relativa al protocolo)');

echo "D) Insignias: autor y moderador\n";
$mod = fn(array $ids) => fn(int $u): bool => in_array($u, $ids, true);
t_ok(T::badges(0, 5, $mod([0, 5])) === [], 'un invitado (created_by 0) nunca tiene insignias, aunque coincida con 0 o sea «moderador»');
t_ok(T::badges(5, 5, $mod([])) === ['author'], 'el autor del articulo: Autor');
t_ok(T::badges(7, 5, $mod([7])) === ['moderator'], 'moderador que no es el autor: Moderador');
t_ok(T::badges(5, 5, $mod([5])) === ['author', 'moderator'], 'autor y moderador: las dos, en orden fijo');
t_ok(T::badges(7, 0, $mod([])) === [] && T::badges(0, 0, $mod([0])) === [], 'autor desconocido (0): nadie es autor, ni siquiera un invitado');
$llamadas = 0;
$cb = function (int $u) use (&$llamadas): bool { $llamadas++; return false; };
T::badges(7, 5, $cb); T::badges(0, 5, $cb);
t_ok($llamadas === 1, 'el invitado no consulta permisos (una sola llamada para el usuario registrado)');
foreach (T::badges(5, 5, $mod([5])) as $b) { t_ok(in_array($b, ['author', 'moderator'], true), "la insignia '$b' es una clave fija (nunca un nombre de grupo ni un id)"); }

echo "E) Modelo de comentarios (real, con una base de datos que registra)\n";
require_once "$c/backend/src/Mixin/ModelPopulateStateTrait.php";

class RecQuery extends \Joomla\Database\DatabaseQuery
{
	public $selects = []; public $joins = []; public $wheres = []; public $orders = []; public $groups = []; public $binds = []; public $froms = [];
	public function select($x) { foreach ((array) $x as $y) { $this->selects[] = $y; } return $this; }
	public function from($x, $a = null) { $this->froms[] = $x; return $this; }
	public function join($t, $a, $b = null) { $this->joins[] = [$t, $a, $b]; return $this; }
	public function where($x) { $this->wheres[] = $x; return $this; }
	public function whereIn($a, $b, $c = null) { $this->wheres[] = [$a, 'IN', $b]; return $this; }
	public function whereNotIn($a, $b, $c = null) { return $this; }
	public function extendWhere($a, $b, $c) { return $this; }
	public function bind($a, &$b, $c = null) { $this->binds[$a] = $b; return $this; }
	public function order($x) { $this->orders[] = $x; return $this; }
	public function group($x) { $this->groups[] = $x; return $this; }
	public function clear($what = null) { if ($what === 'order') { $this->orders = []; } if ($what === 'select') { $this->selects = []; } return $this; }
	public function __toString() { return 'SELECT ' . implode(', ', array_map(fn($s) => is_array($s) ? json_encode($s) : $s, $this->selects)) . ' FROM ' . json_encode($this->froms) . ' JOIN ' . json_encode($this->joins) . ' WHERE ' . json_encode($this->wheres) . ' GROUP ' . json_encode($this->groups) . ' ORDER ' . implode(', ', $this->orders); }
}
class RecDb extends FakeDb
{
	public $queries = []; public $rows = []; public $rows2 = null; // 0.8.1: filas de la 2a consulta (lista normal visible), si se indican
	public function createQuery() { return new RecQuery(); }
	public function qn($n, $as = null) { return $this->quoteName($n, $as); }
	public function setQuery($q) { $this->queries[] = $q; return new class((count($this->queries) >= 2 && $this->rows2 !== null) ? $this->rows2 : $this->rows) { public function __construct(private array $rows) {} public function loadAssocList($key = null) { $o = []; foreach ($this->rows as $r) { $o[$r[$key ?? 'id']] = $r; } return $o; } public function loadObjectList($key = null) { return []; } }; }
}
require_once "$c/backend/src/Model/CommentsModel.php";
$rc = new ReflectionClass(\Akeeba\Component\Engage\Administrator\Model\CommentsModel::class);
$mk = function (array $state, array $rows) use ($rc) {
	$m = $rc->newInstanceWithoutConstructor();
	$m->state = new FakeState(); $m->db = new RecDb(); $m->db->rows = $rows;
	foreach ($state as $k => $v) { $m->state->set($k, $v); }
	return $m;
};
$gl = $rc->getMethod('getListQuery'); $gl->setAccessible(true);
$tree = fn($m, int $start = 0, ?int $limit = null) => $m->commentIDTreeSliceWithDepth($start, $limit);
$row = fn(int $id, int $parent, string $created) => ['id' => $id, 'parent_id' => $parent ?: null, 'created' => $created];
$plano = fn(array $r) => implode(',', array_map(fn($k, $v) => trim((string) $k) . ':' . $v, array_keys($r), $r));

// E1: SQL de cada modo
foreach (['newest' => ['`c`.`created` DESC', '`c`.`id` DESC'], 'oldest' => ['`c`.`created` ASC', '`c`.`id` ASC'], 'top' => ['`reaction_score` DESC', '`c`.`created` DESC', '`c`.`id` DESC']] as $mode => $esperado) {
	$m = $mk(['list.sortmode' => $mode, 'filter.asset_id' => 104, 'list.ordering' => 'c.created', 'list.direction' => 'ASC'], []);
	$tree($m);
	$q = $m->db->queries[0];
	t_ok($q->orders === $esperado, "modo $mode: ORDER BY " . implode(', ', $q->orders));
	t_ok(($mode === 'top') === (count($q->joins) === 4), "modo $mode: " . ($mode === 'top' ? 'une la subconsulta agregada de reacciones' : 'no une la subconsulta de reacciones (ni lee la tabla)'));
	$s = (string) $q;
	t_ok(!str_contains($s, 'engage_reactions') || $mode === 'top', "modo $mode: la tabla de reacciones solo aparece en top");
}
$m = $mk(['list.sortmode' => 'top', 'filter.asset_id' => 104], []);
$tree($m);
$q = $m->db->queries[0]; $s = (string) $q;
t_ok(($q->binds[':rs_asset'] ?? null) === 104, 'top: el contenido va enlazado (:rs_asset = 104) y no concatenado');
t_ok(str_contains($s, 'GROUP [\"`r`.`comment_id`\"]') && str_contains($s, 'SUM(CASE `r`.`type` WHEN 1 THEN 1 WHEN 2 THEN -1 ELSE 0 END)'), 'top: me gusta - no me gusta, agrupado por comentario');
t_ok(str_contains($s, '`r`.`type` IN (1,2)') && str_contains($s, 'IFNULL(`sc`.`created_by`, 0)'), 'top: cuenta me gusta y no me gusta y deja fuera las reacciones del autor a su propio comentario (igual que los contadores)');
$m = $mk(['list.sortmode' => 'top', 'filter.asset_id' => 104, 'filter.score_dislikes' => false], []);
$tree($m);
t_ok(str_contains((string) $m->db->queries[0], '`r`.`type` IN (1)'), 'top con «No me gusta» desactivado: solo cuentan los me gusta');
$m = $mk(['list.sortmode' => 'top', 'filter.asset_id' => 0], []);
$tree($m);
t_ok($m->db->queries[0]->orders === ['`c`.`created` DESC', '`c`.`id` DESC'] && !str_contains((string) $m->db->queries[0], 'engage_reactions'), 'top sin contenido: cae a fecha (no se agrega toda la tabla)');
// valores de ataque en el estado
$malos = 0;
foreach ($ataques as $a) {
	$m = $mk(['list.sortmode' => $a, 'filter.asset_id' => 104, 'list.ordering' => 'c.created', 'list.direction' => 'DESC'], []);
	$tree($m);
	$q = $m->db->queries[0]; $s = (string) $q;
	if (str_contains($s, 'reaction_score') || $q->orders !== ['`c`.`created` DESC, `c`.`id` DESC']) { $malos++; echo '        orden cambiado por: ' . substr(json_encode($a), 0, 60) . "\n"; }
}
t_ok($malos === 0, 'list.sortmode con valores de ataque: el modelo usa el orden de siempre y ni un caracter del valor llega al SQL');
foreach (['list.ordering' => 'reaction_score', 'list.direction' => 'DESC; DROP'] as $k => $v) {
	$m = $mk([$k => $v, 'filter.asset_id' => 104], []); $q = $gl->invoke($m);
	t_ok(!in_array('`reaction_score` DESC', $q->order ?? $q->orders ?? [], true), "$k = " . json_encode($v) . ' no abre reaction_score por la via antigua');
}

// E2: favoritos
$m = $mk(['filter.favorite_user' => 42, 'filter.asset_id' => 104, 'filter.enabled' => 1], []);
$q = $gl->invoke($m);
$j = json_encode($q->joins);
t_ok(str_contains($j, 'engage_reactions') && str_contains($j, ':fav_user') && str_contains($j, ':fav_type') && ($q->binds[':fav_user'] ?? null) === 42 && ($q->binds[':fav_type'] ?? null) === 3, 'favoritos: INNER JOIN a las reacciones con user_id y tipo 3 ENLAZADOS (42 y 3)');
$malos = 0;
foreach (['5', '5; DROP', 0, -1, null, [5], 1.5, true, "1\n", '0x1'] as $a) {
	$m = $mk(['filter.favorite_user' => $a, 'filter.asset_id' => 104], []);
	$q = $gl->invoke($m);
	if (str_contains(json_encode($q->joins), 'engage_reactions')) { $malos++; echo '        favorito aceptado: ' . json_encode($a) . "\n"; }
}
t_ok($malos === 0, 'filter.favorite_user solo admite un ENTERO positivo (cadenas, arrays, 0, negativos, null: ignorado)');

// E3: arbol
$rows = [ // orden que devolveria el SQL (newest): raices por fecha desc
	$row(4, 0, '2026-10-04 10:00:00'), $row(2, 0, '2026-10-02 10:00:00'), $row(1, 0, '2026-10-01 10:00:00'),
	$row(6, 1, '2026-10-06 10:00:00'), $row(5, 1, '2026-10-05 10:00:00'), $row(3, 2, '2026-10-03 10:00:00'), $row(7, 5, '2026-10-07 10:00:00'),
];
$m = $mk(['list.sortmode' => 'newest', 'filter.asset_id' => 104], $rows);
$r = $tree($m);
t_ok($plano($r) === '4:1,2:1,3:2,1:1,5:2,7:3,6:2', 'newest: raices de mas reciente a mas antigua; respuestas bajo su padre de mas antigua a mas reciente (5 antes que 6)');
t_ok($m->getTreeAwareCount() === 7, 'el total para la paginacion cuenta todos los comentarios');
$r = $tree($mk(['list.sortmode' => 'newest', 'filter.asset_id' => 104], $rows), 2, 3);
t_ok($plano($r) === '3:2,1:1,5:2', 'paginacion (start 2, limit 3) sobre el arbol ordenado');
$rowsOld = [$row(1, 0, '2026-10-01 10:00:00'), $row(2, 0, '2026-10-02 10:00:00'), $row(4, 0, '2026-10-04 10:00:00'), $row(6, 1, '2026-10-06 10:00:00'), $row(5, 1, '2026-10-05 10:00:00')];
t_ok($plano($tree($mk(['list.sortmode' => 'oldest', 'filter.asset_id' => 104], $rowsOld))) === '1:1,5:2,6:2,2:1,4:1', 'oldest');
$rowsTop = [ $row(2, 0, '2026-10-02 10:00:00') + ['reaction_score' => 5], $row(4, 0, '2026-10-04 10:00:00') + ['reaction_score' => 5], $row(1, 0, '2026-10-01 10:00:00') + ['reaction_score' => 0], $row(9, 2, '2026-10-09 10:00:00'), $row(8, 2, '2026-10-08 10:00:00')];
t_ok($plano($tree($mk(['list.sortmode' => 'top', 'filter.asset_id' => 104], $rowsTop))) === '2:1,8:2,9:2,4:1,1:1', 'top: el orden de las raices es el que da el SQL; las respuestas, cronologicas, debajo de su padre');
// huerfanas: hijo de un comentario que no esta en la lista (despublicado o borrado) no se muestra
t_ok($plano($tree($mk(['list.sortmode' => 'newest', 'filter.asset_id' => 104], [$row(1, 0, '2026-10-01 10:00:00'), $row(3, 99, '2026-10-03 10:00:00')]))) === '1:1', 'una respuesta cuyo padre no esta en la lista no se muestra (como siempre)');
t_ok($tree($mk(['list.sortmode' => 'newest', 'filter.asset_id' => 104], [])) === [], 'sin comentarios: lista vacia');
// sin modo: comportamiento de siempre (el orden de la consulta, tambien entre hermanos)
t_ok($plano($tree($mk(['filter.asset_id' => 104], [$row(4, 0, '2026-10-04 10:00:00'), $row(1, 0, '2026-10-01 10:00:00'), $row(6, 1, '2026-10-06 10:00:00'), $row(5, 1, '2026-10-05 10:00:00')]))) === '4:1,1:1,6:2,5:2', 'sin modo: el orden entre hermanos es el de la consulta (desc => 6 antes que 5), igual que en la 0.7.1');
$mm = $mk(['filter.asset_id' => 104], [$row(1, 0, 'x')]); $tree($mm);
t_ok(count($mm->db->queries[0]->selects) === 2, 'sin modo la consulta de IDs sigue seleccionando solo id y parent_id');
// favoritos: lista plana
$rowsFav = [$row(9, 4, '2026-10-09 10:00:00'), $row(3, 0, '2026-10-03 10:00:00'), $row(5, 1, '2026-10-05 10:00:00')];
// 0.8.1: la 2a consulta es la lista normal (sin el JOIN de favoritos): una respuesta solo se ve si TODA su cadena de padres esta en ella
$visFav = [$row(4, 0, '2026-10-04 10:00:00'), $row(1, 0, '2026-10-01 10:00:00'), $row(3, 0, '2026-10-03 10:00:00'), $row(5, 1, '2026-10-05 10:00:00'), $row(9, 4, '2026-10-09 10:00:00')];
$m = $mk(['filter.favorite_user' => 42, 'filter.asset_id' => 104, 'list.sortmode' => 'newest'], $rowsFav); $m->db->rows2 = $visFav;
t_ok($plano($tree($m)) === '9:1,3:1,5:1' && $m->getTreeAwareCount() === 3 && $m->getTotal() === 3, 'favoritos: lista plana, todos en el primer nivel (con el padre visible en la lista normal), en el orden pedido; getTotal() = lo que se muestra');
$m = $mk(['filter.favorite_user' => 42, 'filter.asset_id' => 104], $rowsFav); $m->db->rows2 = $visFav;
t_ok($plano($tree($m, 1, 1)) === '3:1', 'favoritos con paginacion');
// 0.8.1: padre sin publicar/spam (no esta en la lista normal), o abuelo sin publicar: la respuesta favorita NO se lista ni se cuenta
$m = $mk(['filter.favorite_user' => 42, 'filter.asset_id' => 104], [$row(9, 4, 'x'), $row(3, 0, 'x'), $row(5, 1, 'x'), $row(7, 5, 'x')]);
$m->db->rows2 = [$row(1, 0, 'x'), $row(3, 0, 'x'), $row(5, 1, 'x'), $row(7, 5, 'x'), $row(9, 4, 'x')]; // el 4 (padre del 9) no esta
t_ok($plano($tree($m)) === '3:1,5:1,7:1' && $m->getTreeAwareCount() === 3 && $m->getTotal() === 3, 'favoritos 0.8.1: la respuesta cuyo padre no es visible en la lista normal queda fuera (y no cuenta en el total)');
$m = $mk(['filter.favorite_user' => 42, 'filter.asset_id' => 104], [$row(7, 5, 'x')]);
$m->db->rows2 = [$row(7, 5, 'x'), $row(5, 1, 'x')]; // el 5 esta pero su padre (el 1) no: cadena rota
t_ok($tree($m) === [] && $m->getTreeAwareCount() === 0, 'favoritos 0.8.1: tampoco si el que falta es un abuelo (se recorre toda la cadena)');
$m = $mk(['filter.favorite_user' => 42, 'filter.asset_id' => 104], [$row(3, 0, 'x')]); $m->db->rows2 = [];
t_ok($plano($tree($m)) === '3:1' && count($m->db->queries) === 1, 'favoritos 0.8.1: sin respuestas en la lista no hay segunda consulta (cuesta lo mismo que antes)');
// rendimiento del arbol en PHP (5000 comentarios, 25% respuestas)
$big = [];
for ($i = 1; $i <= 5000; $i++) { $big[] = ['id' => $i, 'parent_id' => ($i % 4 === 0) ? $i - 1 : null, 'created' => sprintf('2026-01-%02d 10:%02d:00', 1 + $i % 28, $i % 60)]; }
$t0 = microtime(true);
$m = $mk(['list.sortmode' => 'newest', 'filter.asset_id' => 104], $big); $r = $tree($m, 0, 20);
$ms = (microtime(true) - $t0) * 1000;
t_ok(count($r) === 20 && $m->getTreeAwareCount() === 5000, sprintf('5000 comentarios con modo de orden: arbol y primera pagina en %.0f ms de PHP', $ms));
t_ok($ms < 3000, 'el arbol de 5000 comentarios se construye en menos de 3 s');

echo "F) Opciones del componente: config.xml, esquema y categorias\n";
$cfg = $lee("$c/backend/config.xml");
$sch = SS::parse($cfg, 'com_engage')['fields'];
foreach (['sort_selector' => '1', 'copy_link' => '1', 'show_badges' => '1'] as $k => $d) {
	t_ok(isset($sch[$k]) && $sch[$k]['control'] === 'switch' && $sch[$k]['default'] === $d, "$k: interruptor Si/No, por defecto $d");
	foreach (['0', '1'] as $v) { t_ok(SS::validate($sch[$k], $v) === $v, "$k acepta $v"); }
	$bad = 0; foreach (['2', 'yes', '', ' 1', "1\n", 'true', '-1', '01', [1], null, '1; DROP'] as $v) { try { SS::validate($sch[$k], $v); } catch (\Throwable $e) { $bad++; } }
	t_ok($bad === 11, "$k rechaza los valores fuera de 0/1 ($bad de 11)");
}
t_ok(isset($sch['default_sort']) && $sch['default_sort']['control'] === 'segmented' && $sch['default_sort']['default'] === 'auto' && array_keys($sch['default_sort']['options']) === ['auto', 'newest', 'oldest', 'top'], 'default_sort: lista cerrada auto, newest, oldest, top; por defecto auto');
$bad = 0; foreach (['AUTO', 'newest ', "top\n", 'rand', 'c.created', '', ['top'], null, 'top; DROP'] as $v) { try { SS::validate($sch['default_sort'], $v); } catch (\Throwable $e) { $bad++; } }
t_ok($bad === 9, "default_sort rechaza valores fuera de la lista ($bad de 9)");
foreach (L::DEFAULT_SORT_VALUES as $v) { t_ok(SS::validate($sch['default_sort'], $v) === $v, "default_sort acepta $v"); }
t_ok(array_keys($sch['default_sort']['options']) === L::DEFAULT_SORT_VALUES, 'las opciones de config.xml y la lista de ListOrdering coinciden');
$planas = []; foreach (SL::sections() as $sec) { foreach ($sec['items'] as $it) { $planas[] = $it[1]; } }
foreach (['sort_selector', 'default_sort', 'copy_link', 'show_badges'] as $k) { t_ok(count(array_keys($planas, $k, true)) === 1, "$k aparece exactamente una vez en las opciones modernas"); }
$design = []; foreach (SL::sections() as $sec) { if ($sec['id'] === 'design') { $design = array_map(fn($i) => $i[1], $sec['items']); } }
t_ok(in_array('sort_selector', $design) && in_array('default_sort', $design) && in_array('copy_link', $design) && in_array('show_badges', $design), 'las cuatro estan en la categoria Diseno');
t_ok(array_search('default_sort', $design) === array_search('sort_selector', $design) + 1 && array_search('sort_selector', $design) === array_search('comments_ordering', $design) + 1, 'el orden inicial va junto al orden de los comentarios');
t_ok(str_contains($lf("$c/backend/config.xml"), 'name="sort_selector"') && str_contains($lf("$c/backend/config.xml"), 'validate="options"'), 'config.xml declara las opciones');

echo "G) Idiomas: las cadenas existen en en-GB y es-ES\n";
$ini = function (string $f) { $r = @parse_ini_file($f, false, INI_SCANNER_RAW); return is_array($r) ? $r : []; };
$cadenas = ['COM_ENGAGE_SORT_LABEL', 'COM_ENGAGE_SORT_NEWEST', 'COM_ENGAGE_SORT_OLDEST', 'COM_ENGAGE_SORT_TOP', 'COM_ENGAGE_FAV_ONLY', 'COM_ENGAGE_FAV_SHOW_ALL', 'COM_ENGAGE_FAV_HEADER', 'COM_ENGAGE_FAV_EMPTY', 'COM_ENGAGE_FAV_VIEW_IN_THREAD', 'COM_ENGAGE_TOOLS_COPY_LABEL', 'COM_ENGAGE_TOOLS_COPY_OK', 'COM_ENGAGE_TOOLS_COPY_FAIL', 'COM_ENGAGE_BADGE_AUTHOR', 'COM_ENGAGE_BADGE_MODERATOR'];
foreach (['en-GB', 'es-ES'] as $lg) {
	$fe = $ini("$c/frontend/language/$lg/com_engage.ini");
	$mis = array_filter($cadenas, fn($k) => trim($fe[$k] ?? '', '"') === '');
	t_ok(!$mis, "frontend $lg: las " . count($cadenas) . ' cadenas existen' . ($mis ? ' (faltan: ' . implode(', ', $mis) . ')' : ''));
	$sys = $ini("$c/backend/language/$lg/com_engage.sys.ini");
	$cfgKeys = ['COM_ENGAGE_CONFIG_SHOW_BADGES_LABEL', 'COM_ENGAGE_CONFIG_SHOW_BADGES_DESC', 'COM_ENGAGE_CONFIG_COPY_LINK_LABEL', 'COM_ENGAGE_CONFIG_COPY_LINK_DESC', 'COM_ENGAGE_CONFIG_SORT_SELECTOR_LABEL', 'COM_ENGAGE_CONFIG_SORT_SELECTOR_DESC', 'COM_ENGAGE_CONFIG_DEFAULT_SORT_LABEL', 'COM_ENGAGE_CONFIG_DEFAULT_SORT_DESC', 'COM_ENGAGE_CONFIG_DEFAULT_SORT_AUTO', 'COM_ENGAGE_CONFIG_DEFAULT_SORT_NEWEST', 'COM_ENGAGE_CONFIG_DEFAULT_SORT_OLDEST', 'COM_ENGAGE_CONFIG_DEFAULT_SORT_TOP'];
	$mis = array_filter($cfgKeys, fn($k) => trim($sys[$k] ?? '', '"') === '');
	t_ok(!$mis, "sys.ini $lg: las cadenas de las 4 opciones existen");
}
$es = $ini("$c/frontend/language/es-ES/com_engage.ini");
t_ok(trim($es['COM_ENGAGE_TOOLS_COPY_OK'], '"') === 'Enlace copiado' && trim($es['COM_ENGAGE_SORT_LABEL'], '"') === 'Ordenar por' && trim($es['COM_ENGAGE_SORT_TOP'], '"') === 'Más valorados' && trim($es['COM_ENGAGE_BADGE_MODERATOR'], '"') === 'Moderador' && trim($es['COM_ENGAGE_BADGE_AUTHOR'], '"') === 'Autor', 'textos es-ES pedidos: «Ordenar por», «Más valorados», «Enlace copiado», «Autor», «Moderador»');
t_ok(!preg_match('/\b(puedes|tienes|quieres|haz|pulsa|elige|copia)\b/iu', implode(' ', array_intersect_key($es, array_flip($cadenas)))), 'es-ES de usted: sin formas de tuteo en las cadenas nuevas');

echo "H) Seguridad estatica: plantillas, vista, controlador, JavaScript y CSS\n";
$tplD = $lf("$c/frontend/tmpl/comments/default.php");
$tplL = $lf("$c/frontend/tmpl/comments/default_list.php");
$view = $lf("$c/frontend/src/View/Comments/HtmlView.php");
$ctl  = $lf("$c/frontend/src/Controller/CommentsController.php");
$js   = $lee("$c/media/js/tools.js");
$js2  = $lee("$c/media/js/reactions.js");
t_ok(substr_count($tplD, 'rel="nofollow"') >= 2 && str_contains($tplD, 'aria-current="true"') && str_contains($tplD, 'role="group"'), 'enlaces de orden y de favoritos con rel="nofollow", aria-current y grupo con nombre');
t_ok(str_contains($tplD, 'hidden') && str_contains($tplD, 'data-engage-fav-toggle'), 'el conmutador de favoritos viene oculto en el HTML (lo muestra el JavaScript tras consultar la sesion)');
t_ok(!preg_match('/\bstyle\s*=/i', $tplD) && !preg_match('/\bon[a-z]+\s*=/i', $tplD), 'la barra no lleva estilos en linea ni manejadores onclick');
t_ok(substr_count($tplL, 'style=') === 1 && str_contains($tplL, 'max-width: <?= (int) $maxAvatarWidth ?>px'), 'default_list.php no gana ningun estilo en linea (queda solo el ancho del avatar, que ya existia y es un entero)');
t_ok(str_contains($tplD, 'akengage-sort-label-<?= (int) $this->assetId ?>') && !str_contains($tplD, 'id="akengage-sort-label"'), 'el id de la etiqueta del grupo es unico por contenido (varias secciones en una misma pagina)');
t_ok(str_contains($view, 'CommentTools::relativeUrl(Uri::getInstance()') && str_contains($view, '$this->contentAuthorId = null;'), 'la vista sanea la URL relativa de la peticion y no arrastra el autor de un articulo anterior (la vista puede reutilizarse)');
t_ok(str_contains($tplD, '$this->escape($this->sortUrl(') && str_contains($tplD, '$this->escape($this->favoritesUrl('), 'las URL de la barra salen escapadas');
t_ok(str_contains($view, 'htmlspecialchars($url') || str_contains($view, '$e($url)'), 'el enlace del boton Copiar sale escapado');
t_ok(str_contains($view, 'CommentTools::permalinkRelative('), 'el enlace permanente se construye en el servidor con CommentTools::permalinkRelative (relativo y validado)');
t_ok(!str_contains($view, 'getVar(\'akengage_sort\'') && !str_contains($view, "input->get('akengage_sort'"), 'la vista no lee el parametro de orden: lo valida el controlador');
t_ok(str_contains($ctl, 'CommentTools::PARAM_SORT') && str_contains($ctl, "'raw'") && str_contains($ctl, 'CommentTools::resolve('), 'el controlador lee el crudo y lo pasa por resolve (lista cerrada)');
t_ok(str_contains($ctl, "setState('filter.favorite_user', (int) \$identity->id)"), 'el usuario de los favoritos sale de la sesion (nunca de la peticion)');
t_ok(str_contains($ctl, 'ReactionsController::canView($identity, $assetId)') && str_contains($ctl, '$this->disableJoomlaCache()') && str_contains($ctl, "setHeader('Vary', 'Cookie'"), 'favoritos: comprueba que el usuario ve el contenido, desactiva la cache y anade Vary: Cookie');
t_ok(str_contains($ctl, "setState('filter.enabled', 1)"), 'favoritos: solo comentarios publicados, tambien para moderadores');
t_ok(str_contains($view, "setMetaData('robots', 'noindex, nofollow')") && str_contains($view, "addHeadLink("), 'favoritos con noindex,nofollow; orden con enlace canonico');
t_ok(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function|setAttribute\(\s*["\']style|\.style\.cssText|XMLHttpRequest|importScripts|\.src\s*=/i', $js), 'tools.js: sin innerHTML, eval, estilos en linea, red ni scripts dinamicos');
t_ok(!preg_match('/https?:\/\/(?!\*)/i', preg_replace('~/\*.*?\*/~s', '', $js)) , 'tools.js: ninguna URL externa');
t_ok(str_contains($js, 'navigator.clipboard') && str_contains($js, 'execCommand("copy")') && str_contains($js, 'role", "status"') && str_contains($js, 'aria-live') && !preg_match('/\b(alert|confirm|prompt)\s*\(/', preg_replace('~/\*.*?\*/~s', '', $js)), 'tools.js: Clipboard API con alternativa execCommand, region role=status/aria-live y sin alert/confirm');
t_ok(str_contains($js, 'u.origin !== window.location.origin') && str_contains($js, 'akengage_cid') && str_contains($js, '#akengage-comment-') && str_contains($js, 'http:'), 'tools.js: vuelve a comprobar esquema, origen, id y ancla antes de copiar');
t_ok(str_contains($js, 'closest(".akengage-comment-body")') && str_contains($js, 'art.id ===') && str_contains($js2, 'closest(".akengage-comment-body")') && str_contains($js2, 'data-engage-toolbar'), 'los botones solo valen si los pinto el servidor: fuera del texto del comentario y con el id de su articulo; el conmutador, solo en la barra');
t_ok(!preg_match('/innerHTML|eval\(|insertAdjacentHTML/', $js2), 'reactions.js sigue sin innerHTML ni eval');
$css = $lf("$c/media/css/replies.css");
t_ok(str_contains($css, '.akengage-toast') && str_contains($css, '.akengage-fav-toggle[hidden]') && str_contains($css, '.akengage-copy-tmp'), 'CSS: aviso, ocultacion de [hidden] y area temporal');
foreach (['moderno', 'minimalista', 'oscuro'] as $t) {
	$th = $lf("$c/media/css/themes/$t.css");
	t_ok(str_contains($th, '.akengage-toolbar') && str_contains($th, '.akengage-badge--author') && str_contains($th, '.akengage-badge--moderator') && str_contains($th, '.akengage-copy-btn') && str_contains($th, '.akengage-context-link') && str_contains($th, '.akengage-fav-empty'), "tema $t: estilos de la barra, las insignias, el boton Copiar y el enlace al hilo");
}
$ca = $lf("$c/media/joomla.asset.json");
t_ok(str_contains($ca, '"com_engage.tools"') && str_contains($ca, 'com_engage/tools.js') && json_decode($ca) !== null, 'joomla.asset.json registra tools.js y sigue siendo JSON valido');
$ec = $lf($root . '/src/plugins/system/engagecache/src/Extension/Engagecache.php');
t_ok(str_contains($ec, 'akengage_sort') && str_contains($ec, 'akengage_fav') && str_contains($ec, 'COM_ENGAGE_TOOLS_COPY_OK'), 'engagecache: el orden y los favoritos forman parte de la clave de cache y los textos del aviso sobreviven a ella');
t_ok(str_contains($lf("$c/frontend/src/Controller/ReactionsController.php"), 'public static function canView('), 'ReactionsController::canView es publica (la usa la lista de favoritos)');
t_ok(str_contains($tplL, 'in_array(\'moderator\', $badges, true)'), 'la estrella de moderador existente no se repite cuando ya esta en la insignia');
$bloque = substr($view, strpos($view, 'public function isModeratorUser'), strpos($view, 'public function copyButtonHtml') - strpos($view, 'public function isModeratorUser'));
t_ok(!preg_match('/getAuthorisedGroups|getGroups|groupName|usergroups|->title|authorise\([^)]*\)\s*\?\s*[\'"]/i', $bloque) && substr_count($bloque, 'authorise(') === 2, 'las insignias solo preguntan a la ACL (2 comprobaciones) y no leen ni pintan nombres de grupo, ids ni permisos');

t_fin();
