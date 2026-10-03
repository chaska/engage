<?php
/**
 * Crea datos "reales" de comentarios en el Joomla (a traves de la web, como lo haria un visitante, mas algunas filas
 * en estados que solo el panel produce) para la prueba de actualizacion. Escribe $WORK/datos-reales.json con lo creado.
 * Entorno: WORK, SITE_DIR, IDS_FILE, DB_NAME, BASE_URL.
 */
require __DIR__ . '/Cliente.php';
$WORK = rtrim(getenv('WORK') ?: die("Define WORK\n"), '/');
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8081';
$ids  = json_decode(file_get_contents("$WORK/" . (getenv('IDS_FILE') ?: 'ids.json')), true);
$UP   = trim(file_get_contents("$WORK/userpass.txt"));
$db   = new mysqli('127.0.0.1', getenv('DB_USER') ?: 'joomla_test', trim(file_get_contents("$WORK/dbpass.txt")), getenv('DB_NAME') ?: 'joomla_test');
$db->set_charset('utf8mb4');
@mkdir("$WORK/tmp", 0700, true);

// Permisos: invitados pueden comentar, registrados editar lo propio, editores moderar (lo normal en un sitio con comentarios)
$db->query("UPDATE jos_assets SET rules='" . $db->real_escape_string(json_encode(['core.create' => ['1' => 1], 'core.edit.own' => ['2' => 1], 'core.edit.state' => ['4' => 1], 'core.delete' => ['6' => 1]])) . "' WHERE name='com_engage'");
// Ajustes propios del administrador que NO deben perderse al actualizar
$db->query("UPDATE jos_extensions SET params='" . $db->real_escape_string(json_encode(['default_limit' => '5', 'max_level' => '3', 'default_publish' => '1', 'comments_notify_users' => '1', 'min_length' => '3', 'max_length' => '5000'])) . "' WHERE element='com_engage' AND type='component'");
$db->query("UPDATE jos_extensions SET enabled=1 WHERE element='akismet' AND folder='engage'");   // el administrador activo Akismet (sin clave)
$db->query("UPDATE jos_extensions SET enabled=0 WHERE element='gravatar' AND folder='engage'");  // y desactivo Gravatar (privacidad)

function comentar(Cliente $c, int $art, int $cat, int $asset, array $j): array
{
	$p = $c->get("/index.php?option=com_content&view=article&id=$art&catid=$cat");
	$t = Cliente::token($p['body']);
	return $c->post('/index.php?option=com_engage&task=comment.save', ['returnurl' => base64_encode($c->base . '/'), $t => 1, 'jform' => array_merge(['asset_id' => $asset, 'parent_id' => 0], $j)]);
}
$A = $ids['art_publico']; $C = $ids['cat_publica']; $AS = $ids['art_publico_asset'];
$g = new Cliente($BASE, "$WORK/tmp", 'dr-g'); $r = new Cliente($BASE, "$WORK/tmp", 'dr-r'); $r->login('registrado1', $UP);
comentar($g, $A, $C, $AS, ['name' => 'María Ñandú', 'email' => 'maria@example.test', 'body' => 'Primer comentario con acentos áéíóú ñ y emoji 😀']);
comentar($r, $A, $C, $AS, ['body' => 'Comentario de usuario registrado']);
$raiz = (int) $db->query("SELECT MIN(id) FROM jos_engage_comments")->fetch_row()[0];
comentar($g, $A, $C, $AS, ['name' => 'Pedro', 'email' => 'pedro@example.test', 'body' => 'Respuesta al primero', 'parent_id' => $raiz]);
comentar($r, $A, $C, $AS, ['body' => 'Otra respuesta, nivel 2', 'parent_id' => (int) $db->query("SELECT MAX(id) FROM jos_engage_comments")->fetch_row()[0]]);
comentar($g, $ids['art_otro'], $C, $ids['art_otro_asset'], ['name' => 'Lucía', 'email' => 'lucia@example.test', 'body' => 'Comentario en otro articulo']);
// Estados que produce el panel / Akismet: sin publicar, spam, y una baja de notificaciones
$db->query("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by,modified,modified_by) VALUES ($AS,NULL,'Pendiente de moderacion','Anon','anon@example.test','203.0.113.5','UA-real',0,'2025-06-01 10:00:00',0,NULL,NULL)");
$db->query("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by,modified,modified_by) VALUES ($AS,NULL,'Esto es spam','Spammer','spam@example.test','198.51.100.9','UA-spam',-3,'2025-06-02 10:00:00',0,'2025-06-03 11:00:00',{$ids['user_editor1']})");
$db->query("INSERT INTO jos_engage_unsubscribe (asset_id,email) VALUES ($AS,'maria@example.test')");
$n = (int) $db->query('SELECT COUNT(*) FROM jos_engage_comments')->fetch_row()[0];
file_put_contents("$WORK/datos-reales.json", json_encode(['comentarios' => $n], JSON_PRETTY_PRINT));
echo "comentarios creados: $n\n";
