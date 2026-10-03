<?php
/**
 * 0.6.11: la plantilla de edicion de comentarios (frontend/tmpl/comment/edit.php) recibia $returnUrl = null cuando la
 * vista se muestra sin returnurl (PHP 8.1+: "Deprecated: base64_encode(): Passing null"), y pintaba el enlace de
 * cancelar sin escapar. Hallado en Joomla 6.1.4 real con error_reporting=maximum. Comprobacion estatica del fuente.
 */
require __DIR__ . '/aserciones.php';

$src = file_get_contents(dirname(__DIR__) . '/src/component/frontend/tmpl/comment/edit.php');
t_ok($src !== false, 'edit.php legible');
t_ok(strpos($src, 'base64_encode($this->returnUrl)') === false, 'ya no se pasa $this->returnUrl sin convertir a base64_encode()');
t_ok(strpos($src, 'base64_encode((string) $this->returnUrl)') !== false, 'base64_encode recibe una cadena (null -> "")');
t_ok(strpos($src, '<a href="<?= $this->returnUrl ?>"') === false, 'el enlace de cancelar no imprime returnUrl sin escapar');
t_ok(strpos($src, "htmlspecialchars((string) \$this->returnUrl, ENT_QUOTES, 'UTF-8')") !== false, 'el enlace de cancelar escapa returnUrl');

// Comportamiento: con null no hay aviso y las comillas del valor no rompen el atributo.
set_error_handler(function ($n, $m) { throw new ErrorException($m, 0, $n); });
try {
	$rutas = [null, '', '/x"><img src=x onerror=alert(1)>'];
	foreach ($rutas as $v) {
		$b64  = base64_encode((string) $v);
		$href = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
		t_ok(strpos($href, '"') === false && strpos($href, '<') === false && base64_decode($b64) === (string) $v, 'valor ' . var_export($v, true) . ' sin aviso y sin romper el atributo');
	}
} catch (ErrorException $e) { t_ok(false, 'aviso PHP: ' . $e->getMessage()); }
restore_error_handler();
t_fin();
