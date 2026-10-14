<?php
/**
 * 0.9.2: regresion de la 0.8.3. Las listas cerradas de tareas (engageAllowedTasks) dejaron fuera la tarea VACIA y «browse», que son lo que envian
 * el formulario adminForm de la lista de Comentarios al filtrar, buscar, ordenar y paginar (Joomla envia task= vacio) y el boton de restablecer de
 * backend.js; todo filtro acababa en 403. La tarea vacia y «browse» solo se aceptan en las listas de las pantallas de lista y NUNCA son una accion:
 * Joomla las lleva a la tarea por defecto, que solo pinta la vista. El recorrido en navegador de cada formulario esta en
 * tests/joomla-live/lib/listas-navegador.js (22-revision-082.sh).
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
$listas = function (string $f) use ($lee): array {
	if (!preg_match('/function engageAllowedTasks\(\): \?array\s*\{(.*?)\n\t\}/s', $lee($f), $m) || !preg_match('/return \[(.*?)\];/s', $m[1], $r)) { return []; }
	preg_match_all("/'([^']*)'/", $r[1], $t);
	return $t[1];
};
echo "A) Listas de tareas\n";
$cc = $listas("$root/backend/src/Controller/CommentsController.php");
t_ok(in_array('', $cc, true) && in_array('browse', $cc, true), "CommentsController (lista de comentarios): acepta la tarea vacia y «browse» (" . json_encode($cc) . ')');
t_ok(in_array('publish', $cc, true) && in_array('delete', $cc, true) && in_array('main', $cc, true), 'y conserva sus acciones de estado y borrado (con token y permisos) y la vista');
$accionesDeEscritura = ['save', 'apply', 'save2new', 'save2copy', 'delete', 'publish', 'unpublish', 'forgetbase', 'sendtest', 'updateemails', 'resetemails', 'add', 'edit', 'cancel', 'reload', 'checkin', 'possiblespam', 'reportham', 'reportspam', 'unsubscribe'];
foreach (['backend' => ['Comment', 'Comments', 'Controlpanel', 'Settings', 'Emailtemplates', 'Emailpreview'], 'frontend' => ['Comment', 'Comments']] as $lado => $nombres) {
	foreach ($nombres as $n) {
		$f = "$root/$lado/src/Controller/{$n}Controller.php";
		$l = $listas($f);
		$vacia = in_array('', $l, true) || in_array('browse', $l, true);
		$esLista = $lado === 'backend' && $n === 'Comments';
		t_ok($l !== [] && ($esLista ? $vacia : !$vacia), "$lado/$n: lista cerrada " . ($esLista ? 'con' : 'SIN') . ' tarea vacia ni «browse» (' . json_encode($l) . ')');
	}
}
// la tarea vacia y «browse» no pueden ser metodos publicos del controlador (serian acciones)
foreach (glob("$root/{backend,frontend}/src/Controller/*Controller.php", GLOB_BRACE) as $f) {
	t_ok(!preg_match('/public function (browse)\s*\(/i', $lee($f)), basename($f) . ': ninguna accion llamada browse');
}
echo "B) Formularios de la lista\n";
$bj = $lee("$root/media/js/backend.js") . $lee("$root/media/js/backend.min.js");
t_ok(!str_contains(preg_replace('#/\*.*?\*/#s', '', $bj), 'akeeba.System') && str_contains($bj, 'comEngageResetFilters'), 'backend.js ya no usa akeeba.System (que Joomla no carga) y conserva el boton de restablecer');
$fx = $lee("$root/backend/forms/filter_comments.xml");
t_ok(!str_contains($fx, 'atsCommon'), 'filter_comments.xml: sin clases heredadas de otros productos (atsCommon...): el selector del numero de filas no enviaba el formulario');
preg_match_all('/class="([^"]*CommonEventsOnChangeSubmit[^"]*)"/', $fx, $m);
t_ok(count($m[1]) >= 3 && !array_filter($m[1], fn($c) => !str_contains($c, 'engageCommonEventsOnChangeSubmit')), 'todos los campos que se envian al cambiar usan engageCommonEventsOnChangeSubmit (' . count($m[1]) . ')');
t_ok(str_contains($lee("$root/media/js/events.js"), "querySelectorAll('.engageCommonEventsOnChangeSubmit')"), 'events.js engancha esa clase');
$tpl = $lee("$root/backend/tmpl/comments/default.php");
t_ok(str_contains($tpl, 'name="adminForm"') && str_contains($tpl, 'name="task" value=""'), 'la lista envia task= vacio (lo que acepta la lista cerrada)');
$nav = $lee(dirname(__DIR__) . '/tests/joomla-live/lib/listas-navegador.js');
foreach (['filter[enabled]', 'filter[itemtype]', 'filter[search]', 'list[limit]', 'th a.js-stools-column-order', '.pagination', 'js-stools-btn-clear', 'status-group-children-unpublish'] as $k) { t_ok(str_contains($nav, $k), "el recorrido en Chromium cubre: $k"); }
t_ok(str_contains($lee(dirname(__DIR__) . '/tests/joomla-live/22-revision-082.sh'), 'listas-navegador.js'), '22-revision-082.sh ejecuta el recorrido de las listas');
t_fin();
