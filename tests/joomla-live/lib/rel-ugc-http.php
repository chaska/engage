<?php
/**
 * 0.6.18: rel="nofollow ugc noreferrer" en los enlaces del texto de los comentarios, contra un Joomla REAL por HTTP.
 * Inserta comentarios publicados con enlaces (HTML con y sin rel, comillas simples, BBCode, javascript:) en el articulo
 * publico y comprueba el HTML servido (invitado). Requisitos: 03-sembrar-y-probar.sh ya ejecutado ($WORK/ids.json).
 * Escribe $WORK/resultados-rel-ugc.json. Sale con 1 si algo FALLA. Borra los comentarios de ese articulo al empezar y al acabar.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-8s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [$ev]" : '');
}
$asset = (int) $ids['art_publico_asset'];
$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$asset");
$bodies = [
	'sin_rel'   => '<p>uno <a href="https://ext.example/sin-rel">A</a></p>',
	'nofollow'  => '<p>dos <a rel="nofollow" href="https://ext.example/nofollow">B</a></p>',
	'noopener'  => '<p>tres <a href="https://ext.example/noopener" rel="noopener nofollow">C</a></p>',
	'mayus'     => '<p>cuatro <A HREF="https://ext.example/mayus" REL = " NoFollow ">D</A></p>',
	'simples'   => "<p>cinco <a rel='external' href='https://ext.example/simples?a=1&b=2'>E</a></p>",
	'varios'    => '<p><a href="https://ext.example/v1">F1</a> <a rel="x" href="https://ext.example/v2">F2</a> <a href="https://ext.example/v3">F3</a></p>',
	'js'        => '<p>siete <a href="javascript:alert(1)" onclick="alert(2)">G</a> <a href="JaVaScRiPt:alert(3)">G2</a></p>',
	'bbcode'    => 'ocho [url=https://ext.example/bbcode]H[/url] y [url]https://ext.example/bbcode2[/url]',
];
$cid = [];
foreach ($bodies as $k => $b) {
	$st = $db->prepare("INSERT INTO jos_engage_comments (asset_id, body, name, email, ip, user_agent, enabled, created, created_by) VALUES (?, ?, ?, 'rel@example.invalid', '127.0.0.1', 'prueba', 1, NOW(), 0)");
	$n = "rel_$k"; $st->bind_param('iss', $asset, $b, $n); $st->execute(); $cid[$k] = $db->insert_id;
}
$c = new Cliente($BASE, "$WORK/tmp", 'rel-ugc');
$res = $c->get("/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}");
$html = $res['body'] ?? '';
r('R-00', 'la pagina del articulo se sirve (HTTP 200)', ($res['code'] ?? 0) === 200, 'code=' . ($res['code'] ?? '?'));
$d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8"?>' . $html);
$x = new DOMXPath($d);
$enlacesDe = function (int $id) use ($x): array {
	$out = [];
	foreach ($x->query("//*[@id='akengage-comment-$id']//div[contains(@class,'akengage-comment-body') or @itemprop='text']//a") as $a) { $out[] = $a; }
	if (!$out) { foreach ($x->query("//*[@id='akengage-comment-$id']//a[@href and not(contains(@class,'akengage'))]") as $a) { $out[] = $a; } }
	return $out;
};
$OK = 'nofollow ugc noreferrer';
$linea = fn($a) => $a->ownerDocument->saveHTML($a);
foreach ($bodies as $k => $b) {
	$as = $enlacesDe($cid[$k]);
	$ev = implode(' | ', array_map(fn($a) => preg_replace('/>.*$/s', '>', $linea($a)), $as));
	if ($k === 'js') {
		$mal = false; foreach ($as as $a) { if (stripos($a->getAttribute('href'), 'javascript') !== false || $a->hasAttribute('onclick')) { $mal = true; } }
		r("R-$k", 'javascript:/onclick siguen saneados', !$mal && stripos($html, 'alert(1)') === false, $ev);
		continue;
	}
	$bien = count($as) > 0;
	foreach ($as as $a) {
		$rel = preg_split('/\s+/', trim($a->getAttribute('rel')));
		$bien = $bien && $a->getAttribute('href') !== '' && !array_diff(explode(' ', $OK), $rel) && count($rel) === count(array_unique($rel));
		$bien = $bien && preg_match_all('/\brel\s*=/i', preg_replace('/>.*$/s', '>', $linea($a))) === 1;
	}
	r("R-$k", 'todos los enlaces del comentario llevan un unico rel con nofollow ugc noreferrer (' . count($as) . ' enlaces)', $bien, $ev);
}
// Enlaces internos del componente (no del cuerpo del comentario) no deben llevar ugc
$internos = 0; $conUgc = 0;
foreach ($x->query("//a[@href][contains(@class,'akengage') or contains(@href,'akengage')]") as $a) {
	$dentro = false; for ($p = $a->parentNode; $p; $p = $p->parentNode) { if ($p instanceof DOMElement && ($p->getAttribute('itemprop') === 'text' || str_contains($p->getAttribute('class'), 'akengage-comment-body'))) { $dentro = true; } }
	if (!$dentro) { $internos++; if (str_contains($a->getAttribute('rel'), 'ugc')) { $conUgc++; } }
}
r('R-int', "enlaces propios del componente fuera del cuerpo sin ugc ($internos revisados)", $conUgc === 0);
$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$asset");
file_put_contents("$WORK/resultados-rel-ugc.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$f = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
echo count($RES) - $f . " PASA / $f FALLA\n";
exit($f ? 1 : 0);
