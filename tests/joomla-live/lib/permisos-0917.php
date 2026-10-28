<?php
/**
 * 0.9.17 contra el Joomla REAL: «Permisos» abre DIRECTAMENTE la matriz.
 *  1) desde cada pantalla (panel, comentarios, plantillas, ajustes) el enlace «Permisos» apunta a la matriz y, al seguirlo, da HTTP 200 SIN redirecciones; ningun enlace manda a la pantalla nativa de permisos
 *  2) la entrada «Permisos» de Ajustes y el mosaico del panel van directos; el antiguo &section=permissions redirige a la matriz
 *  3) dentro de la matriz el enlace «Permisos avanzados (Joomla)» (cabecera y leyenda) abre la pantalla nativa (com_config) 200 y esta lleva el aviso de vuelta a la matriz; la nativa sigue guardando
 *  4) sin core.admin: 403 en la matriz y en la nativa de com_engage sin core.admin/core.options; sin enlaces «Permisos» a la matriz
 * Restaura las reglas del activo al acabar.
 */
require __DIR__ . '/revision-082-comun.php';
$uid = ['mgr' => usuario('r3mgr', 6), 'reg' => usuario('r3reg', 2)];
$rulesOrig = (string) one("SELECT rules FROM jos_assets WHERE name='com_engage'");
register_shutdown_function(function () use ($rulesOrig) {
	q("UPDATE jos_assets SET rules='" . esc($rulesOrig) . "' WHERE name='com_engage'"); borraUsuarios();
});
q("UPDATE jos_guidedtours SET autostart=0");
register_shutdown_function(function () { q("UPDATE jos_guidedtours SET autostart=1 WHERE id=12"); });
$base = json_decode($rulesOrig, true) ?: []; $base['core.manage'] = ['6' => 1, '7' => 1]; $base['core.options'] = ['7' => 1];
q("UPDATE jos_assets SET rules='" . esc(json_encode($base, JSON_FORCE_OBJECT)) . "' WHERE name='com_engage'");
@mkdir("$WORK/tmp");
$adm = new Cliente($BASE, "$WORK/tmp", 'p17adm'); $adm->loginAdmin('admintest', 'Aa1!' . trim(file_get_contents("$WORK/adminpass.txt")));
$mgr = new Cliente($BASE, "$WORK/tmp", 'p17mgr'); $mgr->loginAdmin('r3mgr', $PASS);
$A = '/administrator/index.php?option=com_engage';
$pantallas = ['panel' => "$A&view=controlpanel", 'comentarios' => "$A&view=comments", 'plantillas' => "$A&view=emailtemplates", 'ajustes' => "$A&view=settings"];
$enlaces = fn(string $html, string $pat): array => array_values(array_unique(array_map('html_entity_decode', (preg_match_all('#href="([^"]*' . $pat . '[^"]*)"#', $html, $m) ? $m[1] : []))));
$sinNativa = fn(string $html) => !preg_match('#href="[^"]*option=com_config[^"]*component=com_engage[^"]*\#permissions#', html_entity_decode($html));
$n = 0;
foreach ($pantallas as $nom => $ruta) {
	$p = $adm->get($ruta); $mat = $enlaces($p['body'], 'view=permissions');
	$ok = $p['code'] === 200 && count($mat) >= 1 && $sinNativa($p['body']);
	$dest = $mat ? $adm->get($mat[0]) : ['code' => 0, 'loc' => '?', 'body' => ''];
	r('PD-' . ++$n, "$nom: el enlace «Permisos» apunta a la matriz, sin enlaces a la nativa de permisos; al seguirlo: HTTP 200 sin redireccion", $ok && $dest['code'] === 200 && $dest['loc'] === '' && str_contains($dest['body'], 'eg-perm-table'), "HTTP {$p['code']} enlaces=" . count($mat) . " destino={$dest['code']} loc={$dest['loc']}");
}
// 2) ajustes y panel
$aj = $adm->get("$A&view=settings"); $mn = $enlaces($aj['body'], 'view=permissions');
r('PD-5', 'Ajustes: la entrada «Permisos» del menu es un enlace directo (ni boton de pestana ni pantalla intermedia con dos botones)', !str_contains($aj['body'], 'data-eg-tab="permissions"') && !str_contains($aj['body'], 'eg-panel-permissions') && count($mn) >= 1 && preg_match('#<a class="eg-navitem" href="[^"]*view=permissions#', $aj['body']) === 1);
$old = $adm->get("$A&view=settings&section=permissions");
r('PD-6', 'el antiguo &section=permissions redirige a la matriz (una sola redireccion) y esta responde 200', in_array($old['code'], [302, 303, 301], true) && str_contains($old['loc'], 'view=permissions') && ($adm->get($old['loc'])['code'] === 200), "HTTP {$old['code']} loc={$old['loc']}");
$pn = $adm->get("$A&view=controlpanel");
r('PD-7', 'el mosaico «Permisos» del panel apunta a la matriz', preg_match('#<a class="eg-tile" href="[^"]*view=permissions#', $pn['body']) === 1);
// 3) avanzado
$mt = $adm->get("$A&view=permissions"); $av = $enlaces($mt['body'], 'option=com_config');
r('PD-8', 'la matriz lleva el enlace «Permisos avanzados (Joomla)» en la cabecera y en la leyenda (2 enlaces iguales)', substr_count(html_entity_decode($mt['body']), 'option=com_config&view=component&component=com_engage') >= 2 && count($av) === 1 && str_contains($av[0], '#permissions'), json_encode($av));
$url = $av[0] ?? ''; $fijo = preg_match('#^(/[^?\#]*)?/?index\.php\?option=com_config&view=component&component=com_engage&return=[A-Za-z0-9+/=%]+\#permissions$#', $url) === 1;
r('PD-9', 'la direccion es relativa (sin Host ni datos de la peticion): solo parametros fijos y el retorno a la matriz', $fijo && !str_contains($url, 'http') && base64_decode(urldecode(preg_match('/return=([^&#]+)/', $url, $m) ? $m[1] : '')) === 'index.php?option=com_engage&view=permissions', $url);
$nat = $adm->get(strtok($url, '#'));
r('PD-10', 'la pantalla nativa de Joomla de com_engage abre (200), con la pestana de permisos y el campo de reglas', $nat['code'] === 200 && str_contains($nat['body'], 'name="jform[rules]') && str_contains($nat['body'], 'permissions'), "HTTP {$nat['code']}");
r('PD-11', 'la nativa lleva el aviso con el enlace de vuelta a la matriz', preg_match('#<a href="index\.php\?option=com_engage&amp;view=permissions">#', $nat['body']) === 1 || str_contains(html_entity_decode($nat['body']), 'href="index.php?option=com_engage&view=permissions"'));
$h = html_entity_decode($adm->get("$A&view=permissions")['body']);
$host = $adm->get(strtok($url, '#'), ['Host: evil.example']);
r('PD-12', 'un Host hostil no aparece en el enlace avanzado ni en el aviso', !str_contains($host['body'], 'evil.example') && !str_contains($h, 'evil.example'));
// 4) sin core.admin
$pm = $mgr->get("$A&view=permissions");
r('PD-13', 'Manager (core.manage, sin core.admin): la matriz = 403', $pm['code'] === 403 && !str_contains($pm['body'], 'eg-perm-table'), "HTTP {$pm['code']}");
$mal = [];
foreach ($pantallas as $nom => $ruta) { $x = $mgr->get($ruta); if ($x['code'] === 200 && (count($enlaces($x['body'], 'view=permissions')) > 0 && $nom !== 'ajustes')) { $mal[] = $nom; } }
r('PD-14', 'sin core.admin ninguna pantalla muestra el enlace «Permisos» a la matriz', !$mal, implode(',', $mal));
$nm = $mgr->get(strtok($url, '#'));
r('PD-15', 'la pantalla nativa de com_engage sin core.admin ni core.options: no abre (403 o redireccion), nunca muestra las reglas', $nm['code'] !== 200 || !str_contains($nm['body'], 'name="jform[rules]'), "HTTP {$nm['code']}");
$g = new Cliente($BASE, "$WORK/tmp", 'p17gst'); $gm = $g->get("$A&view=permissions");
r('PD-16', 'sin sesion: la matriz no se muestra (inicio de sesion o 403)', !str_contains($gm['body'], 'eg-perm-table'), "HTTP {$gm['code']}");
