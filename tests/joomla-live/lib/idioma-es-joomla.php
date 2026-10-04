<?php
/**
 * 0.6.20: carga REAL (analizador y clase Language de Joomla) de los .ini es-ES que el instalador copio de Engage.
 * Para cada .ini de language/es-ES y administrator/language/es-ES de Engage: el analizador de Joomla lee TODAS las claves de su en-GB,
 * la clase Language('es-ES') sirve exactamente la cadena espanola y no queda ninguna clave sin traducir (valor == clave) ni
 * identica al ingles salvo lista blanca. Escribe $WORK/resultados-idioma-es-joomla.json. Sale con 1 si algo FALLA.
 */
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
define('_JEXEC', 1);
define('JPATH_BASE', $WORK . '/' . (getenv('SITE_DIR') ?: 'site'));
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageHelper;

$blanca = ['akeeba engage', 'html purifier', 'composer', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'spam', 'john doe', 'john.doe@example.com', 'nicholas k. dionysopoulos / akeeba ltd'];
$RES = [];
function r(string $d, bool $ok, string $ev = ''): void
{
	global $RES;
	$RES[] = ['desc' => $d, 'estado' => $ok ? 'PASA' : 'FALLA', 'ev' => $ev];
	printf("%-6s %s%s\n", $ok ? 'PASA' : 'FALLA', $d, $ev !== '' ? "  [$ev]" : '');
}
$total = 0;
foreach ([JPATH_SITE => 'sitio', JPATH_ADMINISTRATOR => 'administracion'] as $base => $zona) {
	foreach (glob($base . '/language/es-ES/*.ini') as $es) {
		$ext = basename($es, '.ini');
		if (!preg_match('/^(com_engage|mod_engage|plg_\w+_?engage\w*|pkg_engage)/', $ext) && strpos($ext, 'engage') === false) { continue; }
		$en = $base . '/language/en-GB/' . basename($es);
		if (!is_file($en)) { r("$zona/$ext: existe el en-GB instalado", false, basename($es)); continue; }
		$tes = LanguageHelper::parseIniFile($es);
		$ten = LanguageHelper::parseIniFile($en);
		$sin = array_diff(array_keys($ten), array_keys($tes));
		$extra = array_diff(array_keys($tes), array_keys($ten));
		$l = new Language('es-ES', false);
		$cargado = $l->load($ext, $base, 'es-ES', true);
		$difiere = 0; $mal = [];
		foreach ($tes as $k => $v) {
			$total++;
			if ($l->_($k) !== str_replace('\\n', "\n", $v)) { $mal[] = "$k no se sirve"; }
			if ($v === $k) { $mal[] = "$k sin traducir"; }
			if (isset($ten[$k]) && trim($v) === trim($ten[$k]) && $v !== '' && !in_array(strtolower(trim($v)), $GLOBALS['blanca'], true)) { $mal[] = "$k identica al ingles"; }
		}
		r("$zona/" . basename($es) . ': ' . count($tes) . ' claves es-ES leidas por Joomla = ' . count($ten) . ' del en-GB, cargado y servido', ($cargado || !$tes) && !$sin && !$extra && !$mal, implode('; ', array_slice(array_merge($sin ? ['faltan ' . implode(',', array_slice($sin, 0, 3))] : [], $extra ? ['sobran ' . implode(',', array_slice($extra, 0, 3))] : [], $mal), 0, 4)));
	}
}
r("claves es-ES verificadas en total: $total", $total > 400);
file_put_contents("$WORK/resultados-idioma-es-joomla.json", json_encode($RES, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$f = count(array_filter($RES, fn($z) => $z['estado'] === 'FALLA'));
echo count($RES) - $f . " PASA / $f FALLA\n";
exit($f ? 1 : 0);
