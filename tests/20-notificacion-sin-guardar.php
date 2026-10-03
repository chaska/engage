<?php
/**
 * 0.6.14 (seguridad): CommentTable::store() disparaba onAfterCreate/onAfterUpdate aunque la escritura en la base de datos
 * hubiese fallado. El plugin engage/email (onComEngageCommentTableAfterCreate) enviaba entonces correos con el texto de un
 * comentario NO guardado (id 0) a moderadores y suscriptores, y el registro de actividad anotaba una creacion falsa.
 * Hallado en Joomla 6.1.4 real: un invitado con un nombre de >255 caracteres (la columna es varchar(255)) provocaba el correo.
 * Comprobacion del fuente de store() y simulacion del orden de eventos.
 */
require __DIR__ . '/aserciones.php';

$src = file_get_contents(dirname(__DIR__) . '/src/component/backend/src/Table/CommentTable.php');
t_ok($src !== false, 'CommentTable.php legible');
preg_match('/public function store\(.*?\n\t\}\r?\n/s', $src, $m);
$store = $m[0] ?? '';
t_ok($store !== '', 'se localiza store()');
t_ok((bool) preg_match('/if \(\$result\)\s*\{\s*\$this->triggerEvent\(\$isNew \? \'onAfterCreate\' : \'onAfterUpdate\'/s', $store), 'onAfterCreate/onAfterUpdate solo se disparan si $result es verdadero');
t_ok(strpos($store, "'onAfterStore', [&\$result") !== false, 'onAfterStore se sigue disparando siempre (informa del resultado)');
t_ok(!preg_match('/^\s*\$this->triggerEvent\(\$isNew \? \'onAfterCreate\'/m', preg_replace('/if \(\$result\)\s*\{.*?\}/s', '', $store)), 'no queda ninguna llamada incondicional a onAfterCreate');

// Simulacion del flujo con el mismo orden de llamadas que store()
function simula(bool $resultadoBd): array
{
	$log = [];
	$trigger = function (string $e) use (&$log) { $log[] = $e; };
	$isNew = true; $result = $resultadoBd;
	$trigger('onBeforeCreate'); $trigger('onBeforeStore');
	$trigger('onAfterStore');
	if ($result) { $trigger($isNew ? 'onAfterCreate' : 'onAfterUpdate'); }
	return $log;
}
t_ok(in_array('onAfterCreate', simula(true), true), 'escritura correcta: se notifica');
t_ok(!in_array('onAfterCreate', simula(false), true), 'escritura fallida: no se notifica');
t_fin();
