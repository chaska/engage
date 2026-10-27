<?php
/**
 * 0.9.15: el candado del empaquetador (build/auditoria.php + build/build.php). Funciones con contenido simulado y el build real sobre una COPIA de src con
 * cosas inyectadas: debe FALLAR (codigo 1, sin paquete) con una URL externa, una imagen, JS peligroso, un correo real, una cadena del entorno y una libreria
 * de terceros modificada; y pasar con la copia limpia.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__);
require_once "$root/build/auditoria.php";

echo "A) Reglas con contenido simulado\n";
$p = fn(string $ruta, string $c, bool $t = false) => audFichero($ruta, $c, $t);
t_ok($p('media/css/a.css', '.a{background:url(https://evil.example/x.png)}') !== [], 'CSS con una imagen externa: problema');
t_ok($p('media/css/a.css', '@import url("//cdn.evil.example/f.css");') !== [], 'CSS con @import externo (protocolo relativo): problema');
t_ok($p('media/css/a.css', ".a{background:url(\"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg'/>\")}") === [], 'CSS con data: SVG y su xmlns: permitido (no carga nada)');
t_ok($p('media/css/a.css', '.a{background:url(https://www.gravatar.com/avatar/x)}') !== [], 'CSS con Gravatar: problema (en CSS no se permite ni Gravatar)');
t_ok($p('media/js/a.js', 'var u="https://cdn.evil.example/x.js";') !== [], 'JS con un host externo: problema');
t_ok($p('media/js/a.js', 'var r=/^https:\/\/www\.gravatar\.com\/avatar\//; var u="https://www.gravatar.com/avatar/x";') === [], 'JS con la direccion de Gravatar (funcion del plugin): permitido');
t_ok($p('src/A.php', '// https://github.com/chaska/engage\n$x="https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml";') === [], 'PHP con el repositorio del fork: permitido');
t_ok($p('src/A.php', '$x="https://github.com/otro/repo";') !== [] && $p('src/A.php', '$x="https://github.com/chaska/engage.evil.example/x";') !== [] && $p('src/A.php', '$x="https://www.gravatar.com.evil.example/x";') !== [], 'hosts parecidos (otro repositorio, sufijos engañosos): problema');
t_ok($p('x/a.xml', '<x xmlns="http://www.w3.org/2000/svg"/>') === [] && $p('x/a.xml', '<u>https://example.com/p</u>') === [], 'espacios de nombres y ejemplos: permitidos');
foreach (['innerHTML' => 'a.innerHTML="x";', 'outerHTML' => 'a.outerHTML="x";', 'insertAdjacentHTML' => 'a.insertAdjacentHTML("beforeend",s);', 'eval' => 'eval("1")', 'new Function' => 'var f=new Function("return 1");', 'document.write' => 'document.write("x")', 'setTimeout con cadena' => 'setTimeout("alert(1)",10)'] as $n => $c) {
	t_ok($p('media/js/a.js', $c) !== [], "JS con $n: problema");
}
t_ok($p('media/js/a.js', 'a.textContent="x"; setTimeout(function(){},10); x.evaluate(1); retrieval(2);') === [], 'JS correcto (textContent, setTimeout con funcion, nombres parecidos a eval): sin problemas');
foreach (['a.png', 'x/captura.jpg', 'f.JPEG', 'g.gif', 'h.webp', 'm.eml', 'd.sqlite', 'v.dump', 'v.sql.gz', 'e.log', '.env', 'k.pem'] as $f) { t_ok($p($f, 'x') !== [], "fichero $f: prohibido"); }
t_ok($p('tests/x.php', '<?php') !== [] && $p('docs/a.php', '<?php') !== [] && $p('.git/config', 'x') !== [] && $p('upstream/a.php', '<?php') !== [], 'directorios tests, docs, .git y upstream: prohibidos');
t_ok($p('a.exe', 'x') !== [] && $p('a.sh', 'x') !== [] && $p('a.phar', 'x') !== [], 'extension fuera de la lista cerrada (exe, sh, phar): problema');
t_ok($p('src/a.php', '<?php // hue' . 'va') !== [] && $p('src/a.php', '$t="hv' . '6_content";') !== [] && $p('src/a.php', '$p="/home/chas/x";') !== [] && $p('src/a.php', '$p="Aa1!secreto";') !== [] && $p('src/a.php', '-----BEGIN PRIVATE KEY-----') !== [], 'cadenas del entorno (sitio real, prefijo de tablas, ruta /home, contraseña de prueba, clave privada): problema');
t_ok($p('src/a.php', '$m="persona@gmail.com";') !== [] && $p('src/a.php', '$m="a@example.com"; $n="b@x.invalid"; $o="c@sitio.test"; $q="nicholas@dionysopoulos.me";') === [], 'correo real: problema; example/invalid/test y los de los creditos: permitidos');
t_ok($p('vendor/x/a.js', 'eval("1"); var u="https://x.evil.example/";', true) === [] && $p('vendor/x/a.png', 'x', true) !== [], 'terceros: el contenido se fija por huella, pero una imagen sigue prohibida');

echo "B) Huella de las librerias de terceros\n";
$lista = "$root/build/terceros.sha256";
t_ok(is_file($lista) && auditoriaTerceros("$root/src", $lista) === [], 'la lista de huellas existe y coincide con el vendor actual');
t_ok(count(preg_grep('/^[0-9a-f]{64}  /', file($lista))) >= 300, 'la lista fija mas de 300 ficheros de terceros');

echo "C) El build real sobre una copia con cosas inyectadas\n";
$tmp = sys_get_temp_dir() . '/engage-candado-' . bin2hex(random_bytes(4));
mkdir($tmp);
$copia = function () use ($tmp, $root): string {
	$d = $tmp . '/src-' . bin2hex(random_bytes(3));
	shell_exec('cp -a ' . escapeshellarg("$root/src") . ' ' . escapeshellarg($d));

	return $d;
};
$build = function (string $src) use ($root, $tmp): array {
	$dist = $tmp . '/dist-' . bin2hex(random_bytes(3));
	$o    = [];
	exec('ENGAGE_BUILD_SRC=' . escapeshellarg($src) . ' ENGAGE_BUILD_DIST=' . escapeshellarg($dist) . ' php ' . escapeshellarg("$root/build/build.php") . ' 2>&1', $o, $rc);

	return [$rc, implode("\n", $o), glob("$dist/pkg_engage-*.zip") ?: []];
};
[$rc, $out, $zips] = $build($c0 = $copia());
t_ok($rc === 0 && count($zips) === 1 && str_contains($out, 'Candado del empaquetador: sin problemas'), 'copia limpia: el build pasa y genera el paquete');
$casos = [
	'URL externa inyectada en un JS propio' => [fn($d) => file_put_contents("$d/component/media/js/tools.js", "\nvar x = 'https://cdn.evil.example/steal.js';\n", FILE_APPEND), 'URL externa no permitida'],
	'URL externa inyectada en un CSS propio' => [fn($d) => file_put_contents("$d/component/media/css/replies.css", "\n.x{background:url(https://evil.example/p.gif)}\n", FILE_APPEND), 'URL externa no permitida'],
	'URL externa inyectada en un PHP propio' => [fn($d) => file_put_contents("$d/component/backend/src/Helper/Timer.php", "\n// http://evil.example/beacon\n", FILE_APPEND), 'URL externa no permitida'],
	'imagen (captura) dentro de la extension' => [fn($d) => file_put_contents("$d/component/media/captura.png", "\x89PNG"), 'tipo de fichero prohibido'],
	'correo guardado dentro de la extension' => [fn($d) => file_put_contents("$d/component/media/mensaje.eml", "From: x"), 'tipo de fichero prohibido'],
	'innerHTML inyectado en un JS propio' => [fn($d) => file_put_contents("$d/component/media/js/tools.js", "\nel.innerHTML = s;\n", FILE_APPEND), 'innerHTML'],
	'eval inyectado en un JS propio' => [fn($d) => file_put_contents("$d/component/media/js/reactions.js", "\neval(x);\n", FILE_APPEND), 'eval('],
	'cadena del entorno (sitio real)' => [fn($d) => file_put_contents("$d/component/backend/src/Helper/Timer.php", "\n// hue" . "va.net\n", FILE_APPEND), 'entorno'],
	'correo real' => [fn($d) => file_put_contents("$d/component/backend/src/Helper/Timer.php", "\n// persona.real@gmail.com\n", FILE_APPEND), 'correo que no es de ejemplo'],
	'libreria de terceros modificada' => [fn($d) => file_put_contents("$d/component/backend/vendor/ezyang/htmlpurifier/library/HTMLPurifier.php", "\n// puerta trasera\n", FILE_APPEND), 'tercero MODIFICADO'],
	'libreria de terceros anadida' => [fn($d) => file_put_contents("$d/component/backend/vendor/ezyang/extra.php", "<?php // x", FILE_APPEND), 'tercero NUEVO'],
];
foreach ($casos as $nombre => [$inyecta, $esperado]) {
	$d = $copia(); $inyecta($d); [$rc, $out, $zips] = $build($d);
	t_ok($rc === 1 && !$zips && str_contains($out, 'CANDADO DEL EMPAQUETADOR') && str_contains($out, $esperado), "$nombre: el build FALLA, no deja paquete y dice por que" . ($rc === 1 ? '' : " (rc=$rc)"));
}
shell_exec('rm -rf ' . escapeshellarg($tmp));
$bs = (string) file_get_contents("$root/build/build.php");
t_ok(str_contains($bs, 'auditoriaZip($final)') && str_contains($bs, 'auditoriaTerceros(') && str_contains($bs, "exit(1)"), 'build.php llama al candado y sale con 1');
t_fin();
