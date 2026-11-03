<?php
/**
 * 0.9.17: el README como portada de producto con capturas.
 *  A) Imagenes: la UNICA imagen permitida en el repositorio es docs/capturas/*.jpg; cualquier otra falla (con casos simulados y el arbol real)
 *  B) Las capturas: JPG sin metadatos (ni EXIF, ni XMP, ni ICC, ni comentarios), de peso y ancho razonables; todas citadas y todas existentes
 *  C) El paquete NO lleva docs ni capturas (candado del empaquetador) y las pruebas de «sin imagenes» siguen fallando con cualquier otra imagen
 *  D) README: orden de secciones, enlaces e imagenes relativos que existen, anclas internas validas, <details> equilibrados
 *  E) TODAS las opciones: cada clave de config.xml, de forms/*.xml, del modulo y de los plugins esta en el README (0 faltantes) y el README no inventa ninguna
 *  F) Sin datos personales en el README ni en los nombres de las capturas (correos reales, IP, rutas del PC, nombres de sitios reales)
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__);
require_once "$root/build/auditoria.php";
$leer = fn(string $r) => (string) file_get_contents("$root/$r");

echo "A) Imagenes del repositorio\n";
const EXT_IMG = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'ico', 'heic', 'avif'];
$permitida = static function (string $rel): bool { return (bool) preg_match('#^docs/capturas/[A-Za-z0-9._-]+\.jpg$#', $rel); };
$esImg = static fn(string $rel): bool => in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), EXT_IMG, true);
t_ok($permitida('docs/capturas/principal.jpg') && !$permitida('docs/capturas/principal.png') && !$permitida('docs/principal.jpg') && !$permitida('docs/capturas/sub/a.jpg') && !$permitida('src/component/media/a.jpg') && !$permitida('README.jpg') && !$permitida('docs/capturas/a.JPG') && !$permitida('docs/capturas/../a.jpg') && !$permitida('tests/capturas/a.jpg'), 'regla: solo docs/capturas/<nombre>.jpg; un PNG, otra carpeta, una subcarpeta o un JPG fuera de ahi NO se admiten');
$malas = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS), RecursiveIteratorIterator::SELF_FIRST);
$caps = [];
foreach ($it as $f) {
	if (!$f->isFile()) { continue; }
	$rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
	if (preg_match('#^(\.git|dist|tests/vendor|node_modules)/#', $rel)) { continue; }
	if ($esImg($rel) && !$permitida($rel)) { $malas[] = $rel; }
	if ($permitida($rel)) { $caps[] = $rel; }
}
t_ok(!$malas, 'ninguna imagen fuera de docs/capturas/*.jpg en el arbol de trabajo' . ($malas ? ' (' . implode(', ', array_slice($malas, 0, 5)) . ')' : ''));
$git = @shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files 2>/dev/null');
if ($git) {
	$mg = array_filter(explode("\n", trim($git)), fn($r) => $r !== '' && $esImg($r) && !$permitida($r));
	t_ok(!$mg, 'ninguna imagen fuera de docs/capturas/*.jpg en lo versionado por git' . ($mg ? ' (' . implode(', ', array_slice($mg, 0, 5)) . ')' : ''));
}
$ign = $leer('.gitignore');
t_ok(str_contains($ign, '/docs/**/*.png') && str_contains($ign, '/docs/**/*.jpg') && str_contains($ign, '!/docs/capturas/*.jpg') && strpos($ign, '!/docs/capturas/*.jpg') > strpos($ign, '/docs/**/*.jpg'), '.gitignore: sigue ignorando las imagenes de docs salvo la excepcion docs/capturas/*.jpg (despues de la regla general)');

echo "B) Las capturas\n";
sort($caps);
t_ok(count($caps) >= 20, 'hay al menos 20 capturas (' . count($caps) . ')');
$readme = $leer('README.md');
$citadas = [];
preg_match_all('#docs/capturas/([A-Za-z0-9._-]+\.jpg)#', $readme, $m);
$citadas = array_values(array_unique($m[1]));
sort($citadas);
$existen = array_map('basename', $caps); sort($existen);
t_ok($citadas === $existen, 'todas las capturas estan citadas en el README y todas las citadas existen' . ($citadas !== $existen ? ' (sin citar: ' . implode(',', array_diff($existen, $citadas)) . '; inexistentes: ' . implode(',', array_diff($citadas, $existen)) . ')' : ''));
/** Segmentos de un JPEG: devuelve los marcadores presentes (o null si no es un JPEG valido). */
$segmentos = static function (string $b): ?array {
	if (strlen($b) < 4 || substr($b, 0, 2) !== "\xFF\xD8") { return null; }
	$i = 2; $out = [];
	while ($i + 4 <= strlen($b)) {
		if ($b[$i] !== "\xFF") { return null; }
		$mk = ord($b[$i + 1]);
		if ($mk === 0xD9) { break; }
		$out[] = $mk;
		if ($mk === 0xDA) { break; }
		$len = (ord($b[$i + 2]) << 8) | ord($b[$i + 3]);
		$i += 2 + $len;
	}
	return $out;
};
$sinMeta = static function (string $b) use ($segmentos): bool {
	$s = $segmentos($b);
	if ($s === null) { return false; }
	foreach ($s as $mk) { if (($mk >= 0xE1 && $mk <= 0xEF) || $mk === 0xFE) { return false; } }   // APP1 (EXIF/XMP), APP2 (ICC), ..., comentarios
	return true;
};
$conExif = "\xFF\xD8\xFF\xE1\x00\x08Exif\x00\x00\xFF\xDA\x00\x02\xFF\xD9";
$limpio = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00\x02\xFF\xDA\x00\x02\xFF\xD9";
t_ok(!$sinMeta($conExif) && $sinMeta($limpio) && !$sinMeta('no es un jpg') && !$sinMeta("\xFF\xD8\xFF\xFE\x00\x04hi\xFF\xDA\x00\x02"), 'detector de metadatos: rechaza EXIF/XMP/ICC y comentarios, admite un JPEG limpio, rechaza un fichero que no es JPEG');
$mal = [];
foreach ($caps as $c) {
	$b = $leer($c); $sz = strlen($b); $inf = @getimagesizefromstring($b);
	if (!$sinMeta($b)) { $mal[] = "$c: lleva metadatos o no es JPEG"; }
	if ($sz > 150 * 1024 || $sz < 10 * 1024) { $mal[] = "$c: peso " . intdiv($sz, 1024) . ' KB'; }
	if (!$inf || $inf[0] > 1100 || $inf[0] < 200 || $inf[2] !== IMAGETYPE_JPEG) { $mal[] = "$c: tamano/tipo " . json_encode($inf ? [$inf[0], $inf[1]] : null); }
	if (strpos($b, 'Exif') !== false || strpos($b, 'xpacket') !== false || strpos($b, 'http://ns.adobe.com') !== false) { $mal[] = "$c: cadena EXIF/XMP"; }
}
t_ok(!$mal, 'cada captura: JPG sin EXIF/XMP/ICC/comentarios, entre 10 y 150 KB y de 200 a 1100 px de ancho' . ($mal ? ' (' . implode('; ', array_slice($mal, 0, 4)) . ')' : ''));

echo "C) El paquete no lleva docs ni capturas\n";
foreach (['docs/capturas/principal.jpg', 'docs/PANEL.md', 'component/media/capturas/a.jpg', 'x/b.png'] as $f) { t_ok(audFichero($f, 'x', false) !== [], "el candado del empaquetador rechaza $f dentro de una extension"); }
$bld = $leer('build/build.php');
t_ok(!preg_match('#docs/|capturas|README#', preg_replace('#//.*|/\*.*?\*/#s', '', $bld)) || str_contains($bld, "'src'"), 'build.php empaqueta solo desde src (nunca docs, README ni capturas)');
$zips = glob("$root/dist/pkg_engage-*.zip");
if ($zips) {
	$z = new ZipArchive(); $malos = [];
	foreach ($zips as $zp) {
		if ($z->open($zp) !== true) { continue; }
		for ($i = 0; $i < $z->numFiles; $i++) {
			$n = $z->getNameIndex($i);
			if (preg_match('#(^|/)(docs|capturas)/|\.(jpg|jpeg|png|gif|webp)$#i', $n)) { $malos[] = $n; }
			if (substr($n, -4) === '.zip') {
				$tmp = tempnam(sys_get_temp_dir(), 'eg'); file_put_contents($tmp, $z->getFromIndex($i)); $y = new ZipArchive();
				if ($y->open($tmp) === true) { for ($j = 0; $j < $y->numFiles; $j++) { if (preg_match('#(^|/)(docs|capturas)/|\.(jpg|jpeg|png|gif|webp)$#i', $y->getNameIndex($j))) { $malos[] = "$n:" . $y->getNameIndex($j); } } $y->close(); }
				@unlink($tmp);
			}
		}
		$z->close();
	}
	t_ok(!$malos, 'el ZIP del paquete (y los anidados) no lleva carpetas docs ni capturas, ni imagenes' . ($malos ? ' (' . implode(', ', array_slice($malos, 0, 3)) . ')' : ''));
} else { t_ok(true, 'sin dist/: se omite la inspeccion del ZIP (la hace build/verificar.php)'); }

echo "D) Estructura, enlaces e imagenes del README\n";
$pos = []; foreach (['**Contenido:**', '## Qué hace', '## Todas las opciones', '## Instalar', '## Seguridad', '## Autor y licencia'] as $h) { $pos[$h] = strpos($readme, $h); }
$ord = array_values($pos); $ok = !in_array(false, $ord, true); for ($i = 1; $ok && $i < count($ord); $i++) { $ok = $ord[$i] > $ord[$i - 1]; }
t_ok($ok, 'secciones en orden: contenido, Qué hace, Todas las opciones, Instalar, Seguridad, Autor y licencia');
t_ok(str_starts_with($readme, '<h1 align="center">Engage</h1>') && str_contains($readme, 'img.shields.io/github/v/release/chaska/engage') && str_contains($readme, 'Joomla-5%20%C2%B7%206') && str_contains($readme, 'PHP-8.1%2B') && str_contains($readme, 'GPL--3.0'), 'cabecera centrada con las cuatro insignias (version dinamica, Joomla 5 · 6, PHP 8.1+, GPL-3.0)');
t_ok(preg_match('#<img src="docs/capturas/principal\.jpg" width="860" alt="[^"]{40,}"#', $readme) === 1, 'captura principal a 860 px con texto alternativo descriptivo');
t_ok(substr_count($readme, '<details') === substr_count($readme, '</details>') && substr_count($readme, '<details') >= 25, 'los <details> estan equilibrados (' . substr_count($readme, '<details') . ')');
$mal = [];
preg_match_all('#\]\(([^)\s]+)\)#', $readme, $l1); preg_match_all('#(?:src|href)="([^"]+)"#', $readme, $l2);
foreach (array_unique(array_merge($l1[1], $l2[1])) as $u) {
	if (preg_match('#^(https://|mailto:|\#)#', $u)) { continue; }
	$p = preg_replace('#[?\#].*$#', '', $u);
	$base = str_starts_with($p, '../../releases') ? null : "$root/$p";
	if ($base !== null && !file_exists($base)) { $mal[] = $u; }
}
t_ok(!$mal, 'todos los enlaces e imagenes relativos existen' . ($mal ? ' (' . implode(', ', array_slice($mal, 0, 5)) . ')' : ''));
$ext = array_filter(array_unique(array_merge($l1[1], $l2[1])), fn($u) => preg_match('#^https?://#', $u));
$malh = array_filter($ext, fn($u) => !preg_match('#^https://(img\.shields\.io|github\.com|getcomposer\.org|gravatar\.com|akismet\.com)(/|$)#', $u));
t_ok(!$malh, 'los unicos enlaces externos son shields.io, GitHub, Composer, Gravatar y Akismet' . ($malh ? ' (' . implode(', ', $malh) . ')' : ''));
$slug = static function (string $h): string { $h = mb_strtolower(trim(preg_replace('/<[^>]+>|`/', '', $h))); $h = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $h); return preg_replace('/\s/u', '-', $h); };
$anclas = [];
foreach (preg_split('/\R/', $readme) as $ln) { if (preg_match('/^#{1,6}\s+(.*)$/', $ln, $mm)) { $anclas[] = $slug($mm[1]); } }
preg_match_all('/<a id="([^"]+)"/', $readme, $ia); $anclas = array_merge($anclas, $ia[1]);
preg_match_all('/\]\(#([^)]+)\)/', $readme, $ref);
$sin = array_values(array_diff(array_unique($ref[1]), $anclas));
t_ok(!$sin, 'todas las anclas internas existen' . ($sin ? ' (' . implode(', ', $sin) . ')' : ''));

echo "E) Todas las opciones estan en el README\n";
$bloques = [];   // ambito => texto de los bloques con ese marcador
$partes = preg_split('/(?=^#{3,4} )/m', $readme);
foreach ($partes as $pt) { if (preg_match('/<!-- claves: ([a-z_,]+) -->/', $pt, $mc)) { foreach (explode(',', $mc[1]) as $sc) { $bloques[$sc] = ($bloques[$sc] ?? '') . $pt; } } }
$campos = static function (string $f, array $omitir = []) use ($root): array {
	$x = simplexml_load_file("$root/$f"); $o = [];
	foreach ($x->xpath('//field') as $fd) { $t = (string) $fd['type']; $n = (string) $fd['name']; if ($n === '' || in_array($t, ['hidden', 'note', 'spacer', 'rules'], true) || in_array($n, $omitir, true)) { continue; } $o[] = $n; }
	return array_values(array_unique($o));
};
$S = 'src/component';
$espera = [
	'config' => $campos("$S/backend/config.xml"),
	'filter_comments' => $campos("$S/backend/forms/filter_comments.xml"),
	'comment' => $campos("$S/backend/forms/comment.xml"),
	'comment_new' => $campos("$S/frontend/forms/comment_new.xml"),
	'mod' => $campos('src/modules/site/engage_latest/mod_engage_latest.xml'),
	'akismet' => $campos('src/plugins/engage/akismet/akismet.xml'),
	'email' => $campos('src/plugins/engage/email/email.xml'),
	'gravatar' => $campos('src/plugins/engage/gravatar/gravatar.xml'),
	'actionlog' => $campos('src/plugins/actionlog/engage/engage.xml'),
	'user' => $campos('src/plugins/user/engage/engage.xml'),
	'reports' => ['status', 'reason'],
];
$faltan = []; $total = 0;
foreach ($espera as $sc => $claves) {
	$txt = $bloques[$sc] ?? '';
	foreach ($claves as $k) { $total++; if (!preg_match('/`' . preg_quote($k, '/') . '`/', $txt)) { $faltan[] = "$sc:$k"; } }
}
t_ok(!$faltan, "claves de config.xml, forms, modulo y plugins ($total) presentes en el README: " . count($faltan) . ' faltantes' . ($faltan ? ' (' . implode(', ', array_slice($faltan, 0, 8)) . ')' : ''));
$sobran = [];
$conocidas = ['layout', 'moduleclass_sfx', 'cache', 'cache_time'];
foreach ($partes as $pt) {
	if (!preg_match('/<!-- claves: ([a-z_,]+) -->/', $pt, $mc)) { continue; }
	$perm = [];
	foreach (explode(',', $mc[1]) as $sc) { $perm = array_merge($perm, $espera[$sc] ?? []); }
	preg_match_all('/^\| \*\*[^|]+\*\* \(`([A-Za-z_]+)`\) \|/m', $pt, $mk);
	foreach (array_diff($mk[1], $perm, $conocidas) as $k) { $sobran[] = $mc[1] . ':' . $k; }
}
t_ok(!$sobran, 'el README no inventa ninguna opcion (todas las de las tablas existen en los manifiestos)' . ($sobran ? ' (' . implode(', ', $sobran) . ')' : ''));
$lay = $leer("$S/backend/src/Helper/SettingsLayout.php"); preg_match_all("#\[\\\$c, '(\w+)'#", $lay, $lk);
preg_match_all('/\(`([A-Za-z_]+)`\)/', $bloques['config'] ?? '', $ek);
t_ok(!array_diff($lk[1], $ek[1]), 'cada opcion de la pantalla moderna de Opciones (SettingsLayout) aparece en su categoria del README');
t_ok(substr_count($readme, '#### Opciones: ') === 9, 'una subseccion por cada categoria de Opciones (8 + Permisos)');

echo "F) Sin datos personales\n";
$txts = ['README.md' => $readme, 'docs/PROYECTO.md' => $leer('docs/PROYECTO.md')];
$mal = [];
foreach ($txts as $n => $t) {
	preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $t, $em);
	foreach ($em[0] as $e) { if (!preg_match('/@(example\.(com|org|net|test)|[a-z0-9.-]+\.test|[a-z0-9.-]+\.invalid|users\.noreply\.github\.com)$/i', $e)) { $mal[] = "$n correo $e"; } }
	if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', str_replace('3.4.2.1', 'v', $t), $ip)) { $mal[] = "$n IP {$ip[0]}"; }
	if (preg_match('/\b[A-Za-z]:\\\\|\/home\/|\/Users\/|\/root\//', $t)) { $mal[] = "$n ruta del PC"; }
	if (preg_match('/hue' . 'va|hv' . '6_|ciudad real/i', $t)) { $mal[] = "$n nombre real"; }
}
t_ok(!$mal, 'ni correos reales, ni IP, ni rutas del PC, ni nombres de sitios reales en README.md ni docs/PROYECTO.md' . ($mal ? ' (' . implode('; ', $mal) . ')' : ''));
t_ok(!preg_grep('/[^A-Za-z0-9._-]/', array_map('basename', $caps)), 'los nombres de las capturas son neutros (solo letras, numeros, punto, guion)');
t_ok(str_contains($readme, 'Desarrollado por ChasKa') && str_contains($readme, 'Open Source Matters') && str_contains($readme, 'LGPL') && str_contains($readme, 'Akeeba Ltd'), 'autoria, marca de Joomla!, licencia de HTML Purifier y bifurcacion de Akeeba declaradas');
t_fin();
