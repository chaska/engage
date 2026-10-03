<?php
/**
 * Crea en el Joomla de pruebas: categorias, articulos y usuarios, usando los modelos reales de Joomla.
 * Uso: JOOMLA_SITE=/ruta/al/joomla php seed.php   (imprime JSON con los ID creados)
 * No contiene contrasenas: las recibe por entorno (TEST_USER_PASS).
 */
define('_JEXEC', 1);
$site = getenv('JOOMLA_SITE') ?: die("Define JOOMLA_SITE\n");
define('JPATH_BASE', $site);
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_content'); define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_COMPONENT); define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_content');
use Joomla\CMS\Factory;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\User\UserFactoryInterface;

$container = Factory::getContainer();
$container->alias('session.cli', 'session.web.site')->alias('session', 'session.web.site')
	->alias('JSession', 'session.web.site')->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
	->alias(\Joomla\Session\Session::class, 'session.web.site')->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(ConsoleApplication::class);
Factory::$application = $app;
if (method_exists($app, "createExtensionNamespaceMap")) { $app->createExtensionNamespaceMap(); }
$app->getLanguage()->load('lib_joomla', JPATH_ADMINISTRATOR);

$su = $container->get(UserFactoryInterface::class)->loadUserByUsername('admintest');
$app->loadIdentity($su);
$mvc = $app->bootComponent('com_content')->getMVCFactory();
$cat = $app->bootComponent('com_categories')->getMVCFactory();
$db  = $container->get(\Joomla\Database\DatabaseInterface::class);
$out = [];

function guardar($model, array $data, string $que)
{
	if (!$model->save($data)) {
		fwrite(STDERR, "ERROR creando $que: " . $model->getError() . "\n");
		exit(1);
	}
	return (int) $model->getState($model->getName() . '.id');
}

// Categorias: publica y restringida (nivel Registered = 2)
foreach (['publica' => 1, 'restringida' => 2] as $nom => $acc) {
	$m = $cat->createModel('Category', 'Administrator', ['ignore_request' => true]);
	$out['cat_' . $nom] = guardar($m, ['id' => 0, 'title' => 'Cat ' . $nom, 'extension' => 'com_content', 'published' => 1, 'access' => $acc, 'parent_id' => 1, 'language' => '*'], "categoria $nom");
}

$arts = [
	'art_publico'     => ['Articulo publico', 'cat_publica', 1, 1],
	'art_restringido' => ['Articulo restringido', 'cat_restringida', 2, 1],
	'art_despublicado'=> ['Articulo despublicado', 'cat_publica', 1, 0],
	'art_otro'        => ['Otro articulo publico', 'cat_publica', 1, 1],
];
foreach ($arts as $k => [$t, $c, $acc, $pub]) {
	$m = $mvc->createModel('Article', 'Administrator', ['ignore_request' => true]);
	$out[$k] = guardar($m, ['id' => 0, 'title' => $t, 'alias' => 'a-' . $k, 'catid' => $out[$c], 'state' => $pub, 'access' => $acc, 'language' => '*', 'introtext' => '<p>Texto de prueba ' . $k . '</p>', 'fulltext' => '', 'featured' => 1], "articulo $k");
	$out[$k . '_asset'] = (int) $db->setQuery($db->getQuery(true)->select('asset_id')->from('#__content')->where('id=' . $out[$k]))->loadResult();
}

// Usuarios registrados (via CLI de Joomla desde el script de montaje); aqui solo se leen sus ID.
foreach (['registrado1', 'registrado2', 'editor1'] as $u) {
	$out['user_' . $u] = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__users')->where('username=' . $db->quote($u)))->loadResult();
}
echo json_encode($out, JSON_PRETTY_PRINT), "\n";
