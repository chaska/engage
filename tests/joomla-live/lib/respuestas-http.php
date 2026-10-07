<?php
/**
 * 0.6.21: posicion y cita de las RESPUESTAS en la lista publicada, contra un Joomla REAL por HTTP.
 *
 * Reproduce el flujo del navegador: pide la pagina del articulo, lee del HTML servido el boton "Responder" de un
 * comentario (data-akengageid = el parent_id que el JS pone en jform[parent_id], y data-akengagereplyto) y envia el
 * formulario con el token, el returnurl y los campos ocultos reales. Recorre la matriz
 * comments_ordering (asc/desc) x max_level (1, 2, 3, 6) y registra, por cada combinacion, el orden, la sangria y la
 * cita de cada comentario.
 *
 * Hilo creado (cada respuesta con el boton del comentario indicado):
 *   A (raiz)  B -> A  C -> B  D -> C  F -> A  E (raiz)  H -> E
 *
 * Uso: php respuestas-http.php [--mostrar]     (--mostrar solo imprime el orden; no falla por la cita)
 * Requisitos: 03-sembrar-y-probar.sh ya ejecutado ($WORK/ids.json). Escribe $WORK/resultados-respuestas.json.
 * Sale con 1 si algo FALLA. Al acabar restablece max_level/comments_ordering y borra los comentarios del articulo.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$SOLO_MOSTRAR = in_array('--mostrar', $argv, true);
$RES = [];
$EVID = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-12s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [$ev]" : '');
}
function param(array $kv): void
{
	global $db;
	$p = json_decode((string) $db->query("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'")->fetch_row()[0], true) ?: [];
	foreach ($kv as $k => $v) { if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; } }
	$db->query("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode($p)) . "' WHERE element='com_engage' AND type='component'");
}
$A = $ids['art_publico']; $CP = $ids['cat_publica']; $AS = (int) $ids['art_publico_asset'];
$URL = "/index.php?option=com_content&view=article&id=$A&catid=$CP";
$vaciar = function () use ($db, $AS) { $db->query("DELETE FROM jos_engage_comments WHERE asset_id=$AS"); };

/** Analiza el HTML: lista de comentarios en el orden servido con id, cuerpo, sangria (ul ancestros), boton Responder y cita. */
function analizar(string $html): array
{
	$d = new DOMDocument(); libxml_use_internal_errors(true);
	$d->loadHTML('<?xml encoding="utf-8"?>' . $html);
	$x = new DOMXPath($d);
	$out = [];
	foreach ($x->query("//article[starts-with(@id,'akengage-comment-')]") as $art) {
		$id = (int) substr($art->getAttribute('id'), strlen('akengage-comment-'));
		$nivel = 0;
		for ($p = $art->parentNode; $p; $p = $p->parentNode) {
			if ($p instanceof DOMElement && $p->nodeName === 'ul' && str_contains($p->getAttribute('class'), 'akengage-comment-list')) { $nivel++; }
		}
		$cuerpo = '';
		foreach ($x->query(".//div[contains(@class,'akengage-comment-body')]", $art) as $b) { $cuerpo = trim(preg_replace('/\s+/', ' ', $b->textContent)); break; }
		$btn = $x->query(".//button[contains(@class,'akengage-comment-reply-btn')]", $art)->item(0);
		$cita = $x->query(".//*[contains(@class,'akengage-comment-replyto')]", $art)->item(0);
		$enlace = $cita ? $x->query(".//a/@href", $cita)->item(0) : null;
		$out[] = [
			'id' => $id, 'cuerpo' => $cuerpo, 'nivel_html' => $nivel,
			'btn_id' => $btn ? (int) $btn->getAttribute('data-akengageid') : null,
			'btn_nombre' => $btn ? $btn->getAttribute('data-akengagereplyto') : null,
			'cita' => $cita ? trim(preg_replace('/\s+/', ' ', $cita->textContent)) : null,
			'cita_ancla' => $enlace ? $enlace->nodeValue : null,
		];
	}
	return $out;
}
/** Envia un comentario como lo hace el navegador. $botonDe: id del comentario cuyo boton Responder se pulsa (0 = comentario nuevo). */
function enviar(Cliente $c, string $url, int $asset, string $cuerpo, string $nombre, int $botonDe): array
{
	$pag = $c->get($url);
	$parent = 0;
	if ($botonDe > 0) {
		$lista = analizar($pag['body']);
		foreach ($lista as $it) { if ($it['id'] === $botonDe) { $parent = (int) $it['btn_id']; } }
	}
	$tok = Cliente::token($pag['body']);
	$ru  = preg_match('/name="returnurl" value="([^"]*)"/', $pag['body'], $m) ? html_entity_decode($m[1]) : base64_encode($c->base . $url);
	$resp = $c->post('/index.php?option=com_engage&task=comment.save', [
		'returnurl' => $ru, 'view' => '', 'id' => '', $tok => 1,
		'jform' => ['id' => '', 'parent_id' => (string) $parent, 'asset_id' => (string) $asset, 'name' => $nombre, 'email' => strtolower($nombre) . '@example.test', 'body' => $cuerpo],
	]);
	return ['resp' => $resp, 'parent_enviado' => $parent];
}

$matriz = [];
foreach (['asc', 'desc'] as $orden) {
	foreach ([1, 2, 3, 6] as $maxl) { $matriz[] = [$orden, $maxl]; }
}
foreach ($matriz as [$orden, $maxl]) {
	$vaciar();
	param(['reactions_enabled' => '0', 'sort_selector' => '0', 'copy_link' => '0', 'show_badges' => '0', 'default_publish' => '1', 'comments_ordering' => $orden, 'max_level' => (string) $maxl]);
	$c = new Cliente($BASE, "$WORK/tmp", 'respuestas');
	$mapa = [];   // etiqueta => id
	$plan = [['A', 0], ['B', 'A'], ['C', 'B'], ['D', 'C'], ['F', 'A'], ['E', 0], ['H', 'E']];
	$enviados = [];
	foreach ($plan as [$et, $resp]) {
		$e = enviar($c, $URL, $AS, "Texto $et", "Autor$et", $resp === 0 ? 0 : $mapa[$resp]);
		$fila = $db->query("SELECT id, parent_id FROM jos_engage_comments WHERE body='Texto $et' AND asset_id=$AS")->fetch_assoc();
		$mapa[$et] = (int) ($fila['id'] ?? 0);
		$enviados[$et] = ['http' => $e['resp']['code'], 'parent_enviado' => $e['parent_enviado'], 'parent_guardado' => $fila ? (int) $fila['parent_id'] : -1, 'id' => $mapa[$et]];
		usleep(1100000);   // 'created' tiene resolucion de 1 s: asi el orden por fecha es inequivoco
	}
	$pag = (new Cliente($BASE, "$WORK/tmp", 'respuestas-lectura'))->get($URL);
	$lista = analizar($pag['body']);
	$etiqueta = array_flip($mapa);
	$orden_servido = array_map(fn($i) => ($etiqueta[$i['id']] ?? '?'), $lista);
	$linea = [];
	foreach ($lista as $i) {
		$linea[] = sprintf('%s(n%d%s)', $etiqueta[$i['id']] ?? '?', $i['nivel_html'], $i['cita'] !== null ? ',cita:' . $i['cita'] : '');
	}
	$clave = "$orden/max$maxl";
	$EVID[$clave] = ['enviados' => $enviados, 'servido' => $linea];
	printf("== %s  orden servido: %s\n", $clave, implode('  ', $linea));
	printf("   parent_id guardado: %s\n", implode(' ', array_map(fn($k, $v) => "$k->" . ($v['parent_guardado'] ?: '0') . ($v['parent_enviado'] !== $v['parent_guardado'] ? '!' : ''), array_keys($enviados), $enviados)));

	// Lo que se espera (padre real segun el boton que pulso el visitante) -> el padre guardado
	$padres = ['A' => 0, 'B' => $mapa['A'], 'C' => $mapa['B'], 'D' => $mapa['C'], 'F' => $mapa['A'], 'E' => 0, 'H' => $mapa['E']];
	// Con max_level M, responder a un comentario de nivel >= M cuelga del ancestro de nivel M-1 (comportamiento documentado). El nivel 1 (raiz) = 1.
	$nivelNat = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'F' => 2, 'E' => 1, 'H' => 2];
	$esperado = ['A' => 0, 'E' => 0];
	$pad = ['B' => 'A', 'C' => 'B', 'D' => 'C', 'F' => 'A', 'H' => 'E'];
	$nv = ['A' => 1, 'E' => 1];
	foreach (['B', 'C', 'D', 'F', 'H'] as $et) {
		$p = $pad[$et];
		// nivel que tendria el padre en el arbol REAL ya guardado
		$nivP = $nv[$p];
		if ($maxl <= 1) { $esperado[$et] = 0; $nv[$et] = 1; continue; }
		if ($nivP < $maxl) { $esperado[$et] = $mapa[$p]; $nv[$et] = $nivP + 1; }
		else { $anc = $p; while ($nv[$anc] > $maxl - 1) { $anc = $pad[$anc]; } $esperado[$et] = $mapa[$anc]; $nv[$et] = $nv[$anc] + 1; }
	}
	// 1) el boton Responder manda el padre que dice la documentacion de max_level
	$mal = [];
	foreach ($esperado as $et => $pe) { if ($enviados[$et]['parent_guardado'] !== $pe) { $mal[] = "$et guardado={$enviados[$et]['parent_guardado']} esperado=$pe"; } }
	r("P-$clave", 'el padre guardado es el que corresponde a max_level (boton Responder -> jform[parent_id])', !$mal, implode('; ', $mal));
	// 2) agrupacion: cada comentario aparece DESPUES de su padre guardado y dentro de su rama (el siguiente al padre o el de su subarbol)
	$pos = array_flip($orden_servido);
	$fuera = [];
	foreach ($enviados as $et => $inf) {
		if ($inf['parent_guardado'] > 0) {
			$pe = $etiqueta[$inf['parent_guardado']] ?? '?';
			if (!isset($pos[$et], $pos[$pe]) || $pos[$et] <= $pos[$pe]) { $fuera[] = "$et antes de su padre $pe"; }
		}
	}
	// rama contigua: entre un padre y su hijo solo puede haber descendientes del padre
	$desc = function (string $et) use (&$desc, $enviados, $etiqueta): array {
		$r = [];
		foreach ($enviados as $k => $inf) { if (($etiqueta[$inf['parent_guardado']] ?? null) === $et) { $r[] = $k; $r = array_merge($r, $desc($k)); } }
		return $r;
	};
	foreach (array_keys($enviados) as $et) {
		$d = $desc($et);
		if (!$d) { continue; }
		$ps = array_map(fn($k) => $pos[$k] ?? -1, $d); $ps[] = $pos[$et];
		sort($ps);
		if ($ps !== range($ps[0], $ps[0] + count($ps) - 1)) { $fuera[] = "rama de $et no contigua"; }
	}
	r("G-$clave", 'cada respuesta sale agrupada bajo su comentario padre (rama contigua y despues del padre)', !$fuera, implode('; ', $fuera));
	// 3) el orden asc/desc solo entre hermanos
	$raices = array_values(array_filter($orden_servido, fn($e) => in_array($e, ['A', 'E'], true)));
	r("O-$clave", "las raices salen en orden $orden (A antes que E " . ($orden === 'asc' ? 'en asc' : 'solo en asc; en desc E antes'), $raices === ($orden === 'asc' ? ['A', 'E'] : ['E', 'A']), implode(',', $raices));
	// 4) cita
	$sinCita = []; $conCitaRaiz = []; $nombreMal = [];
	foreach ($lista as $i) {
		$et = $etiqueta[$i['id']] ?? '?';
		$pg = $enviados[$et]['parent_guardado'] ?? 0;
		if ($pg > 0) {
			$nomPadre = 'Autor' . ($etiqueta[$pg] ?? '?');
			if ($i['cita'] === null) { $sinCita[] = $et; }
			elseif (!str_contains($i['cita'], $nomPadre) || $i['cita_ancla'] !== '#akengage-comment-' . $pg) { $nombreMal[] = "$et: " . $i['cita'] . ' ' . $i['cita_ancla']; }
		} elseif ($i['cita'] !== null) { $conCitaRaiz[] = $et; }
	}
	$cuenta = count(array_filter($enviados, fn($v) => $v['parent_guardado'] > 0));
	if (!$SOLO_MOSTRAR) {
		r("C-$clave", "las $cuenta respuestas llevan 'En respuesta a <autor del padre>' con ancla al padre; las raices no", !$sinCita && !$nombreMal && !$conCitaRaiz,
			($sinCita ? 'sin cita: ' . implode(',', $sinCita) . '. ' : '') . ($nombreMal ? 'mal: ' . implode(' | ', $nombreMal) . '. ' : '') . ($conCitaRaiz ? 'cita en raiz: ' . implode(',', $conCitaRaiz) : ''));
	}
}

// --- Escapado y padre inexistente / despublicado (solo con la funcion activa; con --mostrar se omite)
if (!$SOLO_MOSTRAR) {
	$vaciar();
	param(['comments_ordering' => 'asc', 'max_level' => '3']);
	$c = new Cliente($BASE, "$WORK/tmp", 'respuestas-x');
	$xss = '<script>alert(1)</script>"\'&<b>';
	enviar($c, $URL, $AS, 'Texto P', $xss, 0);
	$idP = (int) $db->query("SELECT id FROM jos_engage_comments WHERE body='Texto P' AND asset_id=$AS")->fetch_row()[0];
	enviar($c, $URL, $AS, 'Texto R1', 'Real', $idP);
	enviar($c, $URL, $AS, 'Texto R2', 'Real', $idP);
	$idR1 = (int) $db->query("SELECT id FROM jos_engage_comments WHERE body='Texto R1'")->fetch_row()[0];
	$h = (new Cliente($BASE, "$WORK/tmp", 'respuestas-x2'))->get($URL)['body'];
	$guardado = (string) $db->query("SELECT name FROM jos_engage_comments WHERE id=$idP")->fetch_row()[0];   // Joomla ya puede haber filtrado etiquetas al guardar
	$esc = htmlspecialchars($guardado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	r('X-escape', 'el nombre del padre en la cita sale escapado (con comillas y &)', $esc !== $guardado && str_contains($h, 'class="akengage-comment-replyto-link">' . $esc . '</a>') && !str_contains($h, '<script>alert(1)'), 'guardado=' . $guardado);
	// Nombre hostil guardado directamente en la BD (otro plugin, importacion...): tambien debe salir escapado
	$st = $db->prepare("INSERT INTO jos_engage_comments (asset_id, parent_id, body, name, email, ip, user_agent, enabled, created, created_by) VALUES (?, ?, ?, ?, 'h@example.invalid', '127.0.0.1', 'prueba', 1, NOW(), 0)");
	$cero = null; $cp = 'Texto HP'; $hn = '<img src=x onerror=alert(7)>"\'';
	$st->bind_param('iiss', $AS, $cero, $cp, $hn); $st->execute(); $idHP = $db->insert_id;
	$cp2 = 'Texto HR'; $nr = 'Resp'; $st->bind_param('iiss', $AS, $idHP, $cp2, $nr); $st->execute();
	$h = (new Cliente($BASE, "$WORK/tmp", 'respuestas-xh'))->get($URL)['body'];
	r('X-escape-bd', 'nombre hostil guardado en la BD sale escapado en la cita', !str_contains($h, '<img src=x onerror=alert(7)>') && str_contains($h, '&lt;img src=x onerror=alert(7)&gt;&quot;&#039;</a>'));
	$db->query("DELETE FROM jos_engage_comments WHERE id=$idHP OR parent_id=$idHP");
	// padre despublicado: ni su nombre ni su cita deben salir (ni R1 ni R2 quedan visibles al visitante: son huerfanos del arbol)
	$db->query("UPDATE jos_engage_comments SET enabled=0 WHERE id=$idP");
	$h = (new Cliente($BASE, "$WORK/tmp", 'respuestas-x3'))->get($URL)['body'];
	r('X-despub', 'con el padre despublicado no se filtra su nombre ni sus respuestas al visitante', !str_contains($h, 'script&gt;alert') && !str_contains($h, 'Texto R1') && !str_contains($h, 'Texto P'));
	// moderador ve ambos; la cita de un hijo cuyo padre no esta publicado no debe mostrar el nombre a un visitante
	// Padre borrado (huerfano en BD): la respuesta no se muestra y no se filtra nada
	$db->query("DELETE FROM jos_engage_comments WHERE id=$idP");
	$h = (new Cliente($BASE, "$WORK/tmp", 'respuestas-x4'))->get($URL)['body'];
	r('X-borrado', 'con el padre borrado no se filtra su nombre', !str_contains($h, 'alert(1)'));
	// parametro apagado: sin cita
	$vaciar();
	param(['reply_show_quote' => '0']);
	$a = enviar($c, $URL, $AS, 'Texto Q', 'AutorQ', 0);
	$idQ = (int) $db->query("SELECT id FROM jos_engage_comments WHERE body='Texto Q'")->fetch_row()[0];
	enviar($c, $URL, $AS, 'Texto Q2', 'AutorQ2', $idQ);
	$h = (new Cliente($BASE, "$WORK/tmp", 'respuestas-x5'))->get($URL)['body'];
	r('X-apagado', 'con reply_show_quote=0 no sale ninguna cita (la respuesta sigue anidada)', substr_count($h, 'akengage-comment-replyto') === 0 && str_contains($h, 'Texto Q2'));
	param(['reply_show_quote' => null]);
}

$vaciar();
param(['comments_ordering' => null, 'max_level' => null, 'reply_show_quote' => null]);
file_put_contents("$WORK/resultados-respuestas.json", json_encode(['resultados' => $RES, 'evidencia' => $EVID], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$f = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
echo count($RES) - $f . " PASA / $f FALLA\n";
exit($f ? 1 : 0);
