<?php
/**
 * 0.8.3 (H8): 
 *  a) Akismet: la clave de API forma parte del NOMBRE DE ANFITRION de la peticion (https://<clave>.rest.akismet.com): solo [A-Za-z0-9]{8,32}.
 *  b) EmailtemplatesController::updateEmails / resetEmails reescriben las plantillas de correo del sistema: ademas del token, ACL fina (core.admin de
 *     com_engage y core.edit de com_mails), y la comprobacion va ANTES que el token.
 *  c) Meta::deleteAssetData no borra los comentarios de un activo de com_content.
 */
require __DIR__ . '/aserciones.php';
$root = dirname(__DIR__) . '/src';
$lee  = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));

echo "a) Akismet\n";
$ak = $lee("$root/plugins/engage/akismet/src/Extension/Akismet.php");
t_ok(preg_match('/\$apiKey = trim.*?preg_match\(\'\/\^\[A-Za-z0-9\]\{8,32\}\$\/D\', \$apiKey\) !== 1.*?throw new RuntimeException.*?\$apiUrl\s+= "https:\/\/\{\$apiKey\}\.rest\.akismet\.com/s', $ak) === 1, 'la clave se valida con /^[A-Za-z0-9]{8,32}$/D ANTES de construir la URL');
$ok = ['abcdef123456', 'ABCDEF123456', '12345678', str_repeat('a', 32)];
$mal = ['', 'abc', 'abcdefg', str_repeat('a', 33), 'abc.def.evil.test/x', 'evil.test#', 'abc@evil.test', "abcdef123456\n", 'abcdef123456 ', 'abcdef-123456', 'abcdef_123456', 'ab cdef123456', 'abcdef123456.evil', 'abcdef123456/', 'abcdef123456:80', 'abcdéf123456', "abcdef123456\0"];
foreach ($ok as $k) { t_ok(preg_match('/^[A-Za-z0-9]{8,32}$/D', $k) === 1, 'clave valida: ' . substr($k, 0, 14)); }
foreach ($mal as $k) { t_ok(preg_match('/^[A-Za-z0-9]{8,32}$/D', $k) !== 1, 'clave rechazada: ' . json_encode($k)); }

echo "b) Plantillas de correo\n";
$et = $lee("$root/component/backend/src/Controller/EmailtemplatesController.php");
t_ok(preg_match("/private function assertMayChangeTemplates\(\): void.*?authorise\('core\.admin', 'com_engage'\).*?authorise\('core\.edit', 'com_mails'\).*?403/s", $et) === 1, 'ACL: core.admin de com_engage y core.edit de com_mails, 403 si falta');
foreach (['updateEmails', 'resetEmails'] as $m) {
	t_ok(preg_match('/public function ' . $m . '\(\$cachable = false, \$urlparams = \[\]\)\s*\{\s*\$this->assertMayChangeTemplates\(\);\s*\$this->checkToken\(\'get\'\);/', $et) === 1, "$m: primero la ACL, despues el token");
}

echo "c) deleteAssetData\n";
$meta = $lee("$root/component/frontend/src/Helper/Meta.php");
t_ok(preg_match("/function deleteAssetData.*?assetName\(\\\$assetId\).*?str_starts_with\(\\\$assetName, 'com_content\.'\).*?return -1;.*?transactionStart/s", $meta) === 1, 'si el activo existe y es de com_content no se toca nada (-1), antes de abrir la transaccion');
t_fin();
