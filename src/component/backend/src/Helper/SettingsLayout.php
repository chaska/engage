<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.25): como se agrupan en categorias los ajustes de la pantalla de opciones modernas. Solo datos: cada
 * entrada [ambito, clave] se busca en el esquema leido de los manifiestos (SettingsSchema); lo que no esta declarado alli no se
 * pinta ni se puede guardar. Una prueba comprueba que TODO ajuste editable aparece exactamente una vez.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

final class SettingsLayout
{
	/** Ajustes que NO se editan aqui (dinamicos o de otro tipo): siguen disponibles en las opciones clasicas. */
	public const EXCLUDED = ['login_module', 'custom_default'];

	/**
	 * @return array<int,array{id:string,icon:string,tone:string,items:array<int,array{0:string,1:string,2?:string,group?:string}>,link?:string}>
	 */
	public static function sections(): array
	{
		$c = 'com_engage';

		return [
			['id' => 'design', 'icon' => 'palette', 'tone' => 'violet', 'items' => [
				[$c, 'theme', 'cards'], [$c, 'mobile_avatar'], [$c, 'max_level'], [$c, 'reply_show_quote'], [$c, 'reply_indent'], [$c, 'reply_style'],
				[$c, 'comments_ordering'], [$c, 'comments_show'], [$c, 'comments_show_featured'], [$c, 'comments_show_category'], [$c, 'comments_show_article'],
			]],
			['id' => 'moderation', 'icon' => 'check', 'tone' => 'green', 'items' => [
				[$c, 'default_publish'], [$c, 'comments_enabled'], [$c, 'comments_close_after'], [$c, 'min_length'], [$c, 'max_length'], [$c, 'default_limit'],
			]],
			['id' => 'reactions', 'icon' => 'heart', 'tone' => 'red', 'items' => [
				[$c, 'reactions_enabled'], [$c, 'reactions_dislike'], [$c, 'reactions_favorites'], [$c, 'reactions_who'],
			]],
			['id' => 'antispam', 'icon' => 'flag', 'tone' => 'red', 'items' => [
				[$c, 'captcha'], [$c, 'captcha_for'], [$c, 'tos_accept'], [$c, 'tos_prompt'],
				['plg_engage_akismet', 'key', '', 'akismet'], ['plg_engage_akismet', 'check', '', 'akismet'], ['plg_engage_akismet', 'discard_blatant', '', 'akismet'],
			]],
			['id' => 'notifications', 'icon' => 'bell', 'tone' => 'orange', 'items' => [
				[$c, 'comments_notify_author'], [$c, 'comments_notify_users'],
				['plg_engage_email', 'managers_notify', '', 'email'], ['plg_engage_email', 'managers_notify_spam', '', 'email'],
			]],
			['id' => 'privacy', 'icon' => 'eye', 'tone' => 'blue', 'items' => [
				['plg_engage_gravatar', 'mode', '', 'gravatar'], ['plg_engage_gravatar', 'show_notice', '', 'gravatar'], ['plg_engage_gravatar', 'consent_source', '', 'gravatar'],
				['plg_engage_gravatar', 'jbcookies_group', '', 'gravatar'], ['plg_engage_gravatar', 'profile_link', '', 'gravatar'], ['plg_engage_gravatar', 'rating', '', 'gravatar'],
				['plg_engage_gravatar', 'default_image', '', 'gravatar'], ['plg_engage_gravatar', 'force_default', '', 'gravatar'],
			]],
			['id' => 'security', 'icon' => 'shield', 'tone' => 'slate', 'items' => [
				[$c, 'filter_mode'], [$c, 'htmlpurifier_config_joomla'], [$c, 'htmlpurifier_configstring'], [$c, 'htmlpurifier_include'],
			]],
			['id' => 'advanced', 'icon' => 'wrench', 'tone' => 'slate', 'items' => [
				[$c, 'workaround_mailtemplate'], [$c, 'iplookup'], [$c, 'max_spam_age'], [$c, 'loadCustomCss'], [$c, 'comments_reply_bad_ux'], [$c, 'pleaseWait'],
			]],
			['id' => 'permissions', 'icon' => 'lock', 'tone' => 'orange', 'items' => [], 'link' => 'permissions'],
		];
	}

	/** Identificadores validos de categoria (para ?section=). */
	public static function ids(): array
	{
		return array_column(self::sections(), 'id');
	}
}
