<?php
/**
 * 0.9.10: comentarios importados (sin usuario, sin correo, ip «0»): avatar generico local en todas las tarjetas y la IP solo si es valida. Funciones puras y codigo.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/backend/src/Helper/CommentTools.php";
use Akeeba\Component\Engage\Administrator\Helper\CommentTools as T;

echo "A) validIp\n";
foreach (['203.0.113.9', '192.168.1.10', '127.0.0.1', '2001:db8::1', '::1', 'fe80::1ff:fe23:4567:890a', '255.255.255.255', '  8.8.8.8 '] as $v) { t_ok(T::validIp($v) === trim($v), "valida: $v"); }
$mal = ['0', '', ' ', '0.0.0.0', '::', '0:0:0:0:0:0:0:0', '00', '1', '999.1.1.1', '1.2.3', '1.2.3.4.5', 'basura', 'localhost', '<script>', '1.2.3.4<b>', "1.2.3.4\n5", '1.2.3.4/24', '::ffff:999.1.1.1', str_repeat('1', 60), null, [], 0, 12, true, '??', '???', '-', 'NULL', '0x7f000001', '127.1'];
$colados = [];
foreach ($mal as $v) { $r = T::validIp($v); if ($r !== '') { $colados[] = json_encode($v) . ' => ' . $r; } }
t_ok(!$colados, count($mal) . ' valores no validos (0, 0.0.0.0, ::, vacio, basura, etiquetas, rangos): ninguno se muestra' . ($colados ? ' [' . implode(' | ', $colados) . ']' : ''));

echo "B) La plantilla y el avatar\n";
$dl = $lee("$root/frontend/tmpl/comments/default_list.php"); $av = $lee("$root/backend/src/Helper/Avatar.php");
t_ok(str_contains($dl, '$ipShown       = CommentTools::validIp($comment->ip ?? \'\');') && str_contains($dl, "\$ipLookupURL   = \$ipShown !== '' ? \$this->getIPLookupURL(\$ipShown) : '';") && str_contains($dl, 'use Akeeba\Component\Engage\Administrator\Helper\CommentTools;'), 'la IP se valida y el enlace de busqueda solo se calcula con una IP valida');
t_ok(str_contains($dl, "<?php if (\$ipShown !== '' && (\$this->perms['edit']") && !str_contains($dl, "(\$comment->ip ?? '???')") && substr_count($dl, 'htmlspecialchars($ipShown,') === 2, 'la linea de la IP solo sale con IP valida (y a quien modera); lo que se imprime es la IP validada y escapada');
t_ok(str_contains($av, "if (\$data['src'] === '')") && str_contains($av, 'self::genericSrc($context !== self::CONTEXT_PAGE)') && str_contains($av, "/media/com_engage/images/avatar-generico.svg'"), 'sin avatar (sin usuario ni correo) se usa el generico local; absoluto solo en contextos no interactivos');
t_ok(str_contains($dl, '<?php if (!empty($avatar)): ?>') && str_contains($dl, 'itemprop="image"'), 'la plantilla sigue pintando el avatar cuando hay (ahora siempre) y con itemprop image');
t_ok(is_file(dirname($root, 2) . '/src/component/media/images/avatar-generico.svg') || is_file("$root/media/images/avatar-generico.svg"), 'el avatar generico existe en el paquete');
$be = $lee("$root/backend/tmpl/comments/default.php");
t_ok(str_contains($be, '$item->ip') && !str_contains($be, 'validIp'), 'la pantalla de administracion sigue mostrando la IP tal cual');
t_fin();
