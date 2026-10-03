<?php
/** Router para `php -S`: sirve estaticos tal cual y manda el resto al index.php del sitio o de /administrator. */
$root = $_SERVER['DOCUMENT_ROOT'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path !== '/' && is_file($root . $path) && substr($path, -4) !== '.php') {
	return false;
}
if (preg_match('#^(/[^?]*\.php)(/.*)?$#', $path, $m) && is_file($root . $m[1])) {
	$script = $m[1];
	$info   = $m[2] ?? '';
} elseif (strpos($path, '/administrator') === 0) {
	$script = '/administrator/index.php';
	$info   = substr($path, strlen('/administrator'));
} elseif (strpos($path, '/api') === 0) {
	$script = '/api/index.php';
	$info   = substr($path, 4);
} else {
	$script = '/index.php';
	$info   = $path === '/' ? '' : $path;
}
$_SERVER['SCRIPT_NAME']     = $script;
$_SERVER['PHP_SELF']        = $script . $info;
$_SERVER['SCRIPT_FILENAME'] = $root . $script;
$_SERVER['PATH_INFO']       = $info;
chdir(dirname($root . $script));
require $root . $script;
