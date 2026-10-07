<?php
/**
 * 0.7.1: crea (idempotente) en el Joomla de pruebas una categoria PADRE y una HIJA (con los modelos reales de Joomla) y un articulo publico en la
 * hija, para el ataque a categorias de reacciones-071-http.php (categoria Special, sin publicar, en la papelera, archivada, y padre sin publicar).
 * Uso: JOOMLA_SITE=/ruta/al/joomla php siembra-categorias-071.php   (imprime JSON con los ID creados y lo guarda en $WORK/ids-071.json)
 */
define('_JEXEC', 1);
// Algunas extensiones construyen una aplicacion web aunque estemos en CLI: que sepa el host.
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? (parse_url(getenv('BASE_URL') ?: 'http://127.0.0.1:8080', PHP_URL_HOST) . ':' . (parse_url(getenv('BASE_URL') ?: 'http://127.0.0.1:8080', PHP_URL_PORT) ?: 80));
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
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


$catId = function (string $titulo) use ($db) { return (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__categories')->where('extension=' . $db->quote('com_content') . ' AND title=' . $db->quote($titulo)))->loadResult(); };
$out['cat_padre'] = $catId('Cat atk padre') ?: guardar($cat->createModel('Category', 'Administrator', ['ignore_request' => true]), ['id' => 0, 'title' => 'Cat atk padre', 'extension' => 'com_content', 'published' => 1, 'access' => 1, 'parent_id' => 1, 'language' => '*'], 'categoria padre');
$out['cat_hija']  = $catId('Cat atk hija') ?: guardar($cat->createModel('Category', 'Administrator', ['ignore_request' => true]), ['id' => 0, 'title' => 'Cat atk hija', 'extension' => 'com_content', 'published' => 1, 'access' => 1, 'parent_id' => $out['cat_padre'], 'language' => '*'], 'categoria hija');
$ya = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__content')->where('alias=' . $db->quote('a-atk-hija')))->loadResult();
if (!$ya) {
	$ya = guardar($mvc->createModel('Article', 'Administrator', ['ignore_request' => true]), ['id' => 0, 'title' => 'Articulo atk hija', 'alias' => 'a-atk-hija', 'catid' => $out['cat_hija'], 'state' => 1, 'access' => 1, 'language' => '*', 'introtext' => '<p>Texto de prueba de categorias</p>', 'created_by' => (int) $su->id], 'articulo');
}
$out['art'] = $ya;
$out['art_asset'] = (int) $db->setQuery($db->getQuery(true)->select('asset_id')->from('#__content')->where('id=' . $ya))->loadResult();
$js = json_encode($out, JSON_PRETTY_PRINT);
file_put_contents((getenv('WORK') ?: sys_get_temp_dir()) . '/ids-071.json', $js);
echo $js, "\n";
