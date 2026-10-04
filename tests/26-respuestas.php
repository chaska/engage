<?php
/**
 * 0.6.21: respuestas (cita "En respuesta a", sangria y estilo). Comprobaciones estaticas del codigo, de config.xml, del CSS y de los idiomas.
 * El comportamiento servido se prueba contra un Joomla real en tests/joomla-live/09-respuestas.sh.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src/component';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", file_get_contents("$root/$f"));
$cfg  = $lee('backend/config.xml');
$vista = $lee('frontend/src/View/Comments/HtmlView.php');
$lista = $lee('frontend/tmpl/comments/default_list.php');
$css   = $lee('media/css/replies.css');
$disp  = $lee('frontend/src/Dispatcher/Dispatcher.php');

echo "A) config.xml\n";
$x = simplexml_load_string($cfg);
$campos = [];
foreach ($x->xpath('//fieldset[@name="replies"]/field') as $f) { $campos[(string) $f['name']] = $f; }
t_ok(isset($campos['reply_show_quote'], $campos['reply_indent'], $campos['reply_style']), 'el fieldset "replies" tiene reply_show_quote, reply_indent y reply_style');
t_ok((string) $campos['reply_show_quote']['default'] === '1', 'la cita viene ACTIVADA por defecto');
t_ok((string) $campos['reply_indent']['default'] === 'medium', 'sangria por defecto: medium');
t_ok((string) $campos['reply_style']['default'] === 'line', 'estilo por defecto: line (aspecto original)');
$vals = fn($n) => array_map(fn($o) => (string) $o['value'], $campos[$n]->xpath('option'));
t_ok($vals('reply_indent') === ['none', 'small', 'medium', 'large'], 'valores de reply_indent');
t_ok($vals('reply_style') === ['line', 'soft', 'none'], 'valores de reply_style');
t_ok(!str_contains($cfg, 'show_inreplyto'), 'no queda el nombre provisional show_inreplyto');

echo "B) Vista y plantilla\n";
t_ok(preg_match("/in_array\(\\\$replyIndent, \['none', 'small', 'medium', 'large'\], true\)/", $vista) === 1, 'reply_indent pasa por lista blanca estricta antes de ser clase CSS');
t_ok(preg_match("/in_array\(\\\$replyStyle, \['line', 'soft', 'none'\], true\)/", $vista) === 1, 'reply_style pasa por lista blanca estricta');
t_ok(str_contains($vista, "->where(\$db->quoteName('asset_id') . ' = :asset_id')") && str_contains($vista, '->bind(\':asset_id\''), 'la consulta del padre en otra pagina usa parametros enlazados y limita al mismo contenido');
t_ok(str_contains($vista, '->whereIn($db->quoteName(\'id\'), array_values($missing), ParameterType::INTEGER)'), 'los IDs de padres van enlazados como enteros');
t_ok(str_contains($vista, "if (!\$this->perms['state'])") && str_contains($vista, "'enabled') . ' = 1'"), 'sin permiso de moderacion solo se consultan padres publicados (no se filtra el nombre)');
t_ok(str_contains($lista, "htmlspecialchars(\$replyTo['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"), 'el nombre del padre se escapa con htmlspecialchars');
t_ok(str_contains($lista, "htmlspecialchars(\$replyToHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"), 'el enlace de la cita se escapa');
t_ok(str_contains($lista, '(int) $replyTo[\'id\']'), 'el ID del enlace se fuerza a entero');
t_ok(str_contains($lista, 'akengage-reply-indent--<?= $this->escape($this->replyIndent) ?>') && str_contains($lista, 'akengage-reply-style--<?= $this->escape($this->replyStyle) ?>'), 'las clases de sangria y estilo salen escapadas');
t_ok(!preg_match('/style="[^"]*reply/i', $lista), 'sin estilos en linea para las respuestas (CSP)');
t_ok(str_contains($lista, 'COM_ENGAGE_COMMENTS_INREPLYTO_GENERIC'), 'texto generico cuando el padre no esta disponible');

echo "C) CSS y carga\n";
foreach (['none', 'small', 'medium', 'large'] as $o) { t_ok(str_contains($css, "akengage-reply-indent--$o"), "replies.css define la sangria $o"); }
foreach (['soft', 'none'] as $o) { t_ok(str_contains($css, "akengage-reply-style--$o"), "replies.css define el estilo $o"); }
t_ok(str_contains($css, 'var(--akengage-reply-indent, var(--akengage-reply-indent-size'), 'la variable --akengage-reply-indent del usuario manda sobre la opcion');
t_ok(!str_contains($css, '@import') && !preg_match('#url\(\s*[\'"]?https?:#', $css), 'sin @import ni recursos externos');
$assets = json_decode($lee('media/joomla.asset.json'), true);
$nombres = array_column($assets['assets'], 'uri', 'name');
t_ok(($nombres['com_engage.replies'] ?? '') === 'com_engage/replies.css', 'replies.css registrado como activo');
t_ok(is_file("$root/media/css/replies.css"), 'el archivo existe');
t_ok(str_contains($disp, "'style:com_engage.replies'") && !preg_match("/else\s*\{[^}]*com_engage\.replies/s", $disp), 'se carga siempre (con y sin "Cargar CSS personalizado")');
t_ok(str_contains($lee('media/css/comments.css'), 'ul.akengage-comment-list{margin-left:0}') && !str_contains($lee('media/css/comments.css'), 'ul.akengage-comment-list{margin-left:2em}'), 'comments.css ya no suma su propio margen (evita duplicar la sangria)');

echo "D) Idiomas\n";
$claves = ['COM_ENGAGE_CONFIG_REPLIES_FIELDSET_LABEL', 'COM_ENGAGE_CONFIG_REPLIES_FIELDSET_DESC', 'COM_ENGAGE_CONFIG_REPLY_SHOW_QUOTE_LABEL', 'COM_ENGAGE_CONFIG_REPLY_SHOW_QUOTE_DESC',
	'COM_ENGAGE_CONFIG_REPLY_INDENT_LABEL', 'COM_ENGAGE_CONFIG_REPLY_INDENT_DESC', 'COM_ENGAGE_CONFIG_REPLY_INDENT_NONE', 'COM_ENGAGE_CONFIG_REPLY_INDENT_SMALL', 'COM_ENGAGE_CONFIG_REPLY_INDENT_MEDIUM',
	'COM_ENGAGE_CONFIG_REPLY_INDENT_LARGE', 'COM_ENGAGE_CONFIG_REPLY_STYLE_LABEL', 'COM_ENGAGE_CONFIG_REPLY_STYLE_DESC', 'COM_ENGAGE_CONFIG_REPLY_STYLE_LINE', 'COM_ENGAGE_CONFIG_REPLY_STYLE_SOFT', 'COM_ENGAGE_CONFIG_REPLY_STYLE_NONE'];
foreach (['en-GB', 'es-ES'] as $l) {
	$ini = parse_ini_string($lee("backend/language/$l/com_engage.sys.ini"), false, INI_SCANNER_RAW);
	$f   = parse_ini_string($lee("frontend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW);
	t_ok(!array_diff($claves, array_keys($ini)), "$l: estan las 15 cadenas de configuracion");
	t_ok(isset($f['COM_ENGAGE_COMMENTS_INREPLYTO_GENERIC'], $f['COM_ENGAGE_COMMENTS_FORM_INREPLYTO_LABEL']), "$l: cadenas del frontend (cita generica y etiqueta)");
}
t_ok(str_contains($lee('frontend/language/es-ES/com_engage.ini'), 'COM_ENGAGE_COMMENTS_INREPLYTO_GENERIC="En respuesta a otro comentario"'), 'es-ES de la cita generica');
t_fin();
