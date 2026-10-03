<?php
/**
 * Servidor SMTP minimo de pruebas: acepta cualquier mensaje y lo guarda en un fichero .eml del directorio dado.
 * Uso: php smtp-sink.php <directorio> [puerto=2525]
 */
$dir  = $argv[1] ?? die("Uso: php smtp-sink.php <directorio> [puerto]\n");
$port = (int) ($argv[2] ?? 2525);
@mkdir($dir, 0700, true);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr) or die("No se puede abrir el puerto: $errstr\n");
$n = 0;
while ($c = @stream_socket_accept($srv, -1)) {
	fwrite($c, "220 sink ESMTP\r\n");
	$data = false; $buf = '';
	while (($l = fgets($c)) !== false) {
		if ($data) {
			if (rtrim($l, "\r\n") === '.') {
				file_put_contents(sprintf('%s/%05d.eml', $dir, ++$n), $buf);
				$buf = ''; $data = false; fwrite($c, "250 OK\r\n"); continue;
			}
			$buf .= (isset($l[0]) && $l[0] === '.') ? substr($l, 1) : $l; continue;
		}
		$cmd = strtoupper(substr($l, 0, 4));
		if ($cmd === 'EHLO') { fwrite($c, "250-sink\r\n250 8BITMIME\r\n"); }
		elseif ($cmd === 'DATA') { $data = true; fwrite($c, "354 go\r\n"); }
		elseif ($cmd === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
		elseif ($cmd === 'MAIL' || $cmd === 'RCPT') { $buf .= ''; file_put_contents("$dir/envelope.log", trim($l) . "\n", FILE_APPEND); fwrite($c, "250 OK\r\n"); }
		else { fwrite($c, "250 OK\r\n"); }
	}
	fclose($c);
}
