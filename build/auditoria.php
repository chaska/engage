<?php
/**
 * Candado del empaquetador (0.9.15). Se usa desde build/build.php y desde las pruebas. Un fallo aqui es un fallo del build: no se genera paquete.
 *
 * Que comprueba, sobre el contenido REAL de cada ZIP (el de cada extension y el del paquete, incluidos los ZIP anidados):
 *  1. nada que no deba viajar: ni imagenes/capturas (png, jpg...), ni correos (.eml), ni volcados o bases de datos, ni .git, ni tests/docs/upstream/build,
 *     ni registros, claves o ficheros de entorno; y SOLO extensiones de la lista cerrada de abajo (el resto, fallo);
 *  2. ninguna URL externa en el codigo PROPIO (todo lo que no esta en vendor/): CSS sin ninguna; JS, HTML y SVG solo las de la lista de cada tipo;
 *     PHP, XML, INI y demas solo las de la lista general. Un host nuevo hay que anadirlo AQUI, a proposito, y justificarlo en el CHANGELOG;
 *  3. en el JS propio nada de innerHTML/outerHTML/insertAdjacentHTML, eval, new Function, document.write ni setTimeout/setInterval con una cadena;
 *  4. ninguna cadena del entorno de quien desarrolla o prueba (nombres de sitios, prefijos de tablas reales, rutas /home, contrasenas de prueba, claves
 *     privadas) ni correos reales (solo example.*, .invalid, .test y los de los creditos);
 *  5. la huella SHA-256 de cada fichero de las librerias de terceros (backend/vendor) coincide con build/terceros.sha256 (ni uno mas, ni uno menos, ni uno
 *     distinto): una libreria no cambia sin que alguien lo decida y regenere la lista (php build/build.php --fijar-terceros).
 */

/** Extensiones permitidas dentro de un ZIP de extension (lista cerrada). */
const AUD_EXT = ['php', 'xml', 'ini', 'sql', 'css', 'js', 'json', 'svg', 'html', 'woff', 'woff2', 'ser', 'htaccess', 'config', 'txt', 'map', 'scss', 'md', 'in'];

/** Nombres sin extension permitidos. */
const AUD_SIN_EXT = ['LICENSE', 'VERSION', 'README', 'NEWS', 'CREDITS', 'COPYING', 'INSTALL', 'index'];

/** Imagenes, correos, volcados, bases de datos, claves... nunca. */
const AUD_PROHIBIDAS = '/\.(png|jpe?g|gif|webp|bmp|ico|tiff?|avif|heic|psd|eml|msg|sqlite3?|db|dump|bak|gz|tgz|tar|7z|rar|log|env|pem|key|p12|pfx|swp)$/i';

/** Directorios que no pueden aparecer en la ruta de un fichero del paquete. */
const AUD_DIRS_PROHIBIDOS = '#(^|/)(\.git|\.svn|\.hg|tests?|docs?|upstream|build|dist|node_modules|capturas|coverage)(/|$)#i';

/** Hosts permitidos en el codigo propio, por tipo. Cada entrada: host (con o sin ruta inicial). */
const AUD_URL_GENERAL = [
	'github.com/chaska/engage', 'raw.githubusercontent.com/chaska/engage', // repositorio y servidor de actualizaciones del fork
	'www.akeeba.com', 'cdn.akeeba.com/updates/pkgengage.xml',              // creditos y servidor de actualizaciones original (codigo muerto de UpdatesModel)
	'www.gnu.org/licenses', 'www.gnu.org/copyleft',                         // licencia
	'www.w3.org',                                                           // espacios de nombres XML/SVG/XHTML
	'schema.org',                                                           // microdatos
	'developer.joomla.org/schemas', 'docs.joomla.org',                      // esquema del manifiesto y documentacion
	'www.iis.net', 'developer.mozilla.org', 'github.com/mtibben/html2text', // comentarios de documentacion/credito
	'www.gravatar.com', 'gravatar.com', 'secure.gravatar.com',              // funcion del plugin de Gravatar (con consentimiento)
	'akismet.com', 'rest.akismet.com',                                      // funcion del plugin de Akismet
	'example.com', 'www.example.com', 'example.org', 'otro.host',           // ejemplos
];
const AUD_URL_JS   = ['www.w3.org', 'www.gravatar.com', 'gravatar.com', 'github.com/chaska/engage'];
const AUD_URL_HTML = ['www.w3.org'];
const AUD_URL_CSS  = ['www.w3.org/2000/svg']; // solo el espacio de nombres xmlns de un SVG en un data:URI (no carga nada)

/** Correos permitidos (dominios o sufijos). */
const AUD_CORREOS = '/@(example\.(com|org|net)|[a-z0-9.-]*\.invalid|[a-z0-9.-]*\.test|akeeba\.com|dionysopoulos\.me|chuggnutt\.com|ejemplo\.[a-z]+|domain\.tld|email\.invalid)$/i';

/** Cadenas que delatan el entorno de quien desarrolla o prueba. */
const AUD_ENTORNO = '/sitio de pruebas|\bjos_|el hosting|\/home\/[a-z]|\/Users\/[A-Za-z]|C:\\\\Users|Aa1!|BEGIN (RSA |EC |OPENSSH |)PRIVATE KEY|AKIA[0-9A-Z]{16}/i';

function audTipo(string $ruta): string
{
	$b = basename($ruta);

	return strtolower(pathinfo($b, PATHINFO_EXTENSION)) ?: strtolower($b);
}

/** @return string[] problemas de UN fichero (ruta dentro del ZIP, contenido). */
function audFichero(string $ruta, string $contenido, bool $tercero): array
{
	$p  = [];
	$ext = audTipo($ruta);
	$b   = basename($ruta);

	if (preg_match(AUD_DIRS_PROHIBIDOS, $ruta) && !$tercero) {
		$p[] = "$ruta: directorio que no debe viajar en el paquete";
	}

	if (preg_match(AUD_PROHIBIDAS, $b)) {
		$p[] = "$ruta: tipo de fichero prohibido (imagenes, correos, volcados, bases, registros, claves)";

		return $p;
	}

	$sinExt = (pathinfo($b, PATHINFO_EXTENSION) === '') && ($b[0] !== '.');
	$okExt  = in_array($ext, AUD_EXT, true) || ($b[0] === '.' && $b === '.htaccess') || ($sinExt && in_array($b, AUD_SIN_EXT, true));

	if (!$okExt) {
		$p[] = "$ruta: extension no permitida (lista cerrada del empaquetador)";

		return $p;
	}

	if (in_array($ext, ['woff', 'woff2', 'ser'], true)) {
		return $p;
	}

	if ($tercero) {
		return $p; // las librerias de terceros se fijan por huella (auditoriaTerceros)
	}

	// --- URLs externas
	$permitidas = AUD_URL_GENERAL;

	if (in_array($ext, ['css', 'scss'], true)) {
		$permitidas = AUD_URL_CSS;
	} elseif ($ext === 'js') {
		$permitidas = AUD_URL_JS;
	} elseif (in_array($ext, ['html', 'svg'], true)) {
		$permitidas = AUD_URL_HTML;
	}

	if (preg_match_all('#(?<![A-Za-z0-9_])((?:https?:)?//)([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})(/[^\s"\'<>)`\\\\]*)?#', $contenido, $m, PREG_SET_ORDER)) {
		foreach ($m as $u) {
			$host = strtolower($u[2]);
			$ruta2 = $host . strtolower($u[3] ?? '');
			$ok   = false;

			foreach ($permitidas as $a) {
				if ($ruta2 === $a || str_starts_with($ruta2, rtrim($a, '/') . '/') || ($a === $host) || (!str_contains($a, '/') && str_ends_with($host, '.' . $a) && false)) {
					$ok = true;
					break;
				}
			}

			if (!$ok && preg_match('/^(www\.)?example\.(com|org|net)$/', $host)) {
				$ok = true;
			}

			if (!$ok) {
				$p[] = "$ruta: URL externa no permitida en " . strtoupper($ext) . " propio: " . substr($u[0], 0, 80);
			}
		}
	}

	// --- JS peligroso
	if ($ext === 'js') {
		foreach (['\binnerHTML\b' => 'innerHTML', '\bouterHTML\b' => 'outerHTML', '\binsertAdjacentHTML\b' => 'insertAdjacentHTML', '(?<![A-Za-z0-9_.])eval\s*\(' => 'eval(', '\bnew\s+Function\b' => 'new Function', '\bdocument\s*\.\s*write(ln)?\s*\(' => 'document.write', '\bset(Timeout|Interval)\s*\(\s*[\'"`]' => 'setTimeout/setInterval con cadena'] as $re => $n) {
			if (preg_match('/' . $re . '/', $contenido)) {
				$p[] = "$ruta: JS propio con $n";
			}
		}
	}

	// --- entorno de quien desarrolla
	if (preg_match(AUD_ENTORNO, $contenido, $mm)) {
		$p[] = "$ruta: cadena del entorno de desarrollo o de un sitio real: " . substr($mm[0], 0, 40);
	}

	if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $contenido, $mc)) {
		foreach ($mc[0] as $e) {
			if (!preg_match(AUD_CORREOS, $e)) {
				$p[] = "$ruta: correo que no es de ejemplo: $e";
			}
		}
	}

	return $p;
}

/** Ruta dentro de la extension: ¿es de una libreria de terceros? */
function audEsTercero(string $ruta): bool
{
	return (bool) preg_match('#(^|/)vendor/#', $ruta);
}

/**
 * Revisa un ZIP (y los ZIP que contiene, un nivel o mas).
 *
 * @return string[]
 */
function auditoriaZip(string $zipPath, string $etiqueta = '', int $nivel = 0): array
{
	$p   = [];
	$zip = new ZipArchive();

	if ($zip->open($zipPath) !== true) {
		return ["$etiqueta: no se puede abrir el ZIP"];
	}

	for ($i = 0; $i < $zip->numFiles; $i++) {
		$n = $zip->getNameIndex($i);

		if (str_ends_with($n, '/')) {
			continue;
		}

		$c = (string) $zip->getFromIndex($i);

		if (strtolower(pathinfo($n, PATHINFO_EXTENSION)) === 'zip') {
			if ($nivel >= 1) {
				$p[] = "$etiqueta$n: ZIP anidado donde no corresponde";
				continue;
			}

			$tmp = tempnam(sys_get_temp_dir(), 'aud');
			file_put_contents($tmp, $c);
			$p = array_merge($p, auditoriaZip($tmp, "$etiqueta$n > ", $nivel + 1));
			@unlink($tmp);
			continue;
		}

		// En el ZIP del PAQUETE (nivel 0) solo hay manifiesto, script, idiomas y los ZIP de las extensiones
		$p = array_merge($p, audFichero($n, $c, audEsTercero($n)));
	}

	$zip->close();

	return array_map(static fn(string $x): string => $etiqueta . $x, $p);
}

/**
 * Huellas de las librerias de terceros: lista "sha256  ruta" en build/terceros.sha256 frente al contenido de src/component/backend/vendor.
 *
 * @return string[]
 */
function auditoriaTerceros(string $srcDir, string $lista): array
{
	$dir = "$srcDir/component/backend/vendor";
	$p   = [];

	if (!is_file($lista)) {
		return ["falta $lista (php build/build.php --fijar-terceros)"];
	}

	$esperado = [];

	foreach (preg_split('/\r?\n/', trim((string) file_get_contents($lista))) as $l) {
		if ($l !== '' && $l[0] !== '#' && preg_match('/^([0-9a-f]{64})  (.+)$/', $l, $m)) {
			$esperado[$m[2]] = $m[1];
		}
	}

	$real = audHuellas($dir);

	foreach ($real as $r => $h) {
		if (!isset($esperado[$r])) {
			$p[] = "tercero NUEVO no fijado: vendor/$r";
		} elseif ($esperado[$r] !== $h) {
			$p[] = "tercero MODIFICADO: vendor/$r";
		}
	}

	foreach ($esperado as $r => $h) {
		if (!isset($real[$r])) {
			$p[] = "tercero AUSENTE: vendor/$r";
		}
	}

	return $p;
}

/** @return array<string,string> ruta relativa => sha256 */
function audHuellas(string $dir): array
{
	$res = [];

	if (!is_dir($dir)) {
		return $res;
	}

	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));

	foreach ($it as $f) {
		if ($f->isFile()) {
			// Las terminaciones de linea no cuentan (git puede cambiarlas): la huella es la del contenido con \n
			$res[substr($f->getPathname(), strlen($dir) + 1)] = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($f->getPathname())));
		}
	}

	ksort($res, SORT_STRING);

	return $res;
}

function auditoriaFijarTerceros(string $srcDir, string $lista): int
{
	$h   = audHuellas("$srcDir/component/backend/vendor");
	$out = "# Huellas SHA-256 de las librerias de terceros incluidas (src/component/backend/vendor). Se comprueban en cada build. Regenerar SOLO a proposito: php build/build.php --fijar-terceros\n";

	foreach ($h as $r => $x) {
		$out .= "$x  $r\n";
	}

	file_put_contents($lista, $out);

	return count($h);
}
