<?php
/**
 * 0.9.17: colaboracion con idiomas. build/idiomas.php (comprobar / plantilla / manifiestos), el build y su candado con un idioma de prueba
 * (limpio, con <script> y con una URL externa) en COPIAS temporales (nunca se toca src/), y las plantillas y guias de GitHub.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__);
require_once "$root/build/idiomas.php";
$leer = fn(string $r) => (string) file_get_contents("$root/$r");

echo "A) es-ES y en-GB pasan la herramienta\n";
$r = idiomasComprobar("$root/src", 'todos');
t_ok(!$r['errores'], 'comprobar todos: sin errores' . ($r['errores'] ? ' (' . implode(' | ', array_slice($r['errores'], 0, 3)) . ')' : ''));
t_ok(isset($r['resumen']['es-ES']) && $r['resumen']['es-ES']['textos'] > 900, 'es-ES: mas de 900 textos comparados (' . ($r['resumen']['es-ES']['textos'] ?? 0) . ')');
t_ok(array_keys($r['resumen']) === ['es-ES'], 'idiomas detectados: solo es-ES (ademas de en-GB); no se ha anadido ningun idioma real');
$ex = idiomasExtensiones("$root/src");
t_ok(count($ex) === 14, 'se comparan las 14 carpetas language/ de las 13 extensiones (componente: administracion y parte publica; modulo; paquete; 10 plugins): hay ' . count($ex));
t_ok(!is_dir("$root/src/component/backend/language/xx-XX"), 'el arbol real no tiene idioma de prueba');

echo "B) Reglas de un texto traducido\n";
$t = fn(string $o, string $x) => idiomasTexto($o, $x);
t_ok($t('Hello %s, you have %d new', 'Hola %s, tiene %d nuevos') === [], 'marcadores iguales: correcto');
t_ok($t('Hello %1$s and %2$s', 'Con %2$s y %1$s') === [], 'marcadores numerados en otro orden: correcto');
t_ok($t('Hello %s', 'Hola') !== [] && $t('Hello %s', 'Hola %s %s') !== [] && $t('Hello %s', 'Hola %d') !== [] && $t('Hi {name}', 'Hola {nombre}') !== [], 'marcadores que faltan, sobran o cambian de tipo: error');
t_ok($t('<p>Hi <strong>you</strong></p>', '<p>Hola <strong>usted</strong></p>') === [], 'las mismas etiquetas: correcto');
t_ok($t('Hi', '<script>alert(1)</script>') !== [] && $t('<p>Hi</p>', '<p>Hi</p><script src=x></script>') !== [], '<script>: error');
t_ok($t('<p>Hi</p>', '<p onclick="x()">Hi</p>') !== [] && $t('<p>Hi</p>', '<img src=x onerror=alert(1)>') !== [], 'on*= y etiquetas nuevas: error');
t_ok($t('Hi', '<b>Hola</b>') !== [], 'una etiqueta HTML que no esta en el original: error');
t_ok($t('<a href="https://www.example.com/a">x</a>', '<a href="https://www.example.com/a">y</a>') === [], 'la misma direccion del original: correcto');
t_ok($t('<a href="https://www.example.com/a">x</a>', '<a href="https://evil.example/a">y</a>') !== [] && $t('Hi', 'ver http://evil.example') !== [] && $t('Hi', 'ver //evil.example/x') !== [] && $t('Hi', 'ver www.evil.example') !== [], 'direcciones externas nuevas (https, http, //, www): error');
t_ok($t('<a href="x">x</a>', '<a href="javascript:alert(1)">y</a>') !== [] && $t('Hi', 'a data:text/html,x') !== [] && $t('Hi', 'java&#115;cript:alert(1)') !== [] && $t('Hi', 'j a v a s c r i p t :x') !== [], 'javascript:/data: (tambien con entidades o espacios): error');
t_ok($t('Say <name>', 'Diga <nombre>') !== [] && $t('Say &lt;name&gt;', 'Diga &lt;nombre&gt;') === [], 'un &lt;nombre&gt; escrito como entidad es texto, no una etiqueta');
t_ok($t('Hello', '') !== [] && $t('Hello', '   ') !== [] && $t('', '') === [], 'texto vacio donde el original lo tiene: error; vacio con vacio: correcto');
t_ok($t('Hello', "Hola\x07") !== [] && $t('Hello', 'Hola "x" y') !== [] && $t('Hello', 'Hola \\"x\\" y') === [], 'caracteres de control y comillas sin escapar: error; comillas escapadas: correcto');
$ini = sys_get_temp_dir() . '/eg-ini-' . bin2hex(random_bytes(3)) . '.ini';
foreach ([['A="ok"' . "\n" . '; nota' . "\n" . '[Sec]' . "\n" . 'B="2"', 0], ['A=sin comillas', 1], ['A="uno' . "\n" . 'dos"', 2], ["\xEF\xBB\xBFA=\"x\"", 1], ["A=\"\xC3\x28\"", 1], ['A="x"' . "\n" . 'A="y"', 1], ["A=\"x\x01y\"", 1], ["A=\"x\u{202E}y\"", 1]] as [$c, $n]) {
	file_put_contents($ini, $c); $lr = idiomasLeerIni($ini);
	t_ok(count($lr['errores']) >= ($n ? 1 : 0) && ($n === 0 ? !$lr['errores'] : true), 'formato .ini: ' . ($n ? 'se rechaza ' : 'se admite ') . json_encode(substr($c, 0, 24), JSON_UNESCAPED_UNICODE));
}
@unlink($ini);

echo "C) Idioma de prueba en una COPIA temporal: plantilla, comprobar, build y candado\n";
$tmp = sys_get_temp_dir() . '/engage-idiomas-' . bin2hex(random_bytes(4));
mkdir($tmp);
$copia = function () use ($tmp, $root): string {
	$d = $tmp . '/src-' . bin2hex(random_bytes(3));
	shell_exec('cp -a ' . escapeshellarg("$root/src") . ' ' . escapeshellarg($d));
	return $d;
};
$cli = fn(string $src, string $args) => (function () use ($src, $args, $root) { $o = []; exec('ENGAGE_BUILD_SRC=' . escapeshellarg($src) . ' php ' . escapeshellarg("$root/build/idiomas.php") . " $args 2>&1", $o, $rc); return [$rc, implode("\n", $o)]; })();
$build = function (string $src) use ($root, $tmp): array {
	$dist = $tmp . '/dist-' . bin2hex(random_bytes(3));
	$o = [];
	exec('ENGAGE_BUILD_SRC=' . escapeshellarg($src) . ' ENGAGE_BUILD_DIST=' . escapeshellarg($dist) . ' php ' . escapeshellarg("$root/build/build.php") . ' 2>&1', $o, $rc);
	return [$rc, implode("\n", $o), glob("$dist/pkg_engage-*.zip") ?: []];
};
$s = $copia();
[$rc, $out] = $cli($s, 'plantilla xx-XX');
$nEn = 0; foreach (idiomasExtensiones($s) as $d) { $nEn += count(glob("$d/en-GB/*.ini")); }
t_ok($rc === 0 && preg_match('/Creado xx-XX: (\d+) archivos/', $out, $m) && (int) $m[1] === $nEn && $nEn >= 26, "plantilla xx-XX: crea el idioma copiando en-GB en todas las extensiones ($nEn archivos)");
$n = 0; foreach (idiomasExtensiones($s) as $d) { $n += count(glob("$d/xx-XX/*.ini")) === count(glob("$d/en-GB/*.ini")) ? 1 : 0; }
t_ok($n === count(idiomasExtensiones($s)), 'cada una de las extensiones tiene tantos archivos xx-XX como en-GB (incluidos .sys.ini y los vacios)');
[$rc, $out] = $cli($s, 'plantilla xx-XX');
t_ok($rc !== 0 && str_contains($out, 'ya existe'), 'repetir la plantilla de un idioma existente: error claro, no pisa nada');
[$rc] = $cli($s, 'plantilla en-GB'); [$rc2] = $cli($s, 'plantilla xx'); [$rc3] = $cli($s, 'plantilla ../x');
t_ok($rc !== 0 && $rc2 !== 0 && $rc3 !== 0, 'etiquetas no validas (en-GB, xx, ../x): error');
$mal = 0; foreach (glob("$s/*/*.xml") + glob("$s/*/*/*/*.xml") as $x) { if (@simplexml_load_file($x) === false) { $mal++; } }
$mans = array_merge(glob("$s/component/*.xml"), glob("$s/package/*.xml"), glob("$s/modules/site/*/*.xml"), glob("$s/plugins/*/*/*.xml"));
$sin = []; foreach ($mans as $x) { $c = file_get_contents($x); if (str_contains($c, '<languages') && !str_contains($c, 'tag="xx-XX"')) { $sin[] = basename($x); } if (@simplexml_load_file($x) === false) { $mal++; } }
t_ok(!$sin && !$mal, 'los 13 manifiestos siguen siendo XML valido y listan los archivos xx-XX' . ($sin ? ' (sin: ' . implode(',', $sin) . ')' : ''));
$crlf = file_get_contents("$s/component/engage.xml"); t_ok(substr_count($crlf, "\n") === substr_count($crlf, "\r\n"), 'el manifiesto del componente conserva los finales de linea CRLF');
[$rc, $out] = $cli($s, 'comprobar xx-XX');
t_ok($rc === 0 && str_contains($out, 'Todo correcto') && str_contains($out, 'iguales al ingles'), 'comprobar xx-XX (sin traducir aun): pasa y avisa de que sigue en ingles');
[$rc, $out, $zips] = $build($s);
t_ok($rc === 0 && count($zips) === 1 && str_contains($out, 'Candado del empaquetador: sin problemas'), 'build con el idioma de prueba: se empaqueta y el candado lo admite sin tocar codigo');
$z = new ZipArchive(); $dentro = [];
if ($zips && $z->open($zips[0]) === true) {
	for ($i = 0; $i < $z->numFiles; $i++) {
		$nm = $z->getNameIndex($i);
		if (str_contains($nm, '/xx-XX/')) { $dentro['pkg'][] = $nm; }
		if (substr($nm, -4) !== '.zip') { continue; }
		$tp = tempnam(sys_get_temp_dir(), 'eg'); file_put_contents($tp, $z->getFromIndex($i)); $y = new ZipArchive();
		if ($y->open($tp) === true) { for ($j = 0; $j < $y->numFiles; $j++) { if (str_contains($y->getNameIndex($j), '/xx-XX/')) { $dentro[$nm][] = $y->getNameIndex($j); } } $y->close(); }
		@unlink($tp);
	}
	$z->close();
}
$nz = count($dentro, COUNT_RECURSIVE) - count($dentro);
t_ok(count($dentro) === 13 && $nz === $nEn, "el paquete y los ZIP de las 13 extensiones llevan los $nEn archivos xx-XX (hay $nz)");
// traduccion de verdad: cambiar un texto y que siga valiendo
$f = "$s/component/backend/language/xx-XX/com_engage.sys.ini"; $c = file_get_contents($f); $c = preg_replace('/^(COM_ENGAGE="|COM_ENGAGE_XML_DESCRIPTION=")[^"]*"/m', '$1Texto traducido"', $c, 1); file_put_contents($f, $c);
[$rc] = $cli($s, 'comprobar xx-XX'); t_ok($rc === 0, 'un texto traducido con normalidad sigue pasando');

$casos = [
	'<script> en un texto' => [fn($d) => idiomasEditar("$d/component/frontend/language/xx-XX/com_engage.ini", fn($c) => preg_replace('/^([A-Z0-9_]+=")[^"]*"/m', '$1<script>alert(1)</script>"', $c, 1)), '<script>'],
	'direccion externa en un texto' => [fn($d) => idiomasEditar("$d/modules/site/engage_latest/language/xx-XX/mod_engage_latest.ini", fn($c) => preg_replace('/^(MOD_ENGAGE_LATEST_DESCRIPTION=")[^"]*"/m', '$1Vea https://evil.example/x"', $c, 1)), 'direccion externa'],
	'on*= en un enlace' => [fn($d) => idiomasEditar("$d/component/backend/language/xx-XX/com_engage.ini", fn($c) => preg_replace('/^(COM_ENGAGE_EMAILTEMPLATES_LBL_WHERE_TEXT=")[^"]*"/m', '$1<a onmouseover=\\"x()\\">z</a>"', $c, 1)), 'manejador de eventos'],
	'falta una clave' => [fn($d) => idiomasEditar("$d/plugins/user/engage/language/xx-XX/plg_user_engage.ini", fn($c) => preg_replace('/^[A-Z0-9_]+="[^"]*"\R/m', '', $c, 1)), 'falta la clave'],
	'sobra una clave' => [fn($d) => file_put_contents("$d/plugins/user/engage/language/xx-XX/plg_user_engage.ini", "\nCLAVE_INVENTADA=\"x\"\n", FILE_APPEND), 'sobra la clave'],
	'marcador perdido' => [fn($d) => idiomasEditar("$d/component/backend/language/xx-XX/com_engage.ini", fn($c) => preg_replace('/^(COM_ENGAGE_[A-Z_]*=")([^"]*%s)([^"]*")/m', '$1sin marcador"', $c, 1)), 'marcadores distintos'],
	'archivo borrado' => [fn($d) => unlink("$d/component/backend/language/xx-XX/com_engage.sys.ini"), 'falta el archivo'],
	'archivo fuera del manifiesto' => [fn($d) => file_put_contents("$d/plugins/user/engage/language/xx-XX/plg_user_engage.extra.ini", "A=\"x\"\n"), 'no existe en en-GB'],
];
function idiomasEditar(string $f, callable $fn): void { file_put_contents($f, $fn((string) file_get_contents($f))); }
foreach ($casos as $nombre => [$mut, $frase]) {
	$d = $copia(); $cli($d, 'plantilla xx-XX'); $mut($d);
	[$rc, $out] = $cli($d, 'comprobar xx-XX');
	[$brc, $bout, $bz] = $build($d);
	t_ok($rc === 1 && str_contains($out, $frase), "idioma malicioso/roto ($nombre): la herramienta falla (codigo 1, dice por que)" . ($rc !== 1 || !str_contains($out, $frase) ? " [rc=$rc " . substr($out, 0, 160) . ']' : ''));
	t_ok($brc !== 0 && !$bz && str_contains($bout, 'IDIOMAS'), "idioma malicioso/roto ($nombre): el build FALLA y no deja paquete");
}
// manifiesto sin el idioma
$d = $copia(); $cli($d, 'plantilla xx-XX'); $x = "$d/plugins/user/engage/engage.xml"; file_put_contents($x, preg_replace('#\s*<language tag="xx-XX">[^<]*</language>#', '', file_get_contents($x)));
[$rc, $out] = $cli($d, 'comprobar xx-XX'); t_ok($rc === 1 && str_contains($out, 'no esta en el manifiesto'), 'archivo de idioma que no esta en el manifiesto: la herramienta falla y dice como arreglarlo');
[$rc2] = $cli($d, 'manifiestos xx-XX'); [$rc3] = $cli($d, 'comprobar xx-XX'); t_ok($rc2 === 0 && $rc3 === 0, '«manifiestos xx-XX» repara el manifiesto');
shell_exec('rm -rf ' . escapeshellarg($tmp));
t_ok(!is_dir("$root/src/component/backend/language/xx-XX"), 'src/ real intacto tras las pruebas');

echo "D) Plantillas de GitHub, guias y enlaces\n";
foreach (['docs/TRADUCIR.md', 'CONTRIBUTING.md', '.github/pull_request_template.md', '.github/ISSUE_TEMPLATE/traduccion.yml', 'build/idiomas.php'] as $f) { t_ok(is_file("$root/$f") && filesize("$root/$f") > 400, "existe $f"); }
$yml = $leer('.github/ISSUE_TEMPLATE/traduccion.yml');
foreach (['id: idioma', 'id: codigo', 'id: completa', 'id: aceptacion', 'GPL v3'] as $k) { t_ok(str_contains($yml, $k), "formulario de traduccion: $k"); }
t_ok(substr_count($yml, 'required: true') >= 5 && !preg_match('/correo|e-?mail/i', preg_replace('/No escriba datos personales[^\n]*\n/', '', $yml)) , 'formulario: campos obligatorios (idioma, codigo, completa, dos confirmaciones) y no pide correo ni datos personales');
$pr = $leer('.github/pull_request_template.md');
foreach (['idiomas.php comprobar', 'completa', 'marcadores', 'licencia', 'datos personales'] as $k) { t_ok(stripos($pr, $k) !== false, "plantilla de pull request: $k"); }
$tr = $leer('docs/TRADUCIR.md');
foreach (['php build/idiomas.php plantilla', 'php build/idiomas.php comprobar', '.sys.ini', '%1$s', 'usted', 'Glosario', 'pull request', 'issue', 'Criterios de aceptación', 'Créditos', 'NOTICE.md', 'GPL'] as $k) { t_ok(str_contains($tr, $k), "TRADUCIR.md cubre: $k"); }
$co = $leer('CONTRIBUTING.md');
t_ok(str_contains($co, 'Security') && str_contains($co, 'Report a vulnerability') && !preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/i', $co . $tr . $pr . $yml), 'seguridad: «Security > Report a vulnerability», y ningun correo en ninguna de las guias ni plantillas');
$rd = $leer('README.md');
t_ok(str_contains($rd, '(docs/TRADUCIR.md)') && str_contains($rd, '(CONTRIBUTING.md)') && str_contains($rd, 'Colaborar: traducciones'), 'README: seccion «Colaborar: traducciones» con enlaces a TRADUCIR.md y CONTRIBUTING.md');
$mal = []; foreach (['docs/TRADUCIR.md', 'CONTRIBUTING.md'] as $f) { preg_match_all('#\]\(([^)\s]+)\)#', $leer($f), $l); foreach ($l[1] as $u) { if (preg_match('#^(https://|\#)#', $u)) { continue; } $p = preg_replace('#[?\#].*$#', '', $u); if (!file_exists(dirname("$root/$f") . "/$p")) { $mal[] = "$f: $u"; } } }
t_ok(!$mal, 'los enlaces relativos de TRADUCIR.md y CONTRIBUTING.md existen' . ($mal ? ' (' . implode(', ', $mal) . ')' : ''));
$priv = ''; foreach (['docs/TRADUCIR.md', 'CONTRIBUTING.md', '.github/pull_request_template.md', '.github/ISSUE_TEMPLATE/traduccion.yml', 'build/idiomas.php', 'NOTICE.md'] as $f) { $c = $leer($f); if (preg_match('/hue' . 'va|hv' . '6_|\b[A-Za-z]:\\\\|\/home\/|\/Users\/|(?:\d{1,3}\.){3}\d{1,3}/i', $c)) { $priv .= " $f"; } }
t_ok($priv === '', 'sin datos personales (sitio real, rutas del equipo, IP) en las guias, plantillas y herramienta' . $priv);
t_ok(str_contains($leer('build/build.php'), "require_once __DIR__ . '/idiomas.php'") && str_contains($leer('build/build.php'), 'idiomasComprobar'), 'build.php comprueba los idiomas antes de empaquetar');
t_fin();
