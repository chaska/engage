<?php
/**
 * 0.8.1: salidas HTML sin escapar (XSS). Causa raiz de la correccion de seguridad de la 0.8.1: la plantilla de la lista imprimia
 * `$tempUri->toString()` (la URL de la peticion entera) dentro de un `href`, sin escapar. Aqui:
 *  A) analisis estatico (con el tokenizador de PHP, no con expresiones regulares sobre texto) de TODA salida `<?=` / echo / print de
 *     TODAS las plantillas, vistas, capas, modulos y plugins de src (sin vendor): cada expresion debe ser un escapado conocido, una
 *     traduccion, una conversion a entero, un literal, o estar en la lista blanca JUSTIFICADA de abajo. Cualquier otra cosa hace fallar la prueba.
 *  B) la propia heuristica detecta la linea vulnerable de antes (autoprueba) y no da por buenas variantes con una rama sin escapar.
 *  C) CommentTools: lista blanca de parametros, URL relativa, origen fiable, enlace permanente relativo, con cargas de ataque.
 *  D) la plantilla, la vista y los formularios ya no imprimen la URL de la peticion, el anfitrion ni parametros desconocidos.
 * La parte dinamica (el Joomla real, Chromium sin dialogos, con y sin cache) esta en tests/joomla-live/20-xss.sh.
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
$root = dirname(__DIR__);
$src  = $root . '/src';
$lee  = fn(string $f): string => (string) file_get_contents($f);

// ---------------------------------------------------------------- A) y B): heuristica
/** Prefijos de expresion que son seguros por construccion (se comparan con la expresion sin espacios sobrantes). */
const SEGURAS_PREFIJO = [
	'$this->escape(' => 'escapado de Joomla (htmlspecialchars)',
	'htmlspecialchars(' => 'escapado',
	'htmlentities(' => 'escapado',
	'$e(' => 'cierre local que llama a htmlspecialchars (definido en la misma plantilla; lo comprueba la parte A2)',
	'$url(' => 'cierre local: $e(Route::_(...)) (A2)',
	'(int)' => 'conversion a entero (tratada aparte)',
	"HTMLHelper::_('grid.checkall'" => 'casilla de Joomla',
	"HTMLHelper::_('grid.id'" => 'casilla de Joomla con el id entero',
	"HTMLHelper::_('searchtools.sort'" => 'cabecera ordenable de Joomla (claves de idioma y nombres de columna fijos)',
	'Text::_(' => 'cadena de idioma (texto del paquete, no de la peticion)',
	'Text::sprintf(' => 'cadena de idioma con argumentos: sus argumentos los revisa tests/13-escape-salida.php',
	'Text::plural(' => 'idem (tests/13)',
	'I::icon(' => 'icono SVG propio con nombre de una lista cerrada',
	'I::sprite()' => 'hoja de SVG propia',
	'ReactionIcons::svg(' => 'icono SVG propio',
	'PanelUi::themeSwitcher()' => 'HTML propio con cadenas de idioma escapadas',
	"HTMLHelper::_('form.token')" => 'campo oculto del token de Joomla',
	"HTMLHelper::_('engage.processCommentTextForDisplay'" => 'texto del comentario ya pasado por HtmlFilter/HTML Purifier (tests 03, 04, 07)',
	"HTMLHelper::_('engage.textExcerpt'" => 'extracto de texto ya filtrado; desde la 0.8.1 escapa lo que queda de "<" y "&"',
	"HTMLHelper::_('engage.published'" => 'boton de estado de Joomla',
	"HTMLHelper::_('engage.date'" => 'fecha formateada',
	'LayoutHelper::render(' => 'capa de Joomla/propia (sus salidas se analizan aqui tambien)',
	'$this->loadTemplate(' => 'subplantilla (se analiza aqui tambien)',
	'$this->loadPosition(' => 'modulos de Joomla de la posicion (salen de su propia plantilla)',
	'$this->form->renderFieldset(' => 'campos del formulario de Joomla (escapan ellos)',
	'$this->pagination->getPagesLinks()' => 'paginacion de Joomla (sus enlaces los comprueba la prueba en el Joomla real con payloads en la URL)',
	'$this->pagination->getListFooter()' => 'paginacion de Joomla (idem)',
	'$field->renderField()' => 'campo del formulario de Joomla',
	'$this->badgesHtml(' => 'HTML propio: solo claves de insignia de una lista cerrada y cadenas de idioma escapadas (tests/32)',
	'$this->reactionsHtml(' => 'HTML propio con textos de idioma y el id como entero (tests/30)',
	'$this->copyButtonHtml(' => 'HTML propio: el enlace va por htmlspecialchars y el id como entero (tests/32)',
	'json_encode(' => 'JSON de respuesta (no HTML); se comprueba abajo que lleva JSON_HEX_TAG|AMP|APOS|QUOT',
	'base64_encode(' => 'alfabeto base64: sin comillas ni angulos',
	'number_format(' => 'numero',
	'Meta::getNumCommentsForAsset(' => 'entero',
	'sprintf(\'%0.2fM\'' => 'numero formateado',
];
/** Expresiones concretas permitidas, por fichero (ruta relativa a src/), cada una con su razon. */
const LISTA_BLANCA = [
	'component/frontend/tmpl/comments/default_list.php' => [
		'$level' => 'contador entero del bucle for',
		'$comment->id' => 'id de la tabla: entero autonumerico',
		'$commentDate->toISO8601(false)' => 'fecha ISO 8601 generada por Joomla (cifras, guiones, dos puntos)',
		'$commentDate->format(Text::_(\'DATE_FORMAT_LC2\'), true)' => 'fecha formateada por Joomla con un formato de idioma',
		'$avatarAttrs' => 'atributos construidos arriba en la plantilla con htmlspecialchars en cada valor',
		'$bsCommentStateClass' => 'una de tres clases fijas elegida por el estado',
		'($comment->depth < $this->maxLevel) ? $comment->id : $parentIds[$this->maxLevel - 1]' => 'ids enteros de la tabla',
		'$copyHtml' => 'resultado de copyButtonHtml() (HTML propio ya escapado)',
		'$this->badgesHtml($badges)' => 'ver SEGURAS_PREFIJO',
	],
	'component/frontend/tmpl/comments/default.php' => [
		'$headerHtml' => 'cabecera de recuento: Text::plural con el titulo del contenido escapado (tests/13)',
		'$sortActive ? \' is-active\' : \'\'' => 'literales', '$sortActive ? \' aria-current="true"\' : \'\'' => 'literales',
		'$favView ? \' is-active\' : \'\'' => 'literales', '$favView ? \' aria-current="true"\' : \' hidden\'' => 'literales',
		'Text::_($favView ? \'COM_ENGAGE_FAV_SHOW_ALL\' : \'COM_ENGAGE_FAV_ONLY\')' => 'traduccion de una clave fija',
		'ReactionIcons::svg(\'favorite\')' => 'icono propio',
	],
	'component/frontend/tmpl/comments/default_form.php' => [
		'Route::_(\'index.php?option=com_engage&task=comment.save\')' => 'ruta fija',
		'$badUx ? \'d-none\' : \'\'' => 'literales', '$badUx ? \'display: none;\' : \'\'' => 'literales',
	],
	'component/frontend/tmpl/comment/edit.php' => [
		'Route::_(\'index.php?option=com_engage&task=comment.save\')' => 'ruta fija',
		'$this->item->id' => 'id entero de la tabla',
	],
	'component/frontend/tmpl/comments/default_login.php' => [
		'$moduleContent' => 'salida del modulo de inicio de sesion de Joomla (su propia plantilla)',
		'$positionContent' => 'salida de los modulos de una posicion de Joomla',
	],
	'modules/site/engage_latest/tmpl/default.php' => [
		'$commentBody' => 'texto del comentario pasado por processCommentTextForDisplay (HtmlFilter/Purifier)',
	],
	'component/backend/tmpl/comments/default.php' => [
		'$i % 2' => 'entero', '$item->id' => 'id entero de la tabla', '$ip' => 'ya escapado con $this->escape() al construirlo (con <wbr>)',
		'$excerpt' => 'extracto del texto del comentario ya pasado por HTML Purifier', '$processedComment' => 'texto pasado por HTML Purifier',
		'$jBrowser->isMobile() ? \'mobile\' : \'desktop\'' => 'literales', '$jBrowser->isMobile() ? \'mobile-alt\' : \'desktop\'' => 'literales',
		'!empty($item->ip) ? \'flex-shrink-1\' : \'\'' => 'literales',
		'Text::_(\'COM_ENGAGE_COMMENTS_LBL_BROWSERTYPE_\' . ($jBrowser->isMobile() ? \'mobile\' : \'desktop\'))' => 'clave de idioma con sufijo fijo',
		'Route::_(\'index.php?option=com_engage&view=comments\')' => 'ruta fija',
		'Route::_(\'index.php?option=com_engage&view=comments&filter[search]=ip:\' . urlencode($item->ip) . \'&limitstart=0\')' => 'la IP va con urlencode',
		'Route::_(\'index.php?option=com_engage&view=comment&task=edit&id=\' . $item->id)' => 'id entero',
		'Route::_(\'index.php?option=com_engage&view=comments&filter.asset_id=\' . $item->asset_id . \'&limitstart=0\')' => 'asset_id entero de la tabla',
		'Route::_(\'index.php?option=com_engage&view=comments&filter.asset_id=&limitstart=0\')' => 'ruta fija',
		'Text::_(\'JSEARCH_FILTER_LABEL\') . \' \' . $this->escape($item->ip)' => 'la IP va escapada',
		'Factory::getDate($item->created)->format(Text::_(\'DATE_FORMAT_LC2\'), true, true)' => 'fecha formateada',
		'LayoutHelper::render(\'joomla.searchtools.default\', [\'view\' => $this])' => 'capa de Joomla',
	],
	'component/backend/tmpl/comments/emptystate.php' => [],
	'component/backend/layout/akeeba/engage/user.php' => [
		'$email' => 'ya escapado con $this->escape() al construirlo (con <wbr>)',
	],
	'component/backend/tmpl/comment/edit.php' => [
		'Route::_(\'index.php?option=com_engage&view=comment&layout=edit&id=\' . $this->item->id)' => 'id entero',
		'Text::_($info->label)' => 'clave de idioma de una lista fija del formulario',
	],
	'component/backend/tmpl/emailtemplates/default.php' => [
		'Route::_(\'index.php?option=com_mails&filter[extension]=com_engage\')' => 'ruta fija',
		'Route::_(\'index.php?option=com_engage&view=Emailtemplates&task=updateEmails&\' . $token . \'=1\')' => 'token de Joomla (hex)',
		'Route::_(\'index.php?option=com_engage&view=Emailtemplates&task=resetEmails&\' . $token . \'=1\')' => 'token de Joomla (hex)',
	],
	'component/backend/tmpl/common/errorhandler.php' => [
		'$db->getName()' => 'texto del controlador de base de datos (constante)', '$db->getServerType()' => 'idem', '$db->getVersion()' => 'idem',
		'$db->getCollation()' => 'idem', '$db->getConnectionCollation()' => 'idem',
		'function_exists(\'memory_get_peak_usage\') ? sprintf(\'%0.2fM\', (memory_get_peak_usage() / 1024 / 1024)) : \'N/A\'' => 'numero',
		'function_exists(\'ini_get\') ? htmlentities(ini_get(\'memory_limit\')) : \'N/A\'' => 'escapado', 'function_exists(\'ini_get\') ? htmlentities(ini_get(\'max_execution_time\')) : \'N/A\'' => 'escapado',
	],
	'plugins/content/engage/layouts/akeeba/engage/content/article.php' => ['$numComments' => 'entero devuelto por getTotal()'],
	'plugins/content/engage/layouts/akeeba/engage/content/category.php' => ['$numComments' => 'entero devuelto por getTotal()'],
	'plugins/content/engage/layouts/akeeba/engage/content/featured.php' => ['$numComments' => 'entero devuelto por getTotal()'],
	'component/backend/tmpl/controlpanel/default.php' => [
		'$quickToken' => 'token de Joomla (hex) para las acciones rapidas',
	],
];
/** Ficheros PHP de src que se analizan (todos menos vendor). */
function ficheros(string $src): array
{
	$out = [];
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $f) {
		$p = $f->getPathname();
		if (str_ends_with($p, '.php') && !str_contains($p, '/vendor/')) { $out[] = $p; }
	}
	sort($out);
	return $out;
}
/** Salidas de un codigo: lista de [linea, expresion] de cada `<?=`, echo y print. */
function salidas(string $code): array
{
	$toks = token_get_all($code); $n = count($toks); $out = [];
	for ($i = 0; $i < $n; $i++) {
		$t = $toks[$i];
		if (is_array($t) && in_array($t[0], [T_OPEN_TAG_WITH_ECHO, T_ECHO, T_PRINT], true)) {
			$expr = ''; $depth = 0;
			for ($j = $i + 1; $j < $n; $j++) {
				$u = $toks[$j];
				if (is_array($u) && $u[0] === T_CLOSE_TAG) { break; }
				$s = is_array($u) ? $u[1] : $u;
				if ($s === '(' || $s === '[' || $s === '{') { $depth++; } elseif ($s === ')' || $s === ']' || $s === '}') { $depth--; }
				if ($s === ';' && $depth <= 0) { break; }
				$expr .= $s;
			}
			$out[] = [$t[2], trim(preg_replace('/\s+/', ' ', $expr))];
		}
	}
	return $out;
}
/** Parte una expresion por un operador de primer nivel (fuera de parentesis, corchetes, llaves y cadenas). */
function partir(string $e, string $op): array
{
	$out = []; $d = 0; $ini = 0; $n = strlen($e); $q = '';
	for ($i = 0; $i < $n; $i++) {
		$ch = $e[$i];
		if ($q !== '') { if ($ch === '\\') { $i++; } elseif ($ch === $q) { $q = ''; } continue; }
		if ($ch === '\'' || $ch === '"') { $q = $ch; continue; }
		if ($ch === '(' || $ch === '[' || $ch === '{') { $d++; } elseif ($ch === ')' || $ch === ']' || $ch === '}') { $d--; }
		elseif ($d === 0 && $ch === $op && ($op !== '?' || ($e[$i + 1] ?? '') !== '?') && ($op !== '?' || ($e[$i - 1] ?? '') !== '?')) { $out[] = trim(substr($e, $ini, $i - $ini)); $ini = $i + 1; }
	}
	$out[] = trim(substr($e, $ini));
	return $out;
}
/** Una expresion es un literal de texto. */
function esLiteral(string $e): bool
{
	return (bool) preg_match('/^(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")$/s', $e);
}
/** Posicion justo despues del parentesis que cierra el primero que aparece en $e (o -1). */
function finLlamada(string $e): int
{
	$p = strpos($e, '('); if ($p === false) { return -1; }
	$d = 0; $q = ''; $n = strlen($e);
	for ($i = $p; $i < $n; $i++) {
		$ch = $e[$i];
		if ($q !== '') { if ($ch === '\\') { $i++; } elseif ($ch === $q) { $q = ''; } continue; }
		if ($ch === '\'' || $ch === '"') { $q = $ch; continue; }
		if ($ch === '(') { $d++; } elseif ($ch === ')') { $d--; if ($d === 0) { return $i + 1; } }
	}
	return -1;
}
/** ¿Es segura por construccion? Devuelve la razon o null. (La lista blanca por fichero se mira aparte.) */
function segura(string $e): ?string
{
	$e = trim($e);
	if ($e === '') { return 'vacia'; }
	if (esLiteral($e)) { return 'literal'; }
	if (preg_match('/^(?:JVERSION|PHP_VERSION|PHP_OS|PHP_SAPI)$/D', $e)) { return 'constante de version (no viene de la peticion)'; }
	// Ternario de primer nivel: las DOS ramas deben ser seguras (la condicion no se imprime)
	$q = partir($e, '?');
	if (count($q) === 2) {
		$ramas = partir($q[1], ':');
		if (count($ramas) === 2) { return (segura($ramas[0]) !== null && segura($ramas[1]) !== null) ? 'ternario de ramas seguras' : null; }
		return null;
	}
	// Concatenacion: cada trozo debe ser seguro
	$trozos = partir($e, '.');
	if (count($trozos) > 1) {
		foreach ($trozos as $t) { if (segura($t) === null) { return null; } }
		return 'concatenacion de trozos seguros';
	}
	// Parentesis que envuelven toda la expresion
	if ($e[0] === '(' && finLlamada($e) === strlen($e) && !str_starts_with($e, '(int)')) { return segura(substr($e, 1, -1)); }
	// Conversion a entero de algo sin concatenar
	if (preg_match('/^\(int\)\s*[^.]*$/', $e) && !preg_match('/[;`$]\s*\(?\s*(?:include|require)/', $e)) { return 'conversion a entero'; }
	foreach (SEGURAS_PREFIJO as $pre => $why) {
		if (!str_starts_with($e, $pre) || $pre === '(int)') { continue; }
		$fin = finLlamada($e);
		if ($fin === -1 && !str_ends_with($pre, ')')) { continue; }
		// La llamada tiene que ocupar TODA la expresion (lo de detras ya se partio por concatenacion o ternario)
		if ($fin === strlen($e) || str_ends_with($pre, ')') && $pre === $e) { return $why; }
	}
	return null;
}

echo "A) Toda salida HTML de src (sin vendor) es un escapado, una traduccion, un entero, un literal o esta en la lista blanca justificada\n";
$total = 0; $porLista = 0; $malas = [];
foreach (ficheros($src) as $p) {
	$rel = substr($p, strlen($src) + 1);
	foreach (salidas($lee($p)) as [$linea, $expr]) {
		$total++;
		if (segura($expr) !== null) { continue; }
		if (isset(LISTA_BLANCA[$rel][$expr])) { $porLista++; continue; }
		$malas[] = "$rel:$linea: " . substr($expr, 0, 110);
	}
}
t_ok($total > 400, "se analizaron $total salidas (<?=, echo, print) de src");
t_ok($malas === [], 'ninguna salida sin escapar ni justificar' . ($malas ? "\n        " . implode("\n        ", $malas) : '') . " (con lista blanca: $porLista)");
// la lista blanca no acumula entradas muertas
$vivas = [];
foreach (ficheros($src) as $p) { $rel = substr($p, strlen($src) + 1); foreach (salidas($lee($p)) as [, $expr]) { $vivas[$rel][$expr] = true; } }
$muertas = [];
foreach (LISTA_BLANCA as $f => $es) { foreach ($es as $expr => $why) { if (empty($vivas[$f][$expr])) { $muertas[] = "$f: " . substr($expr, 0, 70); } } }
t_ok($muertas === [], 'la lista blanca no tiene entradas que ya no existan' . ($muertas ? "\n        " . implode("\n        ", $muertas) : ''));
$sinRazon = 0; foreach (LISTA_BLANCA as $es) { foreach ($es as $why) { if (trim((string) $why) === '') { $sinRazon++; } } }
t_ok($sinRazon === 0, 'ninguna entrada de la lista blanca sin razon');

echo "A2) Los cierres \$e() y \$url() de las plantillas del panel escapan de verdad\n";
foreach (['controlpanel', 'settings'] as $v) {
	$t = $lee("$src/component/backend/tmpl/$v/default.php");
	t_ok((bool) preg_match('/\$e\s*=\s*static fn\(\$s\): string => htmlspecialchars\(\(string\) \$s, ENT_QUOTES \| ENT_SUBSTITUTE, \'UTF-8\'\);/', $t), "$v: \$e = htmlspecialchars con ENT_QUOTES");
	t_ok((bool) preg_match('/\$url\s*=\s*static fn\(string \$u\): string => \$e\(Route::_\(\$u, false\)\);/', $t), "$v: \$url = \$e(Route::_(..))");
}
echo "A3) Las respuestas JSON llevan JSON_HEX_* y X-Content-Type-Options\n";
foreach (['component/frontend/src/Controller/ReactionsController.php', 'component/backend/src/Controller/SettingsController.php'] as $f) {
	$t = $lee("$src/$f");
	t_ok(str_contains($t, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT') && str_contains($t, 'application/json') && str_contains($t, 'X-Content-Type-Options: nosniff'), "$f: JSON con JSON_HEX_*, tipo application/json y nosniff");
}

echo "B) Autoprueba de la heuristica: la linea vulnerable de antes se detecta\n";
$vieja = '($favView && ($ctxUrl = $this->commentPermalink((int) $comment->id)) !== \'\') ? htmlspecialchars($ctxUrl, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\') : $tempUri->toString()';
t_ok(segura($vieja) === null, 'ternario con una rama escapada y otra `$tempUri->toString()` NO se da por segura');
foreach (['$tempUri->toString()', '$_SERVER[\'REQUEST_URI\']', '$this->input->get(\'x\')', '$uri->toString()', 'Uri::getInstance()->toString()', '$comment->name', '$_GET[\'a\']', 'Text::_(\'X\') . $_GET[\'a\']'] as $mala) {
	t_ok(segura($mala) === null, 'se marca: ' . $mala);
}
foreach (['$this->escape($x)', 'htmlspecialchars((string) $x, ENT_QUOTES, \'UTF-8\')', '(int) $x', 'Text::_(\'COM_ENGAGE_X\')', "'literal'", '$a ? \'x\' : \'y\''] as $buena) {
	t_ok(segura($buena) !== null, 'se admite: ' . $buena);
}
t_ok(segura('htmlspecialchars($a) . $b') === null, 'una llamada segura seguida de una variable concatenada sin escapar NO es segura');
t_ok(segura('$this->escape($a) . \' px\'') !== null, 'una llamada segura seguida de un literal si');
// la linea original tal cual estaba en el commit anterior, sacada de git, es marcada por el analizador sobre un fichero real
$orig = trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git show d4921cc:src/component/frontend/tmpl/comments/default_list.php 2>/dev/null'));
if ($orig !== '') {
	$exprs = array_column(salidas($orig), 1);
	$flag = array_filter($exprs, fn($x) => segura($x) === null && !isset(LISTA_BLANCA['component/frontend/tmpl/comments/default_list.php'][$x]));
	t_ok(count(array_filter($flag, fn($x) => str_contains($x, '$tempUri->toString()'))) === 1, 'sobre default_list.php de la 0.8.0 (d4921cc) el analizador marca exactamente la salida `$tempUri->toString()` (y ninguna otra de las nuevas)');
} else {
	echo "  (sin git o sin el commit d4921cc: autoprueba sobre el fichero real omitida)\n";
}

// ---------------------------------------------------------------- C) CommentTools
echo "C) CommentTools: lista blanca de parametros, URL relativa, origen fiable\n";
require_once "$src/component/backend/src/Helper/CommentTools.php";
require_once "$src/component/backend/src/Helper/ListOrdering.php";
use Akeeba\Component\Engage\Administrator\Helper\CommentTools as T;

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ataques = [
	'"><script>alert(document.domain)</script>', "'><script>alert(1)</script>", '"><svg onload=alert(1)>', 'javascript:alert(1)', '&quot;&gt;&lt;script&gt;',
	"a\nb", "a\r\nSet-Cookie: x=1", '" onmouseover="alert(1)', "' onfocus='alert(1)", '%22%3E%3Cscript%3Ealert(1)%3C/script%3E', '</script><script>alert(1)</script>',
	'//evil.example/x', '\\\\evil.example', "\0", str_repeat('A', 5000), '<img src=x onerror=alert(1)>', '%00', '${7*7}', '{{7*7}}',
];
$bad = 0; $out = [];
foreach ($ataques as $a) {
	foreach (['x', 'p', 'akengage_sort', 'akengage_fav', 'akengage_limitstart', 'akengage_limit', 'akengage_cid', 'limitstart', 'cid', 'Itemid', 'id', 'catid', 'lang', 'view', 'option', 'start', 'layout'] as $k) {
		$q = $k . '=' . rawurlencode($a) . '&id=1&catid=8';
		$u = T::relativeUrl('/index.php/articulo?' . $q);
		$p = T::permalinkRelative('/index.php/articulo?' . $q, 7);
		$c = T::cleanUrl('https://evil.example/index.php?' . $q);
		foreach ([$u, $p] as $r) {
			if (preg_match('/["\'<>`\s\\\\]/', $r) || stripos($r, 'script') !== false || stripos($r, 'onerror') !== false || str_contains($r, '//') || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $r)) { $bad++; $out[] = substr($r, 0, 100); }
		}
		if (preg_match('/["\'<>`\s\\\\]/', $c) || stripos($c, 'script') !== false || stripos($c, 'onerror') !== false) { $bad++; $out[] = substr($c, 0, 100); }
	}
}
t_ok($bad === 0, 'ninguna combinacion de parametro x carga de ataque produce comillas, angulos, espacios, script, esquema o doble barra' . ($out ? ': ' . implode(' | ', array_slice($out, 0, 3)) : ''));
t_ok(T::relativeUrl('/index.php/a?x=%22%3E%3Cscript%3E&p=ATACANTE&id=1&catid=8&Itemid=101') === '/index.php/a?id=1&catid=8&Itemid=101', 'parametros desconocidos fuera (x, p); id, catid e Itemid se conservan');
t_ok(T::relativeUrl('/index.php?option=com_content&view=article&id=1:mi-articulo&catid=8&lang=es-ES') === '/index.php?option=com_content&view=article&id=1%3Ami-articulo&catid=8&lang=es-ES', 'una URL normal de Joomla pasa entera');
t_ok(T::relativeUrl('/a?akengage_sort=top&akengage_fav=1&akengage_limitstart=20&akengage_limit=10&akengage_cid=5') === '/a?akengage_sort=top&akengage_fav=1&akengage_limitstart=20&akengage_limit=10&akengage_cid=5', 'los parametros propios validos se conservan');
t_ok(T::relativeUrl('/a?akengage_sort=zzz&akengage_fav=2&akengage_limitstart=-1&akengage_limit=99999&akengage_cid=1a') === '/a', 'los propios con valor no valido se descartan (sort fuera de newest|oldest|top, fav distinto de 1, numeros fuera de forma)');
t_ok(T::relativeUrl('/a?id=1&id=2&id[]=3&id%5B%5D=4') === '/a?id=1', 'nombre repetido: solo el primero; los de matriz fuera');
t_ok(T::relativeUrl('//evil.example/x?id=1') === '?id=1' && T::relativeUrl('https://evil.example/x?id=1') === '?id=1' && T::relativeUrl('javascript:alert(1)') === '', 'ruta con // o con esquema: se descarta la ruta');
t_ok(T::relativeUrl('/a?id=1#"><script>') === '/a?id=1', 'el fragmento de la peticion no pasa');
t_ok(T::permalinkRelative('/index.php/a?x=1&akengage_sort=top&akengage_cid=9&id=3', 12) === '/index.php/a?id=3&akengage_cid=12#akengage-comment-12', 'enlace permanente: ruta + parametros validos sin los volatiles + akengage_cid + ancla');
t_ok(T::permalinkRelative('/a', 0) === '' && T::permalinkRelative('/a', -3) === '', 'id no positivo: vacio');
t_ok(!preg_match('~^[a-z]+:|^//~i', T::permalinkRelative('/a?id=1', 5)), 'el enlace permanente no lleva esquema ni anfitrion');
t_ok(T::cleanUrl('http://evil.example/ruta?akengage_sort=top&x=1&id=4&akengage_limitstart=5') === 'http://evil.example/ruta?id=4', 'cleanUrl: sin volatiles ni desconocidos (el origen, si lo hubiera, se queda como entra: el llamador usa solo trustedOrigin)');
t_ok(T::trustedOrigin('https://www.ejemplo.es') === 'https://www.ejemplo.es' && T::trustedOrigin('https://www.ejemplo.es/sub/') === 'https://www.ejemplo.es' && T::trustedOrigin('http://localhost:8080') === 'http://localhost:8080', 'origen fiable: el del live_site (sin ruta)');
foreach (['', '   ', 'javascript:alert(1)', 'https://evil"><script>', "https://a.example/\nx", 'ftp://x.example', '//x.example', 'https://u:p@x.example', 'x.example'] as $ls) {
	t_ok(T::trustedOrigin($ls) === '', 'origen no fiable ' . json_encode($ls) . ': vacio (no hay canonical)');
}

// ---------------------------------------------------------------- D) Plantillas y vista
echo "D) La lista, la vista y los formularios ya no imprimen la URL de la peticion\n";
$c    = "$src/component";
$lf   = fn(string $f): string => str_replace("\r\n", "\n", $lee($f));
$tplL = $lf("$c/frontend/tmpl/comments/default_list.php");
$tplF = $lf("$c/frontend/tmpl/comments/default_form.php");
$view = $lf("$c/frontend/src/View/Comments/HtmlView.php");
t_ok(!str_contains($tplL, 'tempUri') && !str_contains($tplL, 'replyToUri') && !str_contains($tplL, 'Uri::getInstance()'), 'default_list.php no usa Uri::getInstance(): ni $tempUri ni $replyToUri');
t_ok(preg_match('/href="<\?= htmlspecialchars\(\$this->commentPermalink\(\(int\) \$comment->id\), ENT_QUOTES \| ENT_SUBSTITUTE, \'UTF-8\'\) \?>"/', $tplL) === 1, 'el enlace de la fecha: htmlspecialchars(commentPermalink(id))');
t_ok(!str_contains($tplF, "'scheme', 'user', 'pass', 'host'") && str_contains($tplF, 'CommentTools::relativeUrl('), 'el returnurl del formulario es relativo y validado (sin anfitrion)');
t_ok(!preg_match('/Uri::getInstance\(\)->toString\(\)/', $view) && !str_contains($view, "toString(['scheme', 'host', 'port', 'path', 'query'])"), 'la vista no imprime la URL absoluta de la peticion (con el Host)');
t_ok(str_contains($view, "trustedOrigin((string) Factory::getApplication()->get('live_site'") && str_contains($view, '$origin === \'\''), 'el canonical solo con el live_site de Joomla; sin el, no se anade');
t_ok(!preg_match('/^[^\n]*(?:getHost|HTTP_HOST|SERVER_NAME)/m', $view), 'la vista no lee el anfitrion de la peticion');
foreach (['frontend/tmpl/comments/default_list.php', 'frontend/tmpl/comments/default.php', 'frontend/tmpl/comments/default_form.php', 'frontend/tmpl/comment/edit.php', 'frontend/src/View/Comments/HtmlView.php', '../modules/site/engage_latest/tmpl/default.php', '../plugins/content/engage/layouts/akeeba/engage/content/article.php', '../plugins/content/engage/layouts/akeeba/engage/content/category.php', '../plugins/content/engage/layouts/akeeba/engage/content/featured.php'] as $f) {
	$t = $lee("$c/$f");
	t_ok(!preg_match('/\$_(SERVER|GET|POST|REQUEST|COOKIE)\b/', $t), "$f: no lee \$_SERVER/\$_GET/\$_POST/\$_REQUEST/\$_COOKIE");
}
foreach (['article', 'category', 'featured'] as $l) {
	$t = $lf("$src/plugins/content/engage/layouts/akeeba/engage/content/$l.php");
	t_ok(str_contains($t, "htmlspecialchars((string) \$uri->toString(['path', 'query', 'fragment'])") && !str_contains($t, '<?= $uri->toString() ?>'), "layout $l: el enlace del contador va relativo (sin Host) y escapado");
}
$mod = $lf("$src/modules/site/engage_latest/tmpl/default.php");
t_ok(substr_count($mod, "toString(['path', 'query', 'fragment'])") === 2 && !str_contains($mod, 'Uri->toString()') && !preg_match('/\$comment(s)?Uri->toString\(\)/', $mod), 'modulo de ultimos comentarios: enlaces relativos y escapados');
t_ok(str_contains($lf("$c/backend/src/Mixin/ControllerReturnURLTrait.php"), "['', 'http', 'https']"), 'el returnurl solo admite http(s) o sin esquema (javascript:index.php;... pasaria isInternal)');
t_fin();
