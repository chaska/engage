<?php
/**
 * 0.6.10: el comando `engage:cleanspam` no se registraba en Joomla 6.1.4 (hallado en Joomla real):
 * `addArgument('max-time', InputOption::VALUE_OPTIONAL, ...)` pasaba una constante de OPCIÓN (4) como modo de
 * ARGUMENTO, donde 4 es IS_ARRAY, y Symfony lanza "A default value for an array argument must be an array".
 * El plugin console/engage lo silenciaba y el comando desaparecía. Comprobación estática del fuente.
 */
require __DIR__ . '/aserciones.php';

$f   = dirname(__DIR__) . '/src/component/backend/src/CliCommand/CleanSpam.php';
$src = file_get_contents($f);

t_ok($src !== false, 'CleanSpam.php legible');
t_ok(!preg_match('/addArgument\([^;]*InputOption::/', $src), 'ningún addArgument usa constantes de InputOption');
t_ok((bool) preg_match("/addArgument\('max-time', InputArgument::OPTIONAL/", $src), "max-time usa InputArgument::OPTIONAL");
t_ok((bool) preg_match("/addArgument\('max-days', InputArgument::OPTIONAL/", $src), "max-days usa InputArgument::OPTIONAL");
t_ok(strpos($src, 'use Symfony\Component\Console\Input\InputArgument;') !== false, 'importa InputArgument');
t_ok(strpos($src, 'max((int) $maxTime, 1)') !== false && strpos($src, 'max((int) $maxDays, 0)') !== false, 'los argumentos se fuerzan a entero');

// Si hay symfony/console real a mano (p. ej. un Joomla en el equipo), valida contra él.
foreach (['SYMFONY_CONSOLE_DIR', 'JOOMLA_SITE'] as $env) {
	$d = getenv($env);
	if ($d && is_file($d . '/libraries/vendor/autoload.php')) {
		require $d . '/libraries/vendor/autoload.php';
		try {
			new \Symfony\Component\Console\Input\InputArgument('max-time', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'x', 10);
			t_ok(true, 'Symfony real acepta InputArgument::OPTIONAL con valor por defecto escalar');
		} catch (\Throwable $e) { t_ok(false, 'Symfony real: ' . $e->getMessage()); }
		try {
			new \Symfony\Component\Console\Input\InputArgument('max-time', \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'x', 10);
			t_ok(false, 'el código antiguo debería fallar en Symfony real');
		} catch (\Throwable $e) { t_ok(true, 'confirmado: el código antiguo falla en Symfony real (' . $e->getMessage() . ')'); }
		break;
	}
}
t_fin();
