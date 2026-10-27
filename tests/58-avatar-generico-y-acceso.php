<?php
/**
 * 0.9.11: aviso de panel por plugins del grupo engage sin acceso Publico y opcion «Avatar generico en los comentarios sin foto». Estatica, sin Joomla.
 */
require __DIR__ . '/aserciones.php';
require __DIR__ . '/stubs/joomla.php';
$c   = dirname(__DIR__) . '/src/component';
$lee = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
foreach (['PanelHealth', 'ListOrdering', 'CommentTools', 'AvatarField'] as $k) { require_once "$c/backend/src/Helper/$k.php"; }
use Akeeba\Component\Engage\Administrator\Helper\PanelHealth as H;
use Akeeba\Component\Engage\Administrator\Helper\CommentTools as T;

echo "A) Semaforo de acceso de los plugins de fotos\n";
$item = function (array $f) { foreach (H::evaluate($f)['items'] as $i) { if ($i['id'] === 'avataraccess') { return $i; } } return null; };
$it = $item([]);
t_ok($it && $it['level'] === 'ok', 'sin datos: verde');
$it = $item(['engage_plugin_access' => []]);
t_ok($it && $it['level'] === 'ok' && $it['args']['state'] === 'ok', 'ninguno fuera de Publico: verde');
$mifoto = ['id' => 77, 'element' => 'mifoto', 'name' => 'Engage - MiFoto', 'access' => 2, 'level' => 'Registered'];
$it = $item(['engage_plugin_access' => [$mifoto], 'can_edit_plugins' => true]);
t_ok($it && $it['level'] === 'warn' && str_contains($it['args']['a'], 'Engage - MiFoto') && str_contains($it['args']['a'], 'Registered'), 'plugin con nivel Registered: AMBAR con plugin y nivel');
t_ok(($it['fix']['kind'] ?? '') === 'plugin' && ($it['fix']['id'] ?? 0) === 77, 'con permiso: enlace al plugin');
$it = $item(['engage_plugin_access' => [$mifoto], 'can_edit_plugins' => false]);
t_ok($it && $it['level'] === 'warn' && $it['fix'] === null, 'sin permiso para editar plugins: aviso sin enlace');
$it = $item(['engage_plugin_access' => [['id' => 5, 'element' => 'email', 'name' => 'x', 'access' => 3, 'level' => 'Special'], ['id' => 6, 'element' => 'akismet', 'name' => 'y', 'access' => 2, 'level' => 'Registered']]]);
t_ok($it && $it['level'] === 'ok', 'email y akismet fuera de Publico no avisan');
$it = $item(['engage_plugin_access' => [['id' => 8, 'element' => 'x', 'name' => '<script>alert(1)</script>', 'access' => 9, 'level' => '']]]);
t_ok($it && $it['level'] === 'warn' && !str_contains($it['args']['a'], '<') && str_contains($it['args']['a'], '#9'), 'nombre hostil limpio y nivel desconocido como #id');
$many = []; for ($i = 1; $i <= 9; $i++) { $many[] = ['id' => $i, 'element' => "p$i", 'name' => "P$i", 'access' => 2, 'level' => 'Registered']; }
t_ok(substr_count($item(['engage_plugin_access' => $many])['args']['a'], 'Registered') === 5, 'se listan como mucho 5');
$pd = $lee("$c/backend/src/Helper/PanelData.php");
t_ok(str_contains($pd, "':type'") && str_contains($pd, "'e.access') . ' NOT IN (1, 5)'") && str_contains($pd, '->bind(\':grp\'') && str_contains($pd, 'setQuery($q, 0, 50)'), 'consulta acotada y con parametros ligados');
foreach (['es-ES', 'en-GB'] as $l) {
	$ini = parse_ini_string($lee("$c/backend/language/$l/com_engage.ini"), false, INI_SCANNER_RAW);
	t_ok(isset($ini['COM_ENGAGE_PANEL_H_AVATARACCESS_TITLE'], $ini['COM_ENGAGE_PANEL_H_AVATARACCESS_OK'], $ini['COM_ENGAGE_PANEL_H_AVATARACCESS_WARN']) && str_contains($ini['COM_ENGAGE_PANEL_H_AVATARACCESS_WARN'], '%1$s'), "$l: cadenas del semaforo");
	$sys = parse_ini_string($lee("$c/backend/language/$l/com_engage.sys.ini"), false, INI_SCANNER_RAW);
	t_ok(isset($sys['COM_ENGAGE_CONFIG_GENERIC_AVATAR_LABEL'], $sys['COM_ENGAGE_CONFIG_GENERIC_AVATAR_DESC'], $sys['COM_ENGAGE_CONFIG_GENERIC_AVATAR_HIDE'], $sys['COM_ENGAGE_CONFIG_GENERIC_AVATAR_SHOW']), "$l: cadenas de la opcion");
}

echo "B) Opcion del avatar generico\n";
t_ok(T::options([])['generic_avatar'] === true && T::options(['generic_avatar' => '1'])['generic_avatar'] === true && T::options(['generic_avatar' => '0'])['generic_avatar'] === false && T::options(['generic_avatar' => 0])['generic_avatar'] === false, 'por defecto Mostrar; 0 es No mostrar');
foreach (['x', '2', '-1', '', null, [], '1; DROP', 'false', true] as $v) { t_ok(T::options(['generic_avatar' => $v])['generic_avatar'] === true, 'valor no valido ' . json_encode($v) . ' => Mostrar (cierra hacia el comportamiento actual)'); }
$cfg = $lee("$c/backend/config.xml");
t_ok(preg_match('/name="generic_avatar"\s+type="radio"[^>]*default="1"/s', $cfg) === 1 && !preg_match('/name="generic_avatar"[^>]*filter=/', $cfg), 'config.xml: radio cerrado con valor por defecto 1');
t_ok(str_contains($lee("$c/backend/src/Helper/SettingsLayout.php"), "[\$c, 'generic_avatar']"), 'panel moderno incluye la opcion');
$dl = $lee("$c/frontend/tmpl/comments/default_list.php");
t_ok(str_contains($dl, "empty(\$this->tools['generic_avatar'])") && str_contains($dl, "\$avatarDeferred === ''") && str_contains($dl, 'AvatarField::isGeneric'), 'plantilla: solo se oculta el generico sin referencia diferida de Gravatar');
t_ok(!str_contains($lee("$c/backend/src/Helper/SettingsSchema.php"), 'generic_avatar'), 'no esta entre las opciones solo para superadministracion');
