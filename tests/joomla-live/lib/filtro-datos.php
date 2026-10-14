<?php
/**
 * 0.9.2: datos del flujo del filtro «Tipo de elemento» / «Elemento» de la lista de comentarios del administrador (lib/filtro-navegador.js):
 * ~200 comentarios de un «libro de visitas» (un articulo), 4 de un «ranking de un juego» (com_juego.ranking.N) y 5 del componente de mapas
 * (com_mapaviajes.trip.N). `php filtro-datos.php crear|borrar`. Requiere WORK, DB_NAME, DB_USER, SITE_DIR, IDS_FILE.
 */
require __DIR__ . '/revision-082-comun.php';
$cmd = $argv[1] ?? '';
q("DELETE FROM jos_engage_comments WHERE name LIKE 'F9N-%'");
q("DELETE FROM jos_assets WHERE id BETWEEN 930001 AND 930020");
if ($cmd !== 'crear') { echo "ok borrar\n"; exit(0); }
$ins = function (int $asset, int $en, string $name, string $body = 'texto') { q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($asset,NULL,'<p>F9B $body</p>','$name','f9@example.invalid','203.0.113.9','t',$en,DATE_SUB(NOW(), INTERVAL " . random_int(1, 5000) . " MINUTE),0)"); };
$mk = function (int $id, string $name) { q("INSERT INTO jos_assets (id, parent_id, lft, rgt, level, name, title, rules) VALUES ($id, 1, " . (2 * $id) . ", " . (2 * $id + 1) . ", 1, '$name', 'Activo $id', '{}')"); };
for ($i = 1; $i <= 4; $i++) { $mk(930000 + $i, "com_juego.ranking.$i"); }
for ($i = 1; $i <= 5; $i++) { $mk(930010 + $i, "com_mapaviajes.trip.$i"); }
for ($i = 1; $i <= 200; $i++) { $ins($ASSET_PUB, 1, sprintf('F9N-LIBRO-%03d', $i), "libro $i"); }
for ($i = 1; $i <= 4; $i++) { $ins(930000 + $i, 1, "F9N-RANK-$i", "ranking $i"); }
for ($i = 1; $i <= 5; $i++) { $ins(930010 + $i, $i === 5 ? 0 : 1, "F9N-MAPA-$i", "mapa $i"); }
echo "ok crear\n";
