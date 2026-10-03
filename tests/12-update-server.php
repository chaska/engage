<?php
/**
 * 0.6.1: servidor de actualizaciones propio (updates/pkgengage.xml).
 * Copyright (c)2026 fork comunitario de Engage; GNU General Public License v3 o posterior.
 *
 * Interpreta el XML con las clases REALES de Joomla (Update.php de 5.4-dev y de 6.1-dev, InstallerHelper.php y los
 * rasgos de Object, copiados sin modificar en tests/joomla-real/; ver LEEME.md). Solo se simulan sus dependencias
 * externas (Factory::getDbo, InputFilter, Version, Updater::STABILITY_*). No es un Joomla instalado.
 */
namespace Joomla\CMS { class Factory { public static $db; public static function getDbo() { return self::$db; } } class Version { const PRODUCT = 'Joomla!'; } }
namespace Joomla\CMS\Filter { class InputFilter { public static function getInstance() { return new self; } public function clean($v, $t) { return preg_replace('/[^A-Z_a-z-]/', '', $v); } } }
namespace Joomla\CMS\Updater { class Updater { const STABILITY_DEV = 0; const STABILITY_ALPHA = 1; const STABILITY_BETA = 2; const STABILITY_RC = 3; const STABILITY_STABLE = 4; } }

namespace {
	define('_JEXEC', 1);
	$raiz = dirname(__DIR__);

	if (($argv[1] ?? '') === 'hijo') {
		// hijo <rama> <joomla> <tipoBD> <versionBD> <xml> [zip]
		[, , $rama, $jver, $tipo, $vbd, $xmlFile] = $argv;
		$zip = $argv[7] ?? null;
		define('JVERSION', $jver);
		\Joomla\CMS\Factory::$db = new class($tipo, $vbd) { function __construct(public $t, public $v) {} function getServerType() { return $this->t; } function getVersion() { return $this->v; } };
		$r = "$raiz/tests/joomla-real";
		foreach (['Object/LegacyErrorHandlingTrait', 'Object/LegacyPropertyManagementTrait'] as $f) { require_once "$r/5.4-dev/$f.php"; }
		require_once "$r/5.4-dev/Updater/DownloadSource.php";
		require_once "$r/$rama/Updater/Update.php";
		require_once "$r/5.4-dev/Installer/InstallerHelper.php";

		$u = new \Joomla\CMS\Updater\Update();
		$u->setTargetVersion($jver);
		// Igual que Update::loadFromXml() (tras la descarga): estabilidad mínima, canal y analizador expat.
		(function () { $this->minimum_stability = \Joomla\CMS\Updater\Updater::STABILITY_STABLE; $this->channel = null; })->call($u);
		$p = xml_parser_create('');
		xml_set_element_handler($p, [$u, '_startElement'], [$u, '_endElement']);
		xml_set_character_data_handler($p, [$u, '_characterData']);
		$ok = xml_parse($p, file_get_contents($xmlFile));
		$sal = ['xml' => (bool) $ok, 'version' => $u->get('version') ? trim($u->get('version')->_data) : null,
			'element' => $u->get('element') ? trim($u->get('element')->_data) : null,
			'type' => $u->get('type') ? trim($u->get('type')->_data) : null,
			'url' => $u->get('downloadurl') ? trim($u->get('downloadurl')->_data) : null,
			'sha' => $u->get('sha256') ? trim($u->get('sha256')->_data) : null,
			'otra' => $u->get('otherUpdateInfo') ?: null];
		if ($zip && $sal['version'] !== null) {
			$sal['checksum'] = \Joomla\CMS\Installer\InstallerHelper::isChecksumValid($zip, $u);
		}
		echo json_encode($sal), "\n";
		exit(0);
	}

	require __DIR__ . '/aserciones.php';
	$xmlFile = "$raiz/updates/pkgengage.xml";

	function hijo(string $rama, string $j, string $t, string $v, string $xml, ?string $zip = null): array
	{
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' hijo ' . implode(' ', array_map('escapeshellarg', [$rama, $j, $t, $v, $xml])) . ($zip ? ' ' . escapeshellarg($zip) : '') . ' 2>&1';
		return json_decode(trim((string) shell_exec($cmd)), true) ?? ['xml' => false, 'error' => 'sin salida JSON'];
	}

	// --- Estructura y coherencia con el manifiesto
	$doc = new DOMDocument();
	t_ok($doc->load($xmlFile), 'updates/pkgengage.xml es XML bien formado');
	$pkg = simplexml_load_file("$raiz/src/package/pkg_engage.xml");
	$ver = (string) $pkg->version;
	$srv = trim((string) $pkg->updateservers->server);
	t_ok($srv === 'https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml', 'pkg_engage.xml apunta a la URL propia (raw.githubusercontent.com/chaska/engage/main)');
	t_ok(!str_contains(file_get_contents("$raiz/src/package/pkg_engage.xml"), 'cdn.akeeba.com'), 'pkg_engage.xml ya no menciona cdn.akeeba.com');
	t_ok(count($pkg->updateservers->server) === 1 && (string) $pkg->updateservers->server['type'] === 'extension', 'un único update server de tipo extension (Joomla reubica el update site existente solo si hay uno)');
	$x = simplexml_load_file($xmlFile);
	t_ok(count($x->update) === 1, 'una sola entrada <update>');
	t_ok((string) $x->update->element === (string) $pkg->name && (string) $x->update->type === 'package', 'element/type coinciden con el paquete (pkg_engage, package)');
	t_ok((string) $x->update->version === $ver, "version del XML = version del manifiesto ($ver)");
	$url = trim((string) $x->update->downloads->downloadurl);
	t_ok($url === "https://github.com/chaska/engage/releases/download/v$ver/pkg_engage-$ver.zip", 'downloadurl HTTPS a la release v' . $ver);
	t_ok((string) $x->update->targetplatform['version'] === '((5\.[0-9])|(6\.[0-9]))' && (string) $x->update->targetplatform['name'] === 'joomla', 'targetplatform joomla 5.x y 6.x');
	t_ok((string) $x->update->php_minimum === '8.1', 'php_minimum 8.1');
	t_ok((string) $x->update->supported_databases['mysql'] === '8.0.13' && (string) $x->update->supported_databases['mariadb'] === '10.4.0' && !isset($x->update->supported_databases['postgresql']), 'supported_databases: mysql 8.0.13 y mariadb 10.4.0, sin postgresql (el esquema es solo MySQL/MariaDB)');
	t_ok((string) $x->update->tags->tag === 'stable', 'tag stable');
	$sha = trim((string) $x->update->sha256);
	t_ok((bool) preg_match('/^[0-9a-f]{64}$/', $sha) && $sha !== str_repeat('0', 64), 'sha256 con formato válido y no es el marcador');

	// --- sha256 frente al ZIP construido
	$zip = "$raiz/dist/pkg_engage-$ver.zip";
	if (!is_file($zip)) { exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$raiz/build/build.php") . ' > /dev/null 2>&1'); $x = simplexml_load_file($xmlFile); $sha = trim((string) $x->update->sha256); }
	t_ok(is_file($zip) && hash_file('sha256', $zip) === $sha, 'sha256 del XML = sha256 de dist/pkg_engage-' . $ver . '.zip');

	t_ok(str_contains(file_get_contents("$raiz/docs/PUBLICAR-RELEASE.md"), $sha), 'docs/PUBLICAR-RELEASE.md cita el sha256 vigente');

	// --- Analizador real de Joomla (Update.php 5.4-dev y 6.1-dev)
	foreach (['5.4-dev', '6.1-dev'] as $rama) {
		foreach (['5.0.0', '5.4.2', '6.0.0', '6.1.5'] as $j) {
			$r = hijo($rama, $j, 'mysql', '8.0.30', $xmlFile, $zip);
			t_ok($r['xml'] && $r['version'] === $ver && $r['element'] === 'pkg_engage' && $r['type'] === 'package' && $r['url'] === $url && $r['sha'] === $sha, "[$rama] Joomla $j: ofrece $ver (element, type, url y sha256 leídos)");
			t_ok(($r['checksum'] ?? null) === 1, "[$rama] Joomla $j: InstallerHelper::isChecksumValid acepta el ZIP");
		}
		foreach (['4.4.14', '3.10.12', '7.0.0'] as $j) {
			$r = hijo($rama, $j, 'mysql', '8.0.30', $xmlFile);
			t_ok($r['xml'] && $r['version'] === null, "[$rama] Joomla $j: no se ofrece (fuera de targetplatform)");
		}
		$r = hijo($rama, '6.1.5', 'mysql', '8.0.13', $xmlFile);
		t_ok($r['version'] === $ver, "[$rama] MySQL 8.0.13 (mínimo exacto): se ofrece");
		$r = hijo($rama, '6.1.5', 'mysql', '5.5.5-10.6.12-MariaDB', $xmlFile);
		t_ok($r['version'] === $ver, "[$rama] MariaDB 10.6.12: se ofrece");
		$r = hijo($rama, '6.1.5', 'mysql', '5.5.5-10.3.39-MariaDB', $xmlFile);
		t_ok($r['version'] === null && isset($r['otra']['db']), "[$rama] MariaDB 10.3.39: no se ofrece (db)");
		$r = hijo($rama, '6.1.5', 'mysql', '5.7.44', $xmlFile);
		t_ok($r['version'] === null && isset($r['otra']['db']), "[$rama] MySQL 5.7.44: no se ofrece (db)");
		$r = hijo($rama, '6.1.5', 'postgresql', '14.5', $xmlFile);
		t_ok($r['version'] === null, "[$rama] PostgreSQL: no se ofrece (el esquema no lo soporta)");
	}
	// php_minimum: una copia del XML exigiendo un PHP imposible no se ofrece (comprueba que el analizador lo lee)
	$tmp = tempnam(sys_get_temp_dir(), 'upd');
	file_put_contents($tmp, str_replace('<php_minimum>8.1</php_minimum>', '<php_minimum>99.0</php_minimum>', file_get_contents($xmlFile)));
	$r = hijo('5.4-dev', '6.1.5', 'mysql', '8.0.30', $tmp);
	t_ok($r['version'] === null && isset($r['otra']['php']), 'php_minimum se evalúa (99.0: no se ofrece)');
	// sha256 alterado: Joomla rechazaría la descarga
	file_put_contents($tmp, str_replace($sha, str_repeat('a', 64), file_get_contents($xmlFile)));
	$r = hijo('5.4-dev', '6.1.5', 'mysql', '8.0.30', $tmp, $zip);
	t_ok(($r['checksum'] ?? null) === 0, 'con un sha256 distinto isChecksumValid rechaza el ZIP');
	unlink($tmp);
	t_fin();
}
