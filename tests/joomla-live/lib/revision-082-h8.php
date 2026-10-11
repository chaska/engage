<?php
/**
 * 0.8.3, H8 contra el Joomla REAL: las tareas updateEmails / resetEmails del panel reescriben las plantillas de correo del sistema. Ademas del token exigen
 * core.admin de com_engage y core.edit de com_mails: un Administrator (core.manage, sin core.admin) recibe 403 aunque el token sea valido y NO cambia nada;
 * el Super User sigue pudiendo. Comprueba el contenido de #__mail_templates antes y despues.
 */
require __DIR__ . '/revision-082-comun.php';
usuario('r3adm', 7);
register_shutdown_function(function () { borraUsuarios(); });
@mkdir("$WORK/tmp");
$AP = 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt"));
$huella = fn() => md5(json_encode(q("SELECT template_id, subject, body, htmlbody, params FROM jos_mail_templates WHERE template_id LIKE 'com_engage.%' ORDER BY template_id")->fetch_all()));
$adm = new Cliente($BASE, "$WORK/tmp", 'r3h8adm'); $okA = $adm->loginAdmin('r3adm', $PASS);
$sup = new Cliente($BASE, "$WORK/tmp", 'r3h8sup'); $okS = $sup->loginAdmin('admintest', $AP);
r('H8-00', 'Sesiones de panel abiertas (Administrator y Super User)', $okA && $okS);
// El token de los botones va en la propia URL (task=resetEmails&amp;<token>=1)
$tokDe = fn(Cliente $c) => preg_match('~task=resetEmails&amp;([0-9a-f]{32})=1~', $c->get('/administrator/index.php?option=com_engage&view=Emailtemplates')['body'], $mm) ? $mm[1] : '';
$tokA = $tokDe($adm); $tokS = $tokDe($sup);
r('H8-00b', 'Los dos obtienen un token CSRF valido de la pantalla de plantillas', preg_match('/^[0-9a-f]{32}$/', $tokA) && preg_match('/^[0-9a-f]{32}$/', $tokS));
// Se manipula la plantilla por omision: resetEmails la reconstruye (el asunto vuelve a su clave de idioma); es lo que se vigila
$orig = one("SELECT subject FROM jos_mail_templates WHERE template_id='com_engage.notify' AND language=''");
q("UPDATE jos_mail_templates SET subject='MANIPULADO-R3' WHERE template_id='com_engage.notify' AND language=''");
register_shutdown_function(function () use ($orig) { q("UPDATE jos_mail_templates SET subject='" . esc((string) $orig) . "' WHERE template_id='com_engage.notify' AND language='' AND subject='MANIPULADO-R3'"); });
$antes = $huella();
foreach (['updateEmails', 'resetEmails'] as $t) {
	$x = $adm->get("/administrator/index.php?option=com_engage&view=Emailtemplates&task=$t&$tokA=1");
	r("H8-01-$t", "Administrator (sin core.admin) con token VALIDO, URL del boton (view=Emailtemplates&task=$t): 403 y las plantillas no cambian", $x['code'] === 403 && $huella() === $antes, "HTTP {$x['code']}");
	$x = $adm->get("/administrator/index.php?option=com_engage&task=Emailtemplates.$t&$tokA=1");
	r("H8-02-$t", "Lo mismo con task=Emailtemplates.$t", $x['code'] === 403 && $huella() === $antes, "HTTP {$x['code']}");
}
$x = $sup->get("/administrator/index.php?option=com_engage&view=Emailtemplates&task=resetEmails");
r('H8-03', 'Super User sin token: no cambia nada', $huella() === $antes, "HTTP {$x['code']}");
$x = $sup->get("/administrator/index.php?option=com_engage&view=Emailtemplates&task=resetEmails&$tokS=1");
r('H8-04', 'Control positivo: el Super User con token restablece las plantillas (la plantilla manipulada se reconstruye)', $x['code'] === 303 && (int) one("SELECT COUNT(*) FROM jos_mail_templates WHERE template_id='com_engage.notify' AND subject='MANIPULADO-R3'") === 0, "HTTP {$x['code']}");
$g = new Cliente($BASE, "$WORK/tmp", 'r3h8g'); $x = $g->get("/administrator/index.php?option=com_engage&task=updateEmails&$tokS=1");
r('H8-05', 'Un invitado no llega a la tarea (pide iniciar sesion, sin cambios)', $x['code'] !== 500 && str_contains($x['body'], 'login') || $x['code'] === 303 || $x['code'] === 403);
resumen('h8');
