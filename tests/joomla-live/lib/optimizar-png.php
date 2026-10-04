<?php
/** Reduce un PNG a paleta (GD, con tramado) probando 128, 96, 64 y 48 colores hasta quedar por debajo de 190 KB. Uso: php optimizar-png.php entrada.png salida.png [maxKB=190] */
[$_, $in, $out, $kb] = $argv + [null, null, null, 190];
foreach ([128, 96, 64, 48] as $n) {
	$im = imagecreatefrompng($in);
	imagetruecolortopalette($im, true, $n);
	imagepng($im, $out, 9);
	clearstatcache();
	if (filesize($out) < (int) $kb * 1024) { break; }
}
echo basename($out) . ": $n colores, " . filesize($out) . " bytes\n";
