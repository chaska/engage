<?php
/**
 * 0.6.15: Akismet::onAkeebaEngageCheckSpam hacia `[$comment, $isNew] = array_values($event->getArguments())`, pero
 * CommentModel dispara el evento con UN solo argumento ([$table]): "PHP Warning: Undefined array key 1" en cada comentario
 * con Akismet activo (hallado en Joomla 6.1.4 real) y $isNew siempre nulo, de modo que Akismet recibia
 * recheck_reason=edit en comentarios nuevos. Comprobacion del fuente y de la logica de deduccion.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src';
$src  = file_get_contents("$root/plugins/engage/akismet/src/Extension/Akismet.php");
t_ok(strpos($src, '[$comment, $isNew] = array_values($event->getArguments());') === false, 'ya no se desestructura un segundo argumento que no existe');
t_ok(strpos($src, '$args      = array_values($event->getArguments());') !== false && strpos($src, "(isset(\$args[1]) && is_bool(\$args[1]))") !== false, '$isNew solo se toma del evento si es booleano');
$modelo = file_get_contents("$root/component/frontend/src/Model/CommentModel.php");
t_ok(strpos($modelo, "triggerEvent('onAkeebaEngageCheckSpam', [\$table])") !== false, 'CommentModel sigue disparando el evento con un solo argumento (por eso hace falta la deduccion)');

function deducir(array $args): array
{
	$args    = array_values($args);
	$comment = $args[0] ?? null;
	$isNew   = (isset($args[1]) && is_bool($args[1])) ? $args[1] : (is_object($comment) ? empty($comment->id) : true);
	return [$comment, $isNew];
}
set_error_handler(function ($n, $m) { throw new ErrorException($m, 0, $n); });
try {
	t_ok(deducir([(object) ['id' => 0]])[1] === true, 'comentario sin ID -> nuevo, sin avisos');
	t_ok(deducir([(object) ['id' => 7]])[1] === false, 'comentario con ID -> edicion');
	t_ok(deducir([(object) ['id' => 7], true])[1] === true, 'un booleano explicito del evento manda');
	t_ok(deducir([(object) ['id' => 0], ['otro' => 'resultado']])[1] === true, 'un segundo argumento que no es booleano (p. ej. result) se ignora');
	t_ok(deducir([null])[0] === null, 'sin comentario no hay aviso');
} catch (ErrorException $e) { t_ok(false, 'aviso PHP: ' . $e->getMessage()); }
restore_error_handler();
t_fin();
