<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Preview and test email of the notification emails (0.9.0). The preview uses sample data and the DEFAULT templates of Engage in the chosen language;
 * it sends nothing and makes no request. The test email goes through the real path (stored template, EngageMailer) but only to the logged-in administrator.
 *
 * @since 0.9.0
 */
final class EmailPreview
{
	public const KINDS = ['moderator' => 'com_engage.notify_managers', 'subscriber' => 'com_engage.notify_author'];

	/** Languages offered by the preview (closed list). */
	public const LANGS = ['es-ES', 'en-GB'];

	/** Seconds between two test emails of the same session. */
	public const MIN_SECONDS = 60;

	/** The options as the plugin reads them. */
	public static function options(): array
	{
		$p = ComponentHelper::getParams('com_engage');

		return [
			'links'        => !in_array($p->get('email_include_links', 1), [0, '0', false], true),
			'rich'         => !in_array($p->get('email_rich', 1), [0, '0', false], true),
			'confidential' => !in_array($p->get('email_confidential', 1), [0, '0', false], true),
			'accent'       => (string) $p->get('email_accent', 'blue'),
		];
	}

	public static function kind($v): string
	{
		return is_string($v) && isset(self::KINDS[$v]) ? $v : 'moderator';
	}

	public static function lang($v): string
	{
		return is_string($v) && in_array($v, self::LANGS, true) ? $v : 'en-GB';
	}

	/** Sample tags. URLs only with the links option on and a trusted base; otherwise empty (the dead buttons then disappear). */
	public static function sampleData(string $lang, bool $links, bool $forPlain = false): array
	{
		$tr   = TemplateEmails::translator($lang);
		$base = $links ? TrustedBase::absolute('index.php?option=com_engage&view=comments') : '';
		$site = (string) Factory::getApplication()->get('sitename', 'Site');

		return [
			'SITEURL'           => $links ? TrustedBase::root() : '',
			'SITENAME'          => $site,
			'RECIPIENT_NAME'    => 'Ana',
			'RECIPIENT_EMAIL'   => 'ana@example.com',
			'CONTENT_LINK'      => $base,
			'COMMENT_LINK'      => $base,
			'CONTENT_TITLE'     => (string) $tr('COM_ENGAGE_MAILPREVIEW_SAMPLE_TITLE'),
			'CONTENT_CATEGORY'  => (string) $tr('COM_ENGAGE_MAILPREVIEW_SAMPLE_CATEGORY'),
			'AVATAR_URL'        => '',
			'NAME'              => 'Luis',
			'EMAIL'             => 'luis@example.com',
			'IP'                => '203.0.113.7',
			'USER_AGENT'        => 'Mozilla/5.0',
			'DATE_LOCAL'        => Factory::getDate()->format('Y-m-d H:i', true),
			'COMMENT_SANITIZED' => '<p>' . htmlspecialchars((string) $tr('COM_ENGAGE_MAILPREVIEW_SAMPLE_COMMENT'), ENT_QUOTES, 'UTF-8') . '</p>',
			'COMMENT_PLAINTEXT' => (string) $tr('COM_ENGAGE_MAILPREVIEW_SAMPLE_COMMENT'),
			'PUBLISH_URL'       => $base, 'UNPUBLISH_URL' => $base, 'DELETE_URL' => $base, 'POSSIBLESPAM_URL' => $base, 'SPAM_URL' => $base, 'UNSPAM_URL' => $base,
			'UNSUBSCRIBE_URL'   => $base,
		];
	}

	/**
	 * The preview: [html, plain] of the email of the given kind and language, with the current options.
	 *
	 * @return  array{0:string,1:string}
	 */
	public static function render(string $kind, string $lang): array
	{
		$kind = self::kind($kind);
		$lang = self::lang($lang);
		$key  = self::KINDS[$kind];
		$o    = self::options();
		$tr   = TemplateEmails::translator($lang);
		$data = self::sampleData($lang, $o['links']);
		$K    = strtoupper(str_replace('com_engage.', '', $key));

		$html  = (string) $tr('COM_ENGAGE_MAIL_' . $K . '_BODY_HTML');
		$plain = (string) $tr('COM_ENGAGE_MAIL_' . $K . '_BODY');

		foreach ($data as $tag => $value)
		{
			$html  = str_replace('{' . $tag . '}', in_array($tag, ['COMMENT_SANITIZED'], true) ? $value : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), $html);
			$plain = str_replace('{' . $tag . '}', (string) $value, $plain);
		}

		if (TemplateEmails::hasEmptyLinks($data))
		{
			$html  = EngageMailTemplate::stripDeadAnchors($html);
			$plain = EngageMailTemplate::stripDeadLines($plain);
		}

		return EmailWrapper::compose(self::context($key, $lang, $o, $data, $kind === 'subscriber', $tr), $html, $plain, true);
	}

	/** The decoration context used by the plugin, for the preview and the test email. */
	public static function context(string $key, string $lang, array $o, array $data, bool $subscriber, callable $tr): array
	{
		$app = Factory::getApplication();

		return [
			'enabled'      => $o['rich'] || $o['confidential'],
			'rich'         => $o['rich'],
			'confidential' => $o['confidential'],
			'accent'       => $o['accent'],
			'lang'         => $lang,
			'key'          => $key,
			'sitename'     => (string) $app->get('sitename', ''),
			'siteurl'      => $o['links'] ? TrustedBase::root() : '',
			'contact'      => (string) $app->get('mailfrom', ''),
			'unsubscribe'  => (string) ($data['UNSUBSCRIBE_URL'] ?? ''),
			'subscriber'   => $subscriber,
			'tr'           => $tr,
		];
	}

	/** Seconds the user still has to wait to send a test email (0 = may send now). */
	public static function waitSeconds(?int $last, ?int $now = null): int
	{
		$now = $now ?? time();

		return $last !== null && $last > 0 && $now - $last < self::MIN_SECONDS ? self::MIN_SECONDS - ($now - $last) : 0;
	}

	/** Sends the test email to the logged-in user (the only recipient). */
	public static function sendTest(string $kind, string $lang, \Joomla\CMS\User\User $user): bool
	{
		$kind = self::kind($kind);
		$lang = self::lang($lang);
		$o    = self::options();
		$data = self::sampleData($lang, $o['links']);
		$key  = self::KINDS[$kind];
		$tr   = TemplateEmails::translator($lang);

		$data['RECIPIENT_NAME']  = strip_tags((string) $user->name);
		$data['RECIPIENT_EMAIL'] = strip_tags((string) $user->email);

		if (!TemplateEmails::hasTemplate($key))
		{
			TemplateEmails::updateTemplate($key);
		}

		return TemplateEmails::sendMail($key, $data, $user, $lang, false, null, self::context($key, $lang, $o, $data, $kind === 'subscriber', $tr));
	}
}
