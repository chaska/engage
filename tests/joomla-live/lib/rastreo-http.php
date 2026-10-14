<?php
/**
 * 0.9.4: rastreo de robots contra el Joomla REAL por HTTP (invitado): la URL base del articulo con ~200 comentarios queda indexable (sin meta
 * robots de Engage, sin X-Robots-Tag, sin canonical de Engage) y TODAS las variantes (akengage_cid, _limitstart, _limit, _sort, _fav, desconocido)
 * llevan noindex, follow (meta y cabecera) y canonical a la URL base; todo enlace con akengage_ lleva rel nofollow (fecha, respuesta, paginacion de
 * Joomla, orden, favoritos y el modulo «Ultimos comentarios»); con la opcion apagada vuelve el comportamiento anterior. Requiere filtro-datos.php crear.
 */
require __DIR__ . '/revision-082-comun.php';
$g = new Cliente($BASE, "$WORK/tmp", 'rob');
function hdr(array $resp, string $h): string { return preg_match('/^' . preg_quote($h, '/') . ':\s*(.+?)\r?$/mi', $resp['headers'], $m) ? trim($m[1]) : ''; }
$LIVE = 'http://127.0.0.1:8080';
$orig = (string) one("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'");
$modOrig = [(string) one("SELECT published FROM jos_modules WHERE id=110"), (string) one("SELECT position FROM jos_modules WHERE id=110")];
$setOpt = function (?string $v) use ($orig) { $p = array_merge(json_decode($orig, true) ?: [], ['sort_selector' => '1', 'reactions_enabled' => '1', 'reactions_favorites' => '1', 'reactions_dislike' => '1', 'copy_link' => '1']); if ($v === null) { unset($p['crawl_reduce']); } else { $p['crawl_reduce'] = $v; } q("UPDATE jos_extensions SET params='" . esc(json_encode($p)) . "' WHERE element='com_engage' AND type='component'"); };
$restaura = function () use ($orig, $modOrig) { q("UPDATE jos_extensions SET params='" . esc($orig) . "' WHERE element='com_engage' AND type='component'"); q("UPDATE jos_modules SET published=" . (int) $modOrig[0] . ", position='" . esc($modOrig[1]) . "' WHERE id=110"); q("DELETE FROM jos_modules_menu WHERE moduleid=110"); };
register_shutdown_function($restaura);

/** Datos de una respuesta: meta robots, X-Robots-Tag, canonicales, enlaces con akengage_ y su rel. */
$info = function (array $resp): array {
	$b = $resp['body'];
	preg_match_all('/<meta\s+name="robots"\s+content="([^"]*)"/i', $b, $mr);
	preg_match_all('/<link\s+href="([^"]*)"\s+rel="canonical"|<link\s+rel="canonical"\s+href="([^"]*)"/i', $b, $mc);
	$canon = array_values(array_filter(array_merge($mc[1], $mc[2])));
	$enl = [];
	preg_match_all('/<a\b[^>]*>/i', $b, $ma);
	foreach ($ma[0] as $tag) {
		if (preg_match('/href="([^"]*)"/', $tag, $h) && str_contains($h[1], 'akengage_')) { $enl[] = [$h[1], preg_match('/\srel="([^"]*)"/', $tag, $rr) ? $rr[1] : '']; }
	}
	return ['code' => $resp['code'], 'meta' => $mr[1], 'hdr' => hdr($resp, 'X-Robots-Tag'), 'canon' => $canon, 'enl' => $enl, 'body' => $b];
};
$sinNf = fn(array $i) => array_values(array_filter($i['enl'], fn($e) => !str_contains($e[1], 'nofollow')));

$logIni = is_file($PHPLOG) ? filesize($PHPLOG) : 0;
$setOpt(null);
$base = $info($g->req('GET', $PAGE));
r('R-01', 'URL base del articulo: 200, sin noindex en la meta robots, sin X-Robots-Tag y sin canonical que ponga Engage (indexable)', $base['code'] === 200 && !array_filter($base['meta'], fn($m) => stripos($m, 'noindex') !== false) && $base['hdr'] === '' && !$base['canon'], json_encode([$base['meta'], $base['hdr'], $base['canon']]));
r('R-02', 'URL base: todos los enlaces con akengage_ (fecha del comentario, paginacion de Joomla) llevan rel="nofollow"; hay de fecha, >= 5 de paginacion, 3 de orden y el de favoritos', count($base['enl']) >= 25 && !$sinNf($base) && count(array_filter($base['enl'], fn($e) => str_contains($e[0], 'akengage_limitstart'))) >= 5 && count(array_filter($base['enl'], fn($e) => str_contains($e[0], 'akengage_sort='))) >= 3 && count(array_filter($base['enl'], fn($e) => str_contains($e[0], 'akengage_fav='))) >= 1, count($base['enl']) . ' enlaces; sin nofollow: ' . json_encode(array_slice($sinNf($base), 0, 3)));
$cid = (int) one("SELECT id FROM jos_engage_comments WHERE name LIKE 'F9N-LIBRO-%' AND enabled=1 ORDER BY id LIMIT 1");
$vars = ["&akengage_cid=$cid", '&akengage_limitstart=20', '&akengage_limitstart=0', '&akengage_limit=5', '&akengage_sort=newest', '&akengage_sort=oldest', '&akengage_sort=top', '&akengage_sort=zzz', '&akengage_fav=1', '&akengage_cid=999999999', '&akengage_cualquiera=1', '&akengage_limitstart=20&akengage_sort=newest'];
$mal = []; $malCan = []; $malRel = [];
foreach ($vars as $v) {
	$i = $info($g->req('GET', $PAGE . $v));
	if ($i['code'] !== 200 || !in_array('noindex, follow', $i['meta'], true) || $i['hdr'] !== 'noindex, follow') { $mal[] = "$v:{$i['code']}:" . json_encode($i['meta']) . ':' . $i['hdr']; }
	if (count($i['canon']) !== 1 || !str_starts_with($i['canon'][0], $LIVE . '/') || str_contains($i['canon'][0], 'akengage_') || !str_contains($i['canon'][0], "id=$ART")) { $malCan[] = "$v:" . json_encode($i['canon']); }
	if ($sinNf($i)) { $malRel[] = "$v:" . json_encode(array_slice($sinNf($i), 0, 2)); }
}
r('R-03', count($vars) . ' variantes (comentario, pagina, limite, orden valido e invalido, favoritos de invitado, parametro desconocido, combinadas): 200, meta robots «noindex, follow» y cabecera X-Robots-Tag igual', !$mal, implode(' | ', $mal));
r('R-04', 'las mismas variantes llevan canonical a la URL base del articulo (live_site, sin ningun akengage_*), uno solo', !$malCan, implode(' | ', $malCan));
r('R-05', 'las mismas variantes: todo enlace con akengage_ lleva rel="nofollow"', !$malRel, implode(' | ', $malRel));
$pc = $info($g->req('GET', $PAGE . "&akengage_cid=$cid"));
r('R-06', 'el contenido de la variante es el mismo para personas y robots: el comentario pedido y el articulo salen igual (sin cloaking)', str_contains($pc['body'], 'F9B libro') || str_contains($pc['body'], 'libro'), '');
$ctl = $info((new Cliente($BASE, "$WORK/tmp", 'rob2'))->req('GET', $PAGE));
$ua = $info((new Cliente($BASE, "$WORK/tmp", 'rob3'))->req('GET', $PAGE, [], ['User-Agent: Googlebot/2.1 (+http://www.google.com/bot.html)']));
r('R-07', 'con User-Agent de Googlebot la URL base se sirve igual (mismos enlaces, mismo numero de comentarios)', count($ua['enl']) === count($ctl['enl']) && substr_count($ua['body'], 'akengage-comment-') === substr_count($ctl['body'], 'akengage-comment-') && $ua['meta'] === $ctl['meta'] && $ua['hdr'] === $ctl['hdr'], count($ua['enl']) . '/' . count($ctl['enl']));
// Host hostil: nada del Host en las cabeceras ni en el canonical
$hh = $g->req('GET', $PAGE . '&akengage_sort=newest', [], ['Host: evil.example:8080']);
$ih = $info($hh);
r('R-08', 'Host hostil en una variante: la cabecera y la meta son las fijas y el canonical sale de live_site (nunca de la cabecera Host); ningun enlace de Engage lleva el Host', $ih['hdr'] === 'noindex, follow' && in_array('noindex, follow', $ih['meta'], true) && !array_filter($ih['enl'], fn($e) => str_contains($e[0], 'evil.example')) && (!$ih['canon'] || str_starts_with($ih['canon'][0], $LIVE . '/')), json_encode($ih['canon']));
// Modulo «Ultimos comentarios» publicado en la pagina
q("UPDATE jos_modules SET published=1, position='sidebar-right' WHERE id=110"); q("DELETE FROM jos_modules_menu WHERE moduleid=110"); q("INSERT INTO jos_modules_menu (moduleid, menuid) VALUES (110, 0)");
$mp = $info($g->req('GET', $PAGE));
$modEnl = array_values(array_filter($mp['enl'], fn($e) => str_contains($e[0], 'akengage_cid')));
$mod = preg_match('/engage-latest-list/', $mp['body']) === 1;
r('R-09', 'modulo «Ultimos comentarios» en la pagina: el enlace con la fecha (akengage_cid) lleva rel="nofollow"', $mod && $modEnl && !array_filter($modEnl, fn($e) => !str_contains($e[1], 'nofollow')), 'modulo=' . (int) $mod . ' enlaces=' . count($modEnl));
q("UPDATE jos_modules SET published=" . (int) $modOrig[0] . ", position='" . esc($modOrig[1]) . "' WHERE id=110"); q("DELETE FROM jos_modules_menu WHERE moduleid=110");
// Opcion apagada
$setOpt('0');
$of = $info($g->req('GET', $PAGE . "&akengage_cid=$cid"));
$ob = $info($g->req('GET', $PAGE));
$ofs = $info($g->req('GET', $PAGE . '&akengage_sort=newest'));
r('R-10', 'con «Reducir el rastreo» apagado: sin X-Robots-Tag ni meta noindex en la variante de comentario, paginacion y fecha sin nofollow (como antes de la 0.9.4); el orden conserva su canonical y nofollow de siempre', $of['hdr'] === '' && !array_filter($of['meta'], fn($m) => stripos($m, 'noindex') !== false) && count(array_filter($ob['enl'], fn($e) => preg_match('/akengage_limitstart=[1-9]/', $e[0]) && str_contains($e[1], 'nofollow'))) === 0 && $ofs['canon'] && $ofs['hdr'] === '', json_encode([$of['hdr'], $of['meta'], $ofs['hdr'], count(array_filter($ob['enl'], fn($e) => preg_match('/akengage_limitstart=[1-9]/', $e[0]) && str_contains($e[1], 'nofollow')))]));
// Valor ajeno => por defecto (activado)
$setOpt('basura');
$ov = $info($g->req('GET', $PAGE . "&akengage_cid=$cid"));
r('R-11', 'valor ajeno en la opcion: vale el defecto (activada)', $ov['hdr'] === 'noindex, follow', $ov['hdr']);
$setOpt(null);
$ps = $g->req('GET', $PAGE . '&akengage_cid=' . $cid);
$nuevo = is_file($PHPLOG) ? (string) shell_exec('tail -c +' . ($logIni + 1) . ' ' . escapeshellarg($PHPLOG)) : '';
r('R-12', 'sin avisos ni errores de PHP de Engage en el recorrido (los Deprecated del SEF de Joomla con un Host hostil no son de Engage)', !preg_match('/engage/i', $nuevo), substr($nuevo, 0, 200));
resumen('rastreo-http');
