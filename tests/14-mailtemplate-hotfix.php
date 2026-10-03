<?php
/**
 * 0.6.7: MailTemplateHotFix solo actúa en Joomla 5.2.0 y 5.2.1 (donde MailTemplate convertía las URL relativas antes
 * de aplicar la plantilla) y solo si el código del núcleo está en el estado defectuoso conocido. Se prueba con copias
 * SIN MODIFICAR de MailTemplate.php de 5.2.1, 5.2.2, 5.4-dev y 6.1-dev (tests/joomla-real/mail-template).
 */
require __DIR__ . '/aserciones.php';
define('_JEXEC', 1);
eval('namespace Joomla\CMS\Mail { class Mail {} class MailTemplate {} }');
require dirname(__DIR__) . '/src/component/backend/src/Helper/MailTemplateHotFix.php';
use Akeeba\Component\Engage\Administrator\Helper\MailTemplateHotFix as H;
$dir = __DIR__ . '/joomla-real/mail-template';

echo "A) Versiones afectadas\n";
foreach (['5.1.9' => false, '5.2.0' => true, '5.2.1' => true, '5.2.2' => false, '5.2.2-dev' => true /* por versión entra; el estado del código (B) decide */, '5.2.6' => false, '5.4.9' => false, '6.0.2' => false, '6.1.4' => false, '7.0.0' => false, '4.4.0' => false] as $v => $esp) {
	t_ok(H::isAffectedVersion($v) === $esp, "isAffectedVersion($v) = " . ($esp ? 'sí' : 'no'));
}

echo "B) Estado del código del núcleo\n";
$tmp = sys_get_temp_dir() . '/engage_mt_' . bin2hex(random_bytes(4)) . '.php';
foreach (['5.2.1' => true, '5.2.2' => false, '5.4-dev' => false, '6.1-dev' => false] as $v => $parchea) {
	$src = file_get_contents("$dir/$v-MailTemplate.php");
	$out = H::buildPatchedSource($src);
	t_ok(($out !== null) === $parchea, "$v: " . ($parchea ? 'se parchea (código defectuoso)' : 'NO se toca (ya corregido)'));
	if ($out !== null) {
		file_put_contents($tmp, $out);
		exec(PHP_BINARY . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
		t_ok($rc === 0, "$v: el código parcheado es PHP válido");
		t_ok(str_contains($out, 'class MailTemplateAkeebaWorkaround extends \Joomla\CMS\Mail\MailTemplate'), "$v: define la subclase");
		$pc = strpos($out, 'convertRelativeToAbsoluteUrls'); $pl = strpos($out, 'new FileLayout(');
		t_ok($pc > $pl && substr_count($out, 'convertRelativeToAbsoluteUrls') === 1, "$v: la conversión queda DESPUÉS de aplicar la plantilla, una sola vez");
	}
}
@unlink($tmp);
foreach ([
	'sin conversión' => str_replace('$htmlBody = MailHelper::convertRelativeToAbsoluteUrls($htmlBody);', '', file_get_contents("$dir/5.2.1-MailTemplate.php")),
	'dos clases' => file_get_contents("$dir/5.2.1-MailTemplate.php") . "\nclass MailTemplateOtra {}\n",
	'vacío' => '',
	'basura' => '<?php echo 1;',
] as $n => $src) {
	t_ok(H::buildPatchedSource($src) === null, "código inesperado ($n): no se parchea");
}
t_fin();
