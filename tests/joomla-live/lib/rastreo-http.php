<?php
/**
 * 0.9.4 / 0.9.5: rastreo de robots contra el Joomla REAL por HTTP (invitado), con SEF + reescritura y sin SEF, y con cuatro bases fiables: live_site,
 * SIN base (ni live_site, ni opcion, ni aprendida), opcion email_base_url y base aprendida. Con ~200 comentarios (filtro-datos.php crear):
 *  - la URL base amigable es indexable (sin noindex ni X-Robots-Tag) y lleva SU canonical (autocanonical);
 *  - 13 variantes (comentario de la misma pagina y de otra, pagina, limite, orden, favoritos, combinadas, desconocida): 200, noindex+follow en la meta y
 *    en X-Robots-Tag, UNA canonical a la URL amigable limpia (absoluta con base fiable; relativa a la raiz sin ella; nunca la cabecera Host);
 *  - todo enlace con akengage_ lleva nofollow; ningun href con «amp;» (doble escape); la paginacion va por la ruta amigable; la fecha es un ancla;
 *  - Host hostil (incluidos # y ?), Googlebot, opcion apagada o con valor ajeno, modulo «Ultimos comentarios».
 */
require __DIR__ . '/revision-082-comun.php';
$f = @fsockopen('127.0.0.1', 8090, $en, $es, 1);
if (!$f) { echo "NO PROBADO: falta el servidor nginx + php-fpm de lib/nginx-fpm.sh (php -S no sirve: Joomla lo toma por CLI y sin live_site no resuelve las rutas amigables)\n"; exit(2); }
fclose($f);
$BASE = 'http://127.0.0.1:8090'; $LIVE = $BASE;
function hdr(array $resp, string $h): string { return preg_match('/^' . preg_quote($h, '/') . ':\s*(.+?)\r?$/mi', $resp['headers'], $m) ? trim($m[1]) : ''; }
$cfgPath = "$SITE/configuration.php"; $cfgOrig = file_get_contents($cfgPath);
$orig = (string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'");
$modOrig = [(string) one("SELECT published FROM jos_modules WHERE id=110"), (string) one("SELECT position FROM jos_modules WHERE id=110")];
$restaura = function () use ($orig, $modOrig, $cfgPath, $cfgOrig) { file_put_contents($cfgPath, $cfgOrig); q("UPDATE jos_extensions SET params='" . esc($orig) . "' WHERE element='com_engage' AND type='component'"); q("UPDATE jos_modules SET published=" . (int) $modOrig[0] . ", position='" . esc($modOrig[1]) . "' WHERE id=110"); q("DELETE FROM jos_modules_menu WHERE moduleid=110"); };
register_shutdown_function($restaura);
$setOpt = function (array $extra) use ($orig) { $p = array_merge(json_decode($orig, true) ?: [], ['sort_selector' => '1', 'reactions_enabled' => '1', 'reactions_favorites' => '1', 'reactions_dislike' => '1', 'copy_link' => '1', 'email_base_url' => '', 'email_base_learned' => ''], $extra); q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); };
$setCfg = function (string $live, int $sef) use ($cfgPath, $cfgOrig) {
	$c = preg_replace("/public \\\$live_site = '[^']*';/", "public \$live_site = '$live';", $cfgOrig);
	$c = preg_replace('/public \$sef = (true|false);/', 'public $sef = ' . ($sef ? 'true' : 'false') . ';', (string) $c);
	$c = preg_replace('/public \$sef_rewrite = (true|false);/', 'public $sef_rewrite = ' . ($sef ? 'true' : 'false') . ';', (string) $c);
	file_put_contents($cfgPath, $c); usleep(4200000);
};
$logIni = is_file($PHPLOG) ? filesize($PHPLOG) : 0;
q("UPDATE jos_modules SET published=1, position='sidebar-right' WHERE id=110"); q("DELETE FROM jos_modules_menu WHERE moduleid=110"); q("INSERT INTO jos_modules_menu (moduleid, menuid) VALUES (110, 0)");

/** Datos de una respuesta: meta robots, X-Robots-Tag, canonicales, enlaces con su rel. */
$info = function (array $resp): array {
	$b = $resp['body'];
	preg_match_all('/<meta\s+name="robots"\s+content="([^"]*)"/i', $b, $mr);
	preg_match_all('/<link\s+href="([^"]*)"\s+rel="canonical"|<link\s+rel="canonical"\s+href="([^"]*)"/i', $b, $mc);
	$canon = array_map('html_entity_decode', array_values(array_filter(array_merge($mc[1], $mc[2]))));
	$enl = []; $todos = [];
	preg_match_all('/<a\b[^>]*>/i', $b, $ma);
	foreach ($ma[0] as $tag) {
		if (!preg_match('/href="([^"]*)"/', $tag, $h)) { continue; }
		$rel = preg_match('/\srel="([^"]*)"/', $tag, $rr) ? $rr[1] : '';
		$todos[] = $h[1];
		if (str_contains($h[1], 'akengage_') || str_contains($h[1], '#akengage-comment-')) { $enl[] = [$h[1], $rel, $tag]; }
	}
	return ['code' => $resp['code'], 'meta' => $mr[1], 'hdr' => hdr($resp, 'X-Robots-Tag'), 'canon' => $canon, 'enl' => $enl, 'todos' => $todos, 'body' => $b];
};
$sinNf = fn(array $i) => array_values(array_filter($i['enl'], fn($e) => !str_contains($e[1], 'nofollow')));
$ids = fn() => array_map('intval', array_column(q("SELECT id FROM jos_engage_comments WHERE name LIKE 'F9N-LIBRO-%' AND enabled=1 AND asset_id=$GLOBALS[ASSET_PUB] ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'id'));
$cids = $ids(); $cidMisma = $cids[1]; $cidOtra = $cids[count($cids) - 3];

/** Una pasada: configuracion de base fiable + SEF. $origen = origen que debe llevar la canonical ('' = relativa). */
$pasada = function (string $tag, string $live, array $opt, string $origen, int $sef) use ($BASE, $WORK, $PAGE, $ART, $CAT, $setCfg, $setOpt, $info, $sinNf, $cidMisma, $cidOtra) {
	$setCfg($live, $sef); $setOpt($opt);
	$g = fn(string $n) => new Cliente($BASE, "$WORK/tmp", "rob$tag$n");
	$g('w')->req('GET', $PAGE);
	// Direccion amigable del articulo: la que Joomla da al modulo «Ultimos comentarios» (Route::link) sin el comentario. Con SEF, Joomla redirige
	// /index.php?option=... a /?option=...: se pide asi.
	$pedir = $sef ? "/?option=com_content&view=article&id=$ART&catid=$CAT" : $PAGE;
	$mp = $info($g('m')->req('GET', $pedir));
	$amig = ''; $modLinks = [];
	foreach ($mp['enl'] as $e) {
		if (preg_match('/engage-latest/', $mp['body']) && str_contains($e[0], 'akengage_cid=')) {
			$modLinks[] = $e;
			if ($amig === '') { $amig = preg_replace('/[?&]akengage_cid=\d+/', '', html_entity_decode(explode('#', $e[0])[0])); }
		}
	}
	// Sin SEF la direccion de la peticion es la de siempre
	$urlBase = $sef ? $amig : $PAGE;
	r("R-$tag-01", "SEF=" . ($sef ? 'si (reescritura)' : 'no') . ": el modulo da al comentario la direccion " . ($sef ? 'AMIGABLE (/component/content/article/...)' : 'de siempre (/index.php?option=...)') . " del articulo, con nofollow y sin amp;", ($sef ? (str_starts_with($amig, '/component/') && !str_contains($amig, 'option=') && !str_contains($amig, '/index.php')) : true) && $modLinks && !array_filter($modLinks, fn($e) => !str_contains($e[1], 'nofollow') || str_contains(html_entity_decode($e[0]), 'amp;')), $amig . ' | ' . ($modLinks[0][0] ?? ''));
	if ($urlBase === '') { return; }
	$esperada = function (string $reqUrl) use ($origen, $sef) { $p = explode('#', $reqUrl)[0]; $path = explode('?', $p)[0]; $q = explode('?', $p . '?')[1]; parse_str($q, $qs); $keep = []; foreach (['option', 'view', 'id', 'catid', 'Itemid'] as $k) { if (isset($qs[$k])) { $keep[] = "$k=" . $qs[$k]; } } return $origen . $path . ($keep ? '?' . implode('&', $keep) : ''); };
	$exp = $esperada($urlBase);
	$base = $info($g('b')->req('GET', $urlBase));
	r("R-$tag-02", 'URL base: 200, sin noindex ni X-Robots-Tag y UNA canonical a si misma (autocanonical) [' . $exp . ']', $base['code'] === 200 && !array_filter($base['meta'], fn($m) => stripos($m, 'noindex') !== false) && $base['hdr'] === '' && $base['canon'] === [$exp], json_encode([$base['code'], $base['meta'], $base['hdr'], $base['canon']]));
	$fechas = array_values(array_filter($base['enl'], fn($e) => str_contains($e[0], '#akengage-comment-') && !str_contains($e[0], 'akengage_cid') && str_starts_with($e[0], '#')));
	$pagin = array_values(array_filter($base['enl'], fn($e) => preg_match('/akengage_limitstart=[1-9]/', $e[0])));
	r("R-$tag-03", 'URL base: 20 fechas son anclas #akengage-comment-ID con nofollow (ninguna con akengage_cid); la paginacion de Joomla va por la ruta amigable (' . count($pagin) . ' enlaces) con nofollow, sin index.php?view= ni amp;', count($fechas) >= 20 && !array_filter($fechas, fn($e) => !str_contains($e[1], 'nofollow')) && count($pagin) >= 5 && !array_filter($pagin, fn($e) => !str_starts_with(html_entity_decode($e[0]), explode('?', $urlBase)[0]) || ($sef && str_contains($e[0], 'view=article')) || str_contains(html_entity_decode($e[0]), 'amp;') || !str_contains($e[1], 'nofollow')), $pagin[0][0] ?? '');
	r("R-$tag-04", 'URL base: ningun href de la pagina contiene «amp;» tras decodificar una vez, ni queda vacio', !array_filter($base['todos'], fn($h) => str_contains(html_entity_decode($h), 'amp;') || trim($h) === ''), implode(' ', array_slice(array_filter($base['todos'], fn($h) => str_contains(html_entity_decode($h), 'amp;')), 0, 2)));
	$sep = str_contains($urlBase, '?') ? '&' : '?';
	$vars = [$sep . "akengage_cid=$cidMisma", $sep . "akengage_cid=$cidOtra", $sep . 'akengage_limitstart=20', $sep . 'akengage_limitstart=50', $sep . 'akengage_limit=5', $sep . 'akengage_sort=newest', $sep . 'akengage_sort=oldest', $sep . 'akengage_sort=top', $sep . 'akengage_sort=zzz', $sep . 'akengage_fav=1', $sep . 'akengage_cid=999999999', $sep . 'akengage_cualquiera=1', $sep . 'akengage_limitstart=20&akengage_sort=newest'];
	$mal = []; $malCan = []; $malRel = []; $malAmp = [];
	foreach ($vars as $v) {
		$i = $info($g('v')->req('GET', $urlBase . $v));
		if ($i['code'] !== 200 || !in_array('noindex, follow', $i['meta'], true) || $i['hdr'] !== 'noindex, follow') { $mal[] = "$v:{$i['code']}:" . json_encode($i['meta']) . ':' . $i['hdr']; }
		if ($i['canon'] !== [$exp]) { $malCan[] = "$v:" . json_encode($i['canon']); }
		if ($sinNf($i)) { $malRel[] = "$v:" . json_encode(array_slice($sinNf($i), 0, 2)); }
		if (array_filter($i['todos'], fn($h) => str_contains(html_entity_decode($h), 'amp;') || trim($h) === '')) { $malAmp[] = $v; }
	}
	r("R-$tag-05", count($vars) . ' variantes (comentario de la misma pagina y de otra, paginas, limite, orden valido e invalido, favoritos de invitado, desconocido, combinada): 200, noindex+follow en la meta y en X-Robots-Tag', !$mal, implode(' | ', $mal));
	r("R-$tag-06", 'las mismas variantes: UNA canonical = ' . $exp . ' (' . ($origen === '' ? 'relativa a la raiz, sin base fiable' : 'absoluta sobre la base fiable') . ')', !$malCan, implode(' | ', $malCan));
	r("R-$tag-07", 'las mismas variantes: todo enlace con akengage_ lleva nofollow y ningun href tiene «amp;» ni esta vacio', !$malRel && !$malAmp, implode(' | ', array_merge($malRel, $malAmp)));
	$pc = $info($g('c')->req('GET', $urlBase . $sep . "akengage_cid=$cidOtra"));
	r("R-$tag-08", 'comentario de OTRA pagina (akengage_cid): la pagina abre en la que lo contiene y el comentario esta en el HTML (la persona lo ve)', str_contains($pc['body'], "id=\"akengage-comment-$cidOtra\""), '');
	$ctl = $info($g('x')->req('GET', $urlBase)); $ua = $info((new Cliente($BASE, "$WORK/tmp", "rob{$tag}ua"))->req('GET', $urlBase, [], ['User-Agent: Googlebot/2.1 (+http://www.google.com/bot.html)']));
	r("R-$tag-09", 'Googlebot recibe lo mismo que una persona (mismos enlaces, comentarios, meta y canonical; sin cloaking)', count($ua['enl']) === count($ctl['enl']) && substr_count($ua['body'], 'id="akengage-comment-') === substr_count($ctl['body'], 'id="akengage-comment-') && $ua['meta'] === $ctl['meta'] && $ua['canon'] === $ctl['canon'], count($ua['enl']) . '/' . count($ctl['enl']));
	// Host hostil: nada del Host en la canonical ni en los enlaces de Engage
	$mh = [];
	foreach (['evil.example:8080', 'evil.example#x', 'evil.example?x', 'evil.example"onmouseover="alert(1)', 'user@evil.example'] as $h) {
		foreach ([$sep . 'akengage_limitstart=20', ''] as $v) {
			$hh = $g('h')->req('GET', $urlBase . $v, [], ["Host: $h"]); $ih = $info($hh);
			$okc = $ih['canon'] === [$exp] || ($ih['canon'] === [] && $hh['code'] !== 200) ;
			if ($hh['code'] === 200 && str_contains($hh['body'], 'id="akengage-comment-') && ($ih['canon'] !== [$exp] || ($v !== '' && ($ih['hdr'] !== 'noindex, follow' || !in_array('noindex, follow', $ih['meta'], true))) || array_filter($ih['canon'], fn($c) => str_contains($c, 'evil')) || array_filter($ih['enl'], fn($e) => str_contains($e[0], 'evil')))) { $mh[] = "$h$v:" . json_encode($ih['canon']); }
		}
	}
	r("R-$tag-10", 'Host hostil (puerto, #, ?, comillas, usuario@): cuando Joomla sirve el articulo, la canonical y los enlaces de Engage son los mismos y no llevan el Host y la variante conserva noindex y la cabecera (con # o ? Joomla no resuelve la ruta y sirve otra pagina)', !$mh, implode(' | ', $mh));
	// Opcion apagada y valor ajeno
	$setOpt($opt + ['crawl_reduce' => '0']);
	$of = $info($g('o')->req('GET', $urlBase . $sep . "akengage_cid=$cidMisma")); $ob = $info($g('o2')->req('GET', $urlBase));
	r("R-$tag-11", 'opcion apagada: sin X-Robots-Tag, sin meta noindex, sin canonical de Engage en la variante de comentario ni en la base; la paginacion sin nofollow (como antes)', $of['hdr'] === '' && !array_filter($of['meta'], fn($m) => stripos($m, 'noindex') !== false) && $of['canon'] === [] && $ob['canon'] === [] && count(array_filter($ob['enl'], fn($e) => preg_match('/akengage_limitstart=[1-9]/', $e[0]) && str_contains($e[1], 'nofollow'))) === 0, json_encode([$of['hdr'], $of['canon'], $ob['canon']]));
	$setOpt($opt + ['crawl_reduce' => 'basura']);
	$ov = $info($g('o3')->req('GET', $urlBase . $sep . "akengage_cid=$cidMisma"));
	r("R-$tag-12", 'valor ajeno en la opcion: vale el defecto (activada)', $ov['hdr'] === 'noindex, follow' && $ov['canon'] === [$exp], $ov['hdr']);
};

$pasada('A', $LIVE, [], $LIVE, 1);
$pasada('B', '', [], '', 1);
$pasada('C', '', ['email_base_url' => 'https://opcion.example.test'], 'https://opcion.example.test', 1);
$pasada('D', '', ['email_base_learned' => 'https://aprendida.example.test'], 'https://aprendida.example.test', 1);
$pasada('E', '', [], '', 0);
$pasada('F', $LIVE, [], $LIVE, 0);
$nuevo = is_file($PHPLOG) ? (string) shell_exec('tail -c +' . ($logIni + 1) . ' ' . escapeshellarg($PHPLOG)) : '';
r('R-99', 'sin avisos ni errores de PHP de Engage en el recorrido (los Deprecated del SEF de Joomla con un Host hostil no son de Engage)', !preg_match('/engage/i', $nuevo), substr($nuevo, 0, 200));
resumen('rastreo-http');
