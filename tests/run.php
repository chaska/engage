#!/usr/bin/env php
<?php
/**
 * Ejecuta todas las pruebas tests/NN-*.php. Cada una imprime sus líneas y termina con código 0 si pasa.
 * Sin Joomla real: se usan stubs mínimos (tests/stubs) y, donde existe, la librería real del framework.
 * Uso: php tests/run.php
 */
$fallos = 0;
foreach (glob(__DIR__ . '/[0-9][0-9]-*.php') as $t) {
	echo "=== " . basename($t) . "\n";
	passthru(PHP_BINARY . ' ' . escapeshellarg($t), $rc);
	if ($rc !== 0) { $fallos++; echo "--> FALLO (código $rc)\n"; }
}
echo "\n" . ($fallos ? "$fallos prueba(s) con fallo\n" : "Todas las pruebas pasan\n");
exit($fallos ? 1 : 0);
