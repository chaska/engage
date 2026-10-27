<?php
/** 0.9.10: comentarios «importados» sembrados para la prueba (created_by NULL o 0, correo vacio, ip «0» y otras basuras) + uno normal de invitado. `php importados-datos.php crear|borrar` */
require __DIR__ . '/revision-082-comun.php';
q("DELETE FROM jos_engage_comments WHERE name LIKE 'F9I-%'");
if (($argv[1] ?? '') !== 'crear') { echo "ok borrar\n"; exit(0); }
$filas = [['F9I-IMP-1', 'NULL', "''", "'0'"], ['F9I-IMP-2', '0', "''", "'0.0.0.0'"], ['F9I-IMP-3', 'NULL', 'NULL', "''"], ['F9I-IMP-4', '0', "''", "'basura'"], ['F9I-NORMAL', 'NULL', "'normal@example.invalid'", "'203.0.113.9'"]];
foreach ($filas as $i => [$n, $by, $mail, $ip]) {
	$d = sprintf('2010-03-0%d 10:00:00', $i + 1);
	q("INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES ($ASSET_PUB,NULL,'<p>F9I texto $n</p>','$n',$mail,$ip,'',1,'$d',$by)");
}
echo "ok crear\n";
