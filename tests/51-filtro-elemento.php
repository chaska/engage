<?php
/**
 * 0.9.3: filtros «Tipo de elemento» (con recuento, etiquetas amigables, descubrimiento sin tope de 5.000) y «Elemento» (los elementos con comentarios,
 * por numero de comentarios, maximo 100 mas «Otros…», lista cerrada) de la lista de comentarios del administrador; la busqueda mira tambien el titulo.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src/component/backend';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/src/Helper/CommentTypes.php";
require_once "$root/src/Helper/CommentItems.php";

use Akeeba\Component\Engage\Administrator\Helper\CommentItems as I;
use Akeeba\Component\Engage\Administrator\Helper\CommentTypes as T;

echo "A) Tipos: recuento y etiquetas\n";
T::$namesOverride = array_merge(array_map(fn($i) => "com_content.article.$i", range(1, 7)), ['com_juego.ranking.1', 'com_juego.ranking.2', 'com_mapaviajes.trip.5', 'com_mapaviajes.trip.6', 'com_mapaviajes.trip.7', "x'y", null]);
$c = T::counts();
t_ok($c === ['com_content.article' => 7, 'com_juego.ranking' => 2, 'com_mapaviajes.trip' => 3], 'counts(): recuento por tipo, articulos primero, nombres raros descartados (' . json_encode($c) . ')');
T::$namesOverride = null;
$tr = fn(array $m) => fn(string $k): string => $m[$k] ?? $k;
$es = ['COM_ENGAGE_COMMENTS_ITEMTYPE_ARTICLE' => 'Artículos', 'COM_ENGAGE_COMMENTS_ITEMTYPE_THIRDPARTY' => '%s (de terceros)', 'COM_ENGAGE_ITEMTYPE_COM_MAPAVIAJES' => 'Mapa de viajes'];
t_ok(T::label('com_content.article', $tr($es)) === 'Artículos', 'etiqueta: com_content.article = «Artículos»');
t_ok(T::label('com_mapaviajes.trip', $tr($es)) === 'Mapa de viajes', 'etiqueta: com_mapaviajes.* = «Mapa de viajes» (hay clave de idioma)');
t_ok(T::label('com_juego.ranking', $tr($es)) === 'com_juego.ranking (de terceros)', 'etiqueta: sin clave de idioma, el nombre tecnico');
t_ok(T::componentLabelKey('com_mapaviajes.trip') === 'COM_ENGAGE_ITEMTYPE_COM_MAPAVIAJES' && T::componentLabelKey('x y') === '' && T::componentLabelKey('') === '', 'clave de idioma del componente');
t_ok(T::label('com_x.y', fn($k) => $k) === 'COM_ENGAGE_COMMENTS_ITEMTYPE_THIRDPARTY' || str_contains(T::label('com_x.y', fn($k) => $k === 'COM_ENGAGE_COMMENTS_ITEMTYPE_THIRDPARTY' ? '%s!' : $k), 'com_x.y'), 'etiqueta con traductor sin cadenas: no falla');

echo "B) Elementos\n";
$rows = [];
for ($i = 1; $i <= 130; $i++) { $rows[] = ['asset_id' => 1000 + $i, 'n' => 200 - $i, 'name' => "com_content.article.$i", 'atitle' => "Activo $i", 'ctitle' => $i === 1 ? '<b>Libro</b> de visitas' : null]; }
$l = I::build(array_slice($rows, 0, 101), 20000);
t_ok(count($l['items']) === 100 && $l['items'][0] === ['id' => 1001, 'n' => 199, 'title' => 'Libro de visitas'] && $l['others'] > 0, 'build: maximo 100 elementos mas «Otros…»; titulo del articulo sin etiquetas (' . count($l['items']) . ')');
$suma = array_sum(array_column($l['items'], 'n'));
t_ok($l['others'] === 20000 - $suma, 'build: «Otros…» = total - suma de los elementos de la lista (' . $l['others'] . ')');
$l2 = I::build(array_slice($rows, 0, 3), 0);
t_ok(count($l2['items']) === 3 && $l2['others'] === 0, 'build: con 3 elementos no hay «Otros…»');
t_ok(I::title(['name' => 'com_x.y.5'], 5) === 'com_x.y.5' && I::title([], 9) === 'asset 9' && I::title(['atitle' => "a\nb\0c"], 1) === 'a bc' && mb_strlen(I::title(['ctitle' => str_repeat('é', 200)], 1)) === 80, 'title: respaldo, sin saltos de linea ni NUL, 80 caracteres');
t_ok(I::validate('1005', $l) === '1005' && I::validate(1005, $l) === '1005' && I::validate('other', $l) === 'other' && I::validate('1131', $l) === '' && I::validate('0', $l) === '' && I::validate('999999', $l) === '' && I::validate('1e3', $l) === '' && I::validate('1005 OR 1=1', $l) === '' && I::validate('<script>', $l) === '' && I::validate(['1005'], $l) === '' && I::validate(null, $l) === '' && I::validate("1005\n", $l) === '' && I::validate('-1', $l) === '', 'validate: solo un valor de la lista cerrada (los hostiles y los fuera de la lista se ignoran)');
t_ok(I::validate('other', $l2) === '', 'validate: «other» sin «Otros…» no vale');
I::$rowsOverride = $rows;
t_ok(count(I::available(null, '')['items']) === 100, 'available() con las filas sustituidas');
I::$rowsOverride = null;

echo "C) El codigo\n";
$m = $lee("$root/src/Model/CommentsModel.php");
t_ok(str_contains($m, "'itemid'             => 'string'") && str_contains($m, "'itemtype', 'itemid'"), 'modelo: itemid es un campo de estado');
t_ok(preg_match("/CommentItems::validate\(\\\$fltItemRaw, \\\$itemList\\)/", $m) === 1 && str_contains($m, "->where(\$db->quoteName('c.asset_id') . ' = :itemid')->bind(':itemid', \$fltItemId, ParameterType::INTEGER)") && str_contains($m, 'whereNotIn($db->quoteName(\'c.asset_id\')'), 'modelo: valida contra la lista cerrada; SQL ligado; «otros» con NOT IN de enteros');
t_ok(str_contains($m, "->join('LEFT', \$db->quoteName('#__assets', 'ast')") && !str_contains($m, "join('INNER', \$db->quoteName('#__assets', 'ast')"), 'modelo: el JOIN con #__assets es LEFT y siempre (el contador y la paginacion no se rompen)');
t_ok(substr_count($m, "\$db->quoteName('a.title') . ' LIKE") >= 2 && substr_count($m, "\$db->quoteName('ast.title') . ' LIKE") >= 2, 'la busqueda de texto (y title:) mira el titulo del articulo y el del activo');
t_ok(str_contains($m, "elseif (substr(\$fltSearch, 0, 3) === 'ip:')"), 'la busqueda «id:» ya no cae ademas en la busqueda general');
$ct = $lee("$root/src/Helper/CommentTypes.php");
t_ok(!str_contains($ct, 'MAX_ASSETS') && str_contains($ct, "NOT LIKE ' . \$k") && str_contains($ct, 'MAX_TYPES = 60'), 'descubrimiento de tipos sin el tope de 5.000 activos (una consulta por tipo, con LIKE ligado)');
$fx = $lee("$root/forms/filter_comments.xml");
t_ok(preg_match('/name="itemid"\s+type="itemid"\s+label="COM_ENGAGE_COMMENTS_FILTER_ITEMID"/', $fx) === 1, 'formulario: filtro «Elemento»');
t_ok(str_contains($lee("$root/src/Field/ItemidField.php"), 'CommentItems::available') && str_contains($lee("$root/src/Field/ItemtypeField.php"), "' (' . (int) \$n . ')'"), 'los desplegables muestran el numero de comentarios entre parentesis');
foreach (['en-GB', 'es-ES'] as $lg) {
	$ini = $lee("$root/language/$lg/com_engage.ini");
	foreach (['COM_ENGAGE_COMMENTS_FILTER_ITEMID', 'COM_ENGAGE_COMMENTS_FILTER_ITEMID_SELECT', 'COM_ENGAGE_COMMENTS_FILTER_ITEMID_OTHERS', 'COM_ENGAGE_ITEMTYPE_COM_MAPAVIAJES'] as $k) { t_ok(str_contains($ini, "$k=\""), "$lg: $k"); }
}
t_fin();
