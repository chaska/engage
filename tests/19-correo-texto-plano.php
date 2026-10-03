<?php
/**
 * 0.6.13: los enlaces firmados (Route::_ con xhtml=true) llevan "&amp;" entre parametros. En el correo de texto plano
 * (el formato por defecto de Joomla) el enlace de baja resultaba inutilizable. Hallado en Joomla 6.1.4 real.
 * Comprueba TemplateEmails::plainTextData() (real) y que sendMail la pasa a Joomla como datos de texto plano.
 */
if (!defined('_JEXEC')) { define('_JEXEC', 1); }
require __DIR__ . '/aserciones.php';
require dirname(__DIR__) . '/src/component/backend/src/Helper/TemplateEmails.php';

use Akeeba\Component\Engage\Administrator\Helper\TemplateEmails;

$url  = 'http://x.test/index.php/component/engage?task=comments.unsubscribe&amp;returnurl=aHR0&amp;email=a@b.test&amp;expires=1&amp;cid[0]=2&amp;token=abc';
$data = ['UNSUBSCRIBE_URL' => $url, 'PUBLISH_URL' => $url, 'COMMENT_LINK' => 'http://x.test/a?b=1&c=2', 'NAME' => 'Tom &amp; Jerry', 'COMMENT_SANITIZED' => '<p>a &amp; b</p>', 'N' => 5];
$p    = TemplateEmails::plainTextData($data);

t_ok(strpos($p['UNSUBSCRIBE_URL'], '&amp;') === false && substr_count($p['UNSUBSCRIBE_URL'], '&') === 5, 'UNSUBSCRIBE_URL sin &amp; (5 separadores &)');
t_ok(strpos($p['PUBLISH_URL'], '&amp;') === false, 'PUBLISH_URL sin &amp;');
t_ok($p['COMMENT_LINK'] === $data['COMMENT_LINK'], 'COMMENT_LINK (no es *_URL) intacto');
t_ok($p['NAME'] === 'Tom &amp; Jerry' && $p['COMMENT_SANITIZED'] === '<p>a &amp; b</p>' && $p['N'] === 5, 'el resto de valores no se toca');
t_ok($data['UNSUBSCRIBE_URL'] === $url, 'el array original (HTML) conserva &amp;');
parse_str(html_entity_decode(parse_url($url, PHP_URL_QUERY)), $q);
parse_str(parse_url($p['UNSUBSCRIBE_URL'], PHP_URL_QUERY), $q2);
t_ok($q2 === $q && isset($q2['token'], $q2['email'], $q2['expires'], $q2['returnurl']), 'la consulta del enlace de texto plano tiene email, expires, token y returnurl');
parse_str(parse_url($url, PHP_URL_QUERY), $qMal);
t_ok(!isset($qMal['token']) && isset($qMal['amp;token']), 'con el enlace anterior el servidor veia "amp;token" y no "token" (el defecto)');

$src = file_get_contents(dirname(__DIR__) . '/src/component/backend/src/Helper/TemplateEmails.php');
t_ok(strpos($src, 'addTemplateData(self::plainTextData($data), true)') !== false, 'sendMail pasa los datos de texto plano a MailTemplate::addTemplateData($data, true)');
t_fin();
