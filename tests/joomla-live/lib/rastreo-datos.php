<?php
/** 0.9.5: comentarios de usuarios REGISTRADOS (created_by) para la prueba de los microdatos: `php rastreo-datos.php crear|borrar`. Van los primeros de la lista (fecha antigua). */
require __DIR__ . '/revision-082-comun.php';
q("DELETE FROM jos_engage_comments WHERE name LIKE 'F9R-%' OR body LIKE '%F9R-BODY%'");
$uid = usuario('r3reg', 2);
q("DELETE FROM jos_engage_comments WHERE created_by=$uid AND body LIKE '%F9R-BODY%'");
if (($argv[1] ?? '') !== 'crear') { echo "ok borrar\n"; exit(0); }
for ($i = 1; $i <= 3; $i++) {
	q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>F9R-BODY $i registrado</p>',NULL,NULL,'127.0.0.1','t',1,'2000-01-0$i 10:00:00',$uid)");
}
echo "ok crear\n";
