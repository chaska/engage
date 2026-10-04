<?php
/**
 * 0.6.20: pantallas REALES de Engage con es-ES como idioma del sitio y de la administracion (HTTP, sin navegador).
 * Por pantalla comprueba: HTTP 200, que aparecen cadenas espanolas esperadas, que NO queda ninguna clave sin traducir
 * (patron COM_ENGAGE_ / PLG_*ENGAGE / MOD_ENGAGE_ visible fuera de <script>) y que no se cuela texto en INGLES de Engage
 * (el idioma por defecto es en-GB y Joomla lo usa de respaldo: una clave sin traducir se veria en ingles, no como clave).
 * Ademas envia un comentario de invitado y comprueba que el correo capturado sale en espanol.
 * Requisitos: 02 y 03 ya ejecutados y 08-idioma-es.sh (pone es-ES). Escribe $WORK/resultados-idioma-es-http.json.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
$SITE = "$WORK/" . (getenv('SITE_DIR') ?: 'site');
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$AP   = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
$RES = [];
function r(string $id, string $desc, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['id' => $id, 'desc' => $desc, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-8s %-6s %s%s\n", $id, $ok ? 'PASA' : 'FALLA', $desc, $ev !== '' ? "  [$ev]" : '');
}
/** Cadenas inglesas de Engage (valores en-GB largos, sin marcadores) que no deben aparecer en una pantalla es-ES. */
function inglesas(string $srcRoot): array
{
	$o = [];
	foreach (glob($srcRoot . '/{component/backend,component/frontend,modules/site/*,plugins/*/*}/language/en-GB/*.ini', GLOB_BRACE) as $f) {
		foreach (file($f) as $l) {
			if (!preg_match('/^[A-Z][A-Z0-9_]*="(.*)"\s*$/', $l, $m)) { continue; }
			$t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('\\n', ' ', $m[1])), ENT_QUOTES)));
			if (strlen($t) >= 22 && !preg_match('/[%{<]|style/', $t)) { $o[$t] = true; }
		}
	}
	return array_keys($o);
}
$ING = inglesas(dirname(__DIR__, 3) . '/src');
function visible(string $html): string
{
	$html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);
	return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)), ENT_QUOTES | ENT_HTML5);
}
function pantalla(string $id, string $desc, array $res, array $esperado): void
{
	global $ING;
	$h = $res['body'] ?? '';
	$v = visible($h);
	r($id, "$desc: HTTP 200", ($res['code'] ?? 0) === 200, 'code=' . ($res['code'] ?? '?'));
	preg_match_all('/\b(?:COM_ENGAGE|MOD_ENGAGE|PLG_[A-Z]+_ENGAGE|PLG_ENGAGE)_[A-Z0-9_]+\b/', preg_replace('#<(script|style|code)\b.*?</\1>#si', ' ', $h), $m);
	r($id . 'k', "$desc: ninguna clave COM_/MOD_/PLG_ENGAGE visible", !$m[0], implode(',', array_slice(array_unique($m[0]), 0, 4)));
	$todo = $v . ' ' . html_entity_decode($h, ENT_QUOTES | ENT_HTML5); // texto visible + atributos (aria-label, value, title)
	$falta = array_values(array_filter($esperado, fn($e) => stripos($todo, $e) === false));
	r($id . 'e', "$desc: cadenas es-ES esperadas (" . count($esperado) . ')', !$falta, $falta ? 'faltan: ' . implode(' | ', array_slice($falta, 0, 3)) : '');
	$ing = array_values(array_filter($ING, fn($e) => stripos($v, $e) !== false));
	r($id . 'i', "$desc: ningun texto en ingles de Engage", !$ing, $ing ? substr($ing[0], 0, 70) : '');
}
$asset = (int) $ids['art_publico_asset'];
$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$asset");
foreach ([[1, 'Ana', 'Comentario publicado de prueba'], [0, 'Beto', 'Comentario sin publicar'], [-3, 'Spammer', 'Compra ya']] as [$en, $n, $b]) {
	$st = $db->prepare("INSERT INTO jos_engage_comments (asset_id, body, name, email, ip, user_agent, enabled, created, created_by) VALUES (?, ?, ?, 'idioma@example.invalid', '127.0.0.1', 'prueba', ?, NOW(), 0)");
	$st->bind_param('issi', $asset, $b, $n, $en); $st->execute();
}
$adm = new Cliente($BASE, "$WORK/tmp", 'idioma-adm');
r('I-00', 'inicio de sesion de administracion', $adm->loginAdmin('admintest', $AP));
pantalla('I-01', 'Comentarios (lista, backend)', $adm->get('/administrator/index.php?option=com_engage&view=Comments'), ['Comentarios', 'Posible SPAM']);
pantalla('I-02', 'Opciones del componente (com_engage.sys.ini)', $adm->get('/administrator/index.php?option=com_config&view=component&component=com_engage'), ['Nivel máximo de anidación de comentarios', 'Avanzado', 'Filtrado de texto', 'Texto de la casilla de condiciones', 'Cargar CSS personalizado']);
pantalla('I-03', 'Plantillas de email (Engage)', $adm->get('/administrator/index.php?option=com_engage&view=Emailtemplates'), ['¿Dónde están las plantillas de email?', 'Instalar o actualizar', 'Restablecer']);
pantalla('I-04', 'Plugins (lista filtrada por engage)', $adm->get('/administrator/index.php?option=com_plugins&view=plugins&filter[search]=engage&filter[folder]=&list[limit]=100'), ['Registro de acciones', 'Protección antispam de Akismet', 'Consola - Akeeba Engage', 'Integración con Gravatar', 'Usuario – Akeeba Engage']);
$formPlugin = function (int $eid) use ($adm, $db) {
	$r = $adm->get("/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=$eid");
	if (in_array($r['code'], [301, 302, 303, 307], true) && $r['loc'] !== '') { $r = $adm->get($r['loc']); }
	$db->query("UPDATE jos_extensions SET checked_out=NULL, checked_out_time=NULL WHERE extension_id=$eid");
	return $r;
};
$q = fn(string $el, string $fol) => (int) $db->query("SELECT extension_id FROM jos_extensions WHERE element='$el' AND folder='$fol' AND type='plugin'")->fetch_row()[0];
pantalla('I-05', 'Plugin Akismet (formulario)', $formPlugin($q('akismet', 'engage')), ['Clave de Akismet', 'Descartar el SPAM evidente', 'Quien no sea administrador']);
pantalla('I-06', 'Plugin Emails (formulario)', $formPlugin($q('email', 'engage')), ['Notificar a los administradores de comentarios todos los comentarios']);
pantalla('I-07', 'Plugin Gravatar (formulario)', $formPlugin($q('gravatar', 'engage')), ['Modo de Gravatar', 'Persona misteriosa', 'Grupo de JBCookies (opcional)']);
pantalla('I-08', 'Plugin registro de acciones (formulario)', $formPlugin($q('engage', 'actionlog')), ['Registrar comentarios']);
pantalla('I-09', 'Plugin usuario (formulario)', $formPlugin($q('engage', 'user')), ['Comentarios propios de invitado al iniciar sesión']);
$m10 = $adm->get('/administrator/index.php?option=com_modules&task=module.add&client_id=0&eid=' . (int) $db->query("SELECT extension_id FROM jos_extensions WHERE element='mod_engage_latest'")->fetch_row()[0]);
if (in_array($m10['code'], [301, 302, 303, 307], true) && $m10['loc'] !== '') { $m10 = $adm->get($m10['loc']); }
pantalla('I-10', 'Modulo Ultimos comentarios (formulario nuevo)', $m10, ['Mostrar extracto', 'Modo de categorías', 'Número de elementos']);
// Frontend: invitado en el articulo publico
$inv = new Cliente($BASE, "$WORK/tmp", 'idioma-inv');
$art = "/index.php?option=com_content&view=article&id={$ids['art_publico']}&catid={$ids['cat_publica']}";
pantalla('I-11', 'Frontend: articulo con comentarios (invitado)', $inv->get($art), ['Sección de comentarios', 'Deje su comentario']);
// Correo: comentario de invitado -> notificacion a administradores (texto plano + HTML), capturado por el SMTP de pruebas
$db->query("UPDATE jos_extensions SET params='{\"mail_style\":\"both\",\"disable_htmllayout\":\"1\"}' WHERE element='com_mails'");
foreach (glob("$WORK/mails/*.eml") ?: [] as $f) { unlink($f); }
$pag = $inv->get($art);
$tok = Cliente::token($pag['body']);
$ru  = preg_match('/name="returnurl" value="([^"]*)"/', $pag['body'], $mm) ? html_entity_decode($mm[1]) : base64_encode($BASE . $art);
$resp = $inv->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => $ru, 'jform' => ['asset_id' => $asset, 'parent_id' => '0', 'name' => 'Invitado Idioma', 'email' => 'invitado.idioma@example.test', 'body' => 'Comentario para probar el correo'], $tok => 1]);
sleep(2);
$asunto = ''; $texto = ''; $html = '';
foreach (glob("$WORK/mails/*.eml") ?: [] as $f) {
	$raw = file_get_contents($f);
	if (preg_match('/^Subject:\s*(.+?)(?:\r?\n(?!\s))/ms', $raw, $sm)) { $asunto .= iconv_mime_decode(preg_replace('/\r?\n\s+/', '', $sm[1]), 0, 'UTF-8') . "\n"; }
	if (preg_match('#Content-Type: text/plain[^\n]*\n(?:Content-Transfer-Encoding:[^\n]*\n)?\r?\n(.*?)\r?\n--b1#s', $raw, $pm)) { $texto .= $pm[1] . "\n"; }
	if (preg_match('#Content-Type: text/html[^\n]*\n(Content-Transfer-Encoding:[^\n]*\n)?\r?\n(.*?)\r?\n--b1#s', $raw, $hm)) { $html .= (stripos($hm[1], 'quoted') !== false ? quoted_printable_decode($hm[2]) : $hm[2]) . "\n"; }
}
if ($asunto === '') {
	r('I-12', 'correo de notificacion: NO PROBADO (no llego ningun correo al SMTP de captura)', false, 'code=' . ($resp['code'] ?? '?'));
} else {
	$ok = str_contains($texto, 'Hola ') && str_contains($texto, 'Se ha enviado un nuevo comentario') && str_contains($html, 'Ver comentario');
	$ingles = (bool) preg_match('/Hello |A new comment was filed|awaits your moderation|Please do not reply|View Comment/', $texto . $html);
	r('I-12', 'el correo capturado (asunto, texto plano y HTML) esta en espanol y sin frases inglesas de Engage', $ok && !$ingles, trim($asunto));
}
$db->query("DELETE FROM jos_engage_comments WHERE asset_id=$asset");
file_put_contents("$WORK/resultados-idioma-es-http.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$f = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
echo count($RES) - $f . " PASA / $f FALLA\n";
exit($f ? 1 : 0);
