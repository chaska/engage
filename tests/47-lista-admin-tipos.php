<?php
/**
 * 0.8.5: lista de comentarios del administrador: filtro «Tipo de elemento» (lista cerrada con los tipos reales), titulo y enlace del elemento en cada
 * fila (fichas ya saneadas, con tope de activos distintos por pagina), «Elemento no disponible (asset N)» y filtro de estado «Todos».
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__) . '/src/component/backend';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
require_once "$root/src/Helper/CommentTypes.php";

use Akeeba\Component\Engage\Administrator\Helper\CommentTypes as T;

echo "A) CommentTypes\n";
t_ok(T::fromAssetName('com_content.article.12') === 'com_content.article' && T::fromAssetName('com_mapa.punto.5') === 'com_mapa.punto' && T::fromAssetName('com_x.5') === 'com_x', 'tipo = nombre del activo sin el numero final');
t_ok(T::fromAssetName('com_content.category.7') === 'com_content.category' && T::fromAssetName('com_tercero.item.900001') === 'com_tercero.item', 'otros componentes');
foreach ([null, '', 123, [], "com_x.item.1\n", 'com x.item.1', 'com_x.item.1<script>', "com_x'.item.1", 'com_x.item.1;DROP', str_repeat('a', 101), '.5', '12', 'com_x/../y.1', '%', 'com_%.1'] as $mal) {
	t_ok(T::fromAssetName($mal) === null, 'nombre rechazado: ' . json_encode(is_string($mal) ? substr($mal, 0, 30) : $mal));
}
$l = T::fromNames(['com_tercero.item.2', 'com_content.article.1', 'com_content.article.9', 'com_hostil.item.3', "x'y", null, 'com_tercero.item.7']);
t_ok(array_keys($l) === ['com_content.article', 'com_hostil.item', 'com_tercero.item'], 'lista cerrada: unica, ordenada, articulos primero, sin nombres raros');
t_ok(T::validate('com_tercero.item', $l) === 'com_tercero.item' && T::validate('com_otro.item', $l) === '' && T::validate('<script>', $l) === '' && T::validate("com_tercero.item' OR 1=1", $l) === '' && T::validate('com_%', $l) === '' && T::validate(['x'], $l) === '' && T::validate(null, $l) === '' && T::validate('', $l) === '', 'el filtro solo acepta un valor de la lista; el resto se ignora');
t_ok(T::likePattern('com_content.article') === 'com\_content.article.%', 'LIKE con los comodines escapados');
T::$namesOverride = ['com_content.article.1', 'com_a.b.2'];
t_ok(array_keys(T::available()) === ['com_content.article', 'com_a.b'], 'available() con la lectura sustituida');
T::$namesOverride = null;

echo "B) El codigo\n";
$m = $lee("$root/src/Model/CommentsModel.php");
t_ok(preg_match("/CommentTypes::validate\(\\\$this->getState\('filter\.itemtype'\), CommentTypes::available\(\\\$db\)\).*?'ast\.name'\) \. ' LIKE :itemtype'\).*?->bind\(':itemtype', \\\$typeLike, ParameterType::STRING\)/s", $m) === 1, 'modelo: valida contra la lista cerrada y SQL ligado');
t_ok(str_contains($m, "'itemtype'           => 'string'") && str_contains($m, "'search', 'from', 'to', 'itemtype'"), 'el filtro es un campo de estado del modelo');
t_ok(str_contains($m, "->join('LEFT', \$db->quoteName('#__content', 'a')"), 'el LEFT JOIN con #__content de los articulos se conserva');
$v = $lee("$root/src/View/Comments/HtmlView.php");
t_ok(str_contains($v, 'public const MAX_META_ASSETS = 50;') && preg_match('/count\(\$this->metaSeen\) >= self::MAX_META_ASSETS.*?return \[.type. => .unknown./s', $v) === 1 && str_contains($v, 'Meta::getAssetAccessMeta($assetId, false)'), 'vista: tope de activos distintos consultados por pagina (cache por activo en Meta)');
$tp = $lee("$root/tmpl/comments/default.php");
t_ok(str_contains($tp, '$this->itemMeta((int) $item->asset_id)') && !str_contains($tp, 'Meta::getAssetAccessMeta('), 'la plantilla usa itemMeta (con tope), no llama a los plugins directamente');
t_ok(substr_count($tp, "COM_ENGAGE_COMMENTS_LBL_ITEM_UNAVAILABLE', (int) \$item->asset_id") >= 3 && str_contains($tp, '$this->escape($meta[\'title\']') && str_contains($tp, '$this->escape($item->article_title)'), 'titulo escapado; «Elemento no disponible (asset N)» con el numero como entero');
t_ok(!preg_match('/<\?=\s*\$meta\[[^\]]+\]\s*\?>/', $tp) && !preg_match('/<\?=\s*\$item->(article_title|name)\s*\?>/', $tp), 'ningun dato de ficha sin escapar');
$fx = $lee("$root/forms/filter_comments.xml");
t_ok(preg_match('/name="itemtype"\s+type="itemtype"/', $fx) === 1 && str_contains($fx, 'COM_ENGAGE_COMMENTS_FILTER_STATUS_ALL'), 'formulario del filtro: tipo de elemento y «Todos» en el estado');
$fl = $lee("$root/src/Field/ItemtypeField.php");
t_ok(str_contains($fl, 'extends ListField') && str_contains($fl, 'CommentTypes::counts()'), 'campo del filtro: lista cerrada');
foreach (['en-GB', 'es-ES'] as $lg) {
	$ini = $lee("$root/language/$lg/com_engage.ini");
	foreach (['COM_ENGAGE_COMMENTS_FILTER_ITEMTYPE', 'COM_ENGAGE_COMMENTS_FILTER_ITEMTYPE_SELECT', 'COM_ENGAGE_COMMENTS_ITEMTYPE_ARTICLE', 'COM_ENGAGE_COMMENTS_ITEMTYPE_THIRDPARTY', 'COM_ENGAGE_COMMENTS_FILTER_STATUS_ALL', 'COM_ENGAGE_COMMENTS_LBL_ITEM_UNAVAILABLE'] as $k) {
		t_ok(str_contains($ini, "$k=\""), "$lg: $k");
	}
	t_ok(str_contains($ini, 'COM_ENGAGE_COMMENTS_LBL_ITEM_UNAVAILABLE="') && preg_match('/ITEM_UNAVAILABLE="[^"]*%d/', $ini) === 1, "$lg: «asset %d»");
}
t_fin();
