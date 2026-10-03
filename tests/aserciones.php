<?php
// Utilidades mínimas de aserción compartidas por las pruebas.
$GLOBALS['__t'] = ['ok' => 0, 'ko' => 0];
function t_ok(bool $cond, string $msg): void
{
	$GLOBALS['__t'][$cond ? 'ok' : 'ko']++;
	echo ($cond ? '  OK    ' : '  FALLO ') . $msg . "\n";
}
function t_fin(): void
{
	echo "  -> {$GLOBALS['__t']['ok']} correctas, {$GLOBALS['__t']['ko']} fallidas\n";
	exit($GLOBALS['__t']['ko'] ? 1 : 0);
}
