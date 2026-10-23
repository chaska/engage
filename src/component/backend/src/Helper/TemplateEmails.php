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
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Exception;
use Joomla\Database\DatabaseInterface;
use stdClass;

/**
 * Manage and send emails with Joomla's email templates component
 */
abstract class TemplateEmails
{
	/**
	 * Email templates known to Akeeba Ticket System.
	 */
	private const EMAIL_DEFINITIONS = [
		'com_engage.manage'       => [
			'subject'       => 'COM_ENGAGE_MAIL_MANAGE_SUBJECT',
			'bodyPlaintext' => 'COM_ENGAGE_MAIL_MANAGE_BODY',
			'bodyHtml'      => 'COM_ENGAGE_MAIL_MANAGE_BODY_HTML',
			'variables'     => [
				'SITEURL',
				'SITENAME',
				'RECIPIENT_NAME',
				'RECIPIENT_EMAIL',
				'CONTENT_LINK',
				'CONTENT_TITLE',
				'CONTENT_CATEGORY',
				'AVATAR_URL',
				'NAME',
				'EMAIL',
				'IP',
				'USER_AGENT',
				'DATE_LOCAL',
				'COMMENT_SANITIZED',
				'COMMENT_PLAINTEXT',
				'COMMENT_LINK',
				'PUBLISH_URL',
				'UNPUBLISH_URL',
				'DELETE_URL',
				'POSSIBLESPAM_URL',
				'SPAM_URL',
				'UNSPAM_URL',
				'UNSUBSCRIBE_URL',
			],
		],
		'com_engage.spam'       => [
			'subject'       => 'COM_ENGAGE_MAIL_SPAM_SUBJECT',
			'bodyPlaintext' => 'COM_ENGAGE_MAIL_SPAM_BODY',
			'bodyHtml'      => 'COM_ENGAGE_MAIL_SPAM_BODY_HTML',
			'variables'     => [
				'SITEURL',
				'SITENAME',
				'RECIPIENT_NAME',
				'RECIPIENT_EMAIL',
				'CONTENT_LINK',
				'CONTENT_TITLE',
				'CONTENT_CATEGORY',
				'AVATAR_URL',
				'NAME',
				'EMAIL',
				'IP',
				'USER_AGENT',
				'DATE_LOCAL',
				'COMMENT_SANITIZED',
				'COMMENT_PLAINTEXT',
				'COMMENT_LINK',
				'PUBLISH_URL',
				'UNPUBLISH_URL',
				'DELETE_URL',
				'POSSIBLESPAM_URL',
				'SPAM_URL',
				'UNSPAM_URL',
				'UNSUBSCRIBE_URL',
			],
		],
		'com_engage.notify'       => [
			'subject'       => 'COM_ENGAGE_MAIL_NOTIFY_SUBJECT',
			'bodyPlaintext' => 'COM_ENGAGE_MAIL_NOTIFY_BODY',
			'bodyHtml'      => 'COM_ENGAGE_MAIL_NOTIFY_BODY_HTML',
			'variables'     => [
				'SITEURL',
				'SITENAME',
				'RECIPIENT_NAME',
				'RECIPIENT_EMAIL',
				'CONTENT_LINK',
				'CONTENT_TITLE',
				'CONTENT_CATEGORY',
				'AVATAR_URL',
				'NAME',
				'EMAIL',
				'IP',
				'USER_AGENT',
				'DATE_LOCAL',
				'COMMENT_SANITIZED',
				'COMMENT_PLAINTEXT',
				'COMMENT_LINK',
				'PUBLISH_URL',
				'UNPUBLISH_URL',
				'DELETE_URL',
				'POSSIBLESPAM_URL',
				'SPAM_URL',
				'UNSPAM_URL',
				'UNSUBSCRIBE_URL',
			],
		],
		'com_engage.notify_managers'       => [
			'subject'       => 'COM_ENGAGE_MAIL_NOTIFY_MANAGERS_SUBJECT',
			'bodyPlaintext' => 'COM_ENGAGE_MAIL_NOTIFY_MANAGERS_BODY',
			'bodyHtml'      => 'COM_ENGAGE_MAIL_NOTIFY_MANAGERS_BODY_HTML',
			'variables'     => [
				'SITEURL',
				'SITENAME',
				'RECIPIENT_NAME',
				'RECIPIENT_EMAIL',
				'CONTENT_LINK',
				'CONTENT_TITLE',
				'CONTENT_CATEGORY',
				'AVATAR_URL',
				'NAME',
				'EMAIL',
				'IP',
				'USER_AGENT',
				'DATE_LOCAL',
				'COMMENT_SANITIZED',
				'COMMENT_PLAINTEXT',
				'COMMENT_LINK',
				'PUBLISH_URL',
				'UNPUBLISH_URL',
				'DELETE_URL',
				'POSSIBLESPAM_URL',
				'SPAM_URL',
				'UNSPAM_URL',
				'UNSUBSCRIBE_URL',
			],
		],
		'com_engage.notify_author'       => [
			'subject'       => 'COM_ENGAGE_MAIL_NOTIFY_AUTHOR_SUBJECT',
			'bodyPlaintext' => 'COM_ENGAGE_MAIL_NOTIFY_AUTHOR_BODY',
			'bodyHtml'      => 'COM_ENGAGE_MAIL_NOTIFY_AUTHOR_BODY_HTML',
			'variables'     => [
				'SITEURL',
				'SITENAME',
				'RECIPIENT_NAME',
				'RECIPIENT_EMAIL',
				'CONTENT_LINK',
				'CONTENT_TITLE',
				'CONTENT_CATEGORY',
				'AVATAR_URL',
				'NAME',
				'EMAIL',
				'IP',
				'USER_AGENT',
				'DATE_LOCAL',
				'COMMENT_SANITIZED',
				'COMMENT_PLAINTEXT',
				'COMMENT_LINK',
				'PUBLISH_URL',
				'UNPUBLISH_URL',
				'DELETE_URL',
				'POSSIBLESPAM_URL',
				'SPAM_URL',
				'UNSPAM_URL',
				'UNSUBSCRIBE_URL',
			],
		],

	];

	/**
	 * Returns the number of the known email templates.
	 *
	 * @return  int
	 */
	public static function countKnownTemplates(): int
	{
		return count(self::EMAIL_DEFINITIONS);
	}

	/**
	 * Returns the number of the know templates configured in the database.
	 *
	 * Remember that this may include templates which are out-of-date!
	 *
	 * @return  int
	 */
	public static function countTemplates(): int
	{
		return self::actOnTemplates('return');
	}

	/**
	 * Removes all email templates we know about.
	 *
	 * WARNING! THIS ALSO REMOVES THE USER-GENERATED EMAIL TEMPLATES FOR ALL KEYS WE KNOW.
	 *
	 * @return  int Number of email template keys affected
	 */
	public static function deleteAllTemplates(): int
	{
		return self::actOnTemplates('delete');
	}

	/**
	 * Returns the keys of the known email templates.
	 *
	 * @return  string[]
	 */
	public static function getKnownTemplatesKeys(): array
	{
		return array_keys(self::EMAIL_DEFINITIONS);
	}

	/**
	 * Checks whether the main email template for the specific key exists in the database.
	 *
	 * It does NOT check if the template is up-to-date.
	 *
	 * @param   string  $key
	 *
	 * @return  bool
	 */
	public static function hasTemplate(string $key): bool
	{
		return self::actOnTemplate($key, 'return');
	}

	/**
	 * Resets all email templates we know about.
	 *
	 * WARNING! THIS ALSO REMOVES THE USER-GENERATED EMAIL TEMPLATES FOR ALL KEYS WE KNOW.
	 *
	 * @return  int Number of email template keys affected
	 */
	public static function resetAllTemplates(): int
	{
		return self::actOnTemplates('reset');
	}

	/**
	 * Resets an email template.
	 *
	 * WARNING! THIS ALSO REMOVES THE USER-GENERATED EMAIL TEMPLATES FOR THIS KEY.
	 *
	 * @param   string  $key
	 *
	 * @return  bool
	 */
	public static function resetTemplate(string $key)
	{
		return self::actOnTemplate($key, 'reset');
	}

	/**
	 * Datos de sustitución para la parte de TEXTO PLANO del correo.
	 *
	 * Los enlaces firmados se generan con `Route::_(..., $xhtml = true)`, es decir con `&amp;` entre parámetros, lo que es
	 * correcto dentro de un atributo href del correo HTML pero rompe el enlace en texto plano (el cliente de correo envía
	 * `amp;returnurl=...` y el servidor no ve `email`, `expires` ni `token`; el enlace de baja no se ejecuta). Aquí solo se
	 * deshace esa codificación en las claves *_URL; el resto de valores no se toca.
	 *
	 * @param   array  $data  Los datos de sustitución de la plantilla
	 *
	 * @return  array
	 * @since   0.6.13
	 */
	public static function plainTextData(array $data): array
	{
		foreach ($data as $key => $value)
		{
			if (is_string($value) && substr((string) $key, -4) === '_URL')
			{
				$data[$key] = str_replace('&amp;', '&', $value);
			}
		}

		// 0.9.6: the text part never shows the comment as HTML (`<p>…</p>`): a template that uses {COMMENT_SANITIZED} in its plain text gets the clean text
		// (paragraphs kept, tags removed, entities decoded ONCE by EmailWrapper::htmlToText: a comment that says «&amp;» keeps saying «&amp;»)
		if (isset($data['COMMENT_PLAINTEXT']) && is_string($data['COMMENT_PLAINTEXT']))
		{
			$data['COMMENT_PLAINTEXT'] = trim($data['COMMENT_PLAINTEXT']);
			$data['COMMENT_SANITIZED'] = $data['COMMENT_PLAINTEXT'];
		}

		return $data;
	}

	/**
	 * Joomla's mail layer turns every EMPTY or relative href of the HTML part into an absolute URL built with the Host header of the request
	 * (MailHelper::convertRelativeToAbsoluteUrls: `Uri::root()`). A link that has no trusted base (0.8.3: see TrustedBase) is therefore sent to the HTML part
	 * as `#`, which that conversion leaves alone; the plain text part keeps the empty string (see sendMail).
	 *
	 * @param   array  $data  The tags of the email
	 *
	 * @return  array
	 * @since   0.8.3
	 */
	public static function inertLinks(array $data): array
	{
		foreach (self::LINK_TAGS as $key)
		{
			if (array_key_exists($key, $data) && ($data[$key] === '' || $data[$key] === null))
			{
				$data[$key] = '#';
			}
		}

		return $data;
	}

	/** Tags that are the target of a link (an `href`) in the templates. AVATAR_URL is an image, not a link. */
	public const LINK_TAGS = ['SITEURL', 'CONTENT_LINK', 'COMMENT_LINK', 'PUBLISH_URL', 'UNPUBLISH_URL', 'DELETE_URL', 'POSSIBLESPAM_URL', 'SPAM_URL', 'UNSPAM_URL', 'UNSUBSCRIBE_URL'];

	/** Does this email have a link tag without an address? Then the buttons and links that use it must disappear (EngageMailTemplate). */
	public static function hasEmptyLinks(array $data): bool
	{
		foreach (self::LINK_TAGS as $key)
		{
			if (array_key_exists($key, $data) && ($data[$key] === '' || $data[$key] === null))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Sends an email using a template.
	 *
	 * WARNING! THIS DOES NOT CHECK IF THE TEMPLATE EXISTS. USE TemplateEmails::updateTemplate($key) FIRST.
	 *
	 * @param   string       $key            The email template key to send
	 * @param   array        $data           The variable/tag associative array to include in the email
	 * @param   User|null    $user           The user to send the email to. NULL for the currently logged in user.
	 * @param   string|null  $forceLanguage  Force a specific language tag instead of using the user's preferences.
	 * @param   bool         $throw          False (default) to return false on error, True to throw the exception back
	 *                                       to you.
	 *
	 * @return  bool True if the email was sent.
	 * @throws  Exception When $throw === true and there's an error sending the email
	 */
	public static function sendMail(string $key, array $data, ?User $user = null, ?string $forceLanguage = null, bool $throw = false, ?Mail $mailer = null, array $wrap = []): bool
	{
		if (empty($user))
		{
			$user = UserFetcher::getUser();
		}

		//if ($user->guest || $user->block || !$user->sendEmail)
		if ($user->block)
		{
			return false;
		}

		try
		{
			/**
			 * We create a custom mailer, setting its priority to normal.
			 *
			 * Even though the Priority is nominally optional, SpamAssassin will reject emails without a priority.
			 * That's a major WTF which even Joomla itself doesn't know about :O
			 */
			if (empty($mailer))
			{
				$mailer           = Factory::getMailer();
				$mailer->Priority = 3;
			}


			$app              = Factory::getApplication();
			$appLang          = $app->getLanguage() ?? null;
			$appLang          = is_object($appLang) ? $appLang->getTag() : null;
			$userLang         = $app->isClient('administrator') ? $user->getParam('administrator_language') : $user->getParam('language');
			$userFrontendLang = $user->getParam('language');
			$langTag          = $userLang ?: $userFrontendLang ?: $appLang ?: 'en-GB';
			$langTag          = $forceLanguage ?: $langTag;

			/**
			 * Try to get the template. Remember that Joomla looks for the specific language tag or the main template
			 * which defines no language and falls back to translation strings.
			 */
			$template = MailTemplate::getTemplate($key, $langTag);

			if (empty($template))
			{
				// Yeah, well, there's no template. I can't send the email, I'm afrain.
				return false;
			}

			$templateMailer = self::buildTemplateMailer($key, $langTag, $mailer, $data);

			// 0.9.0: rich HTML wrapper and confidentiality notice, applied to the final message when it is sent (the stored templates are not touched)
			if ($wrap !== [] && !empty($wrap['enabled']) && $mailer instanceof Mail)
			{
				$wrap['lang'] = $langTag;
				$wrap['key']  = $key;
				$wrap['tr']   = self::translator($langTag);
				$mailer       = EngageMailer::from($mailer, $wrap);

				// the template classes keep a reference to the mailer given to them: build them again with the decorating one
				$templateMailer = self::buildTemplateMailer($key, $langTag, $mailer, $data);
			}

			$templateMailer->addTemplateData(self::inertLinks($data));

			// Joomla usa estos datos en lugar de $data al generar la parte de texto plano.
			$templateMailer->addTemplateData(self::plainTextData($data), true);
			$templateMailer->addRecipient(trim($user->email), $user->name);

			// 0.9.6: Engage ALWAYS sends HTML + plain text (multipart/alternative), whatever the «Mail format» of Joomla's Mail Templates options says
			// (its default is «Plain text only»: the 0.9.0 design never reached such sites). The option is forced in memory only while the message
			// is built and sent, and restored afterwards; the stored configuration is never touched.
			$mailsParams = ComponentHelper::getParams('com_mails');
			$forced      = ['mail_style' => 'both', 'disable_htmllayout' => '0', 'alternative_mailconfig' => 0];
			$previous    = [];

			foreach ($forced as $k => $v)
			{
				$previous[$k] = $mailsParams->get($k);
				$mailsParams->set($k, $v);
			}

			try
			{
				return $templateMailer->send();
			}
			finally
			{
				foreach ($previous as $k => $v)
				{
					if ($v === null)
					{
						$mailsParams->remove($k);
					}
					else
					{
						$mailsParams->set($k, $v);
					}
				}
			}
		}
		catch (Exception $e)
		{
			if ($throw)
			{
				throw $e;
			}

			return false;
		}
	}

	/** The MailTemplate object that will send the message (hot fix class of 0.7.x, or the one that removes dead links, or the core one). */
	private static function buildTemplateMailer(string $key, string $langTag, Mail $mailer, array $data): MailTemplate
	{
		if (ComponentHelper::getParams('com_engage')->get('workaround_mailtemplate', 1))
		{
			$templateMailer = MailTemplateHotFix::getAWorkingMailTemplate($key, $langTag, $mailer);
		}
		else
		{
			$templateMailer = new MailTemplate($key, $langTag, $mailer);
		}

		// 0.8.3: with empty link tags the dead buttons and links are removed after the tags are replaced (EngageMailTemplate)
		if (self::hasEmptyLinks($data) && get_class($templateMailer) === MailTemplate::class)
		{
			$templateMailer                   = new EngageMailTemplate($key, $langTag, $mailer);
			$templateMailer->engageStripLinks = true;
		}

		return $templateMailer;
	}

	/**
	 * Translator for the language of the email: strings of com_engage (with the overrides of that language), falling back to en-GB.
	 *
	 * @return  callable  fn(string $key, ...$args): string
	 * @since   0.9.0
	 */
	public static function translator(string $langTag): callable
	{
		$lang = null;

		try
		{
			$tag  = preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $langTag) === 1 ? $langTag : 'en-GB';
			$lang = Factory::getContainer()->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage($tag, false);
			// $default = true: the strings of en-GB are loaded first, so a missing translation falls back to English
			$lang->load('com_engage', JPATH_ADMINISTRATOR, $tag, true, true);
		}
		catch (\Throwable $e)
		{
			$lang = null;
		}

		return static function (string $k, ...$args) use ($lang): string {
			$text = $lang ? (string) $lang->_($k) : $k;

			return $args ? vsprintf($text, $args) : $text;
		};
	}

	/**
	 * Update all email templates we know about.
	 *
	 * This operates only on the mail templates. User–generated templates are kept as-is.
	 *
	 * @return  int
	 */
	public static function updateAllTemplates(): int
	{
		return self::actOnTemplates('fix');
	}

	/**
	 * Updates a specific email template.
	 *
	 * Makes sure that the main email template exists in the database. If it doesn't, it's created. If it exists and its
	 * variables (tags), subject, body (plaintext) or body (HTML) differ it will be updated. Otherwise no further action
	 * is taken.
	 *
	 * @param   string  $key
	 *
	 * @return bool
	 */
	public static function updateTemplate(string $key)
	{
		return self::actOnTemplate($key, 'fix');
	}

	private static function actOnTemplate(string $key, string $action = 'return'): bool
	{
		/**
		 * Note that we are only checking the email template WITHOUT a language. This is considered the "default" email
		 * template from which all the localised email templates are generated. We only care if that email template
		 * exists and is up to date. We don't mess with the user-defined email templates, ever!
		 */
		try
		{
			/** @var DatabaseDriver $db */
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true));
			$query->select('*')
				->from($db->quoteName('#__mail_templates'))
				->where($db->quoteName('template_id') . ' = :key')
				->where($db->quoteName('language') . ' = ' . $db->quote(''))
				->order($db->quoteName('language') . ' DESC')
				->bind(':key', $key);

			$templateInDB = $db->setQuery($query)->loadAssoc() ?: [];
			$hasTemplate  = !empty($templateInDB);
		}
		catch (\Exception $e)
		{
			$templateInDB = [];
			$hasTemplate  = false;
		}

		$knownTemplate = array_key_exists($key, self::EMAIL_DEFINITIONS);
		$action        = strtolower($action);

		switch (strtolower($action))
		{
			// Ensures a template exists and its definition is up-to-date
			case 'fix':
				if (!$knownTemplate)
				{
					return false;
				}

				// The template does not exist in the database. Create it.
				if (!$hasTemplate)
				{
					$record = self::EMAIL_DEFINITIONS[$key];
					self::createTemplate($key, $record['subject'], $record['bodyPlaintext'], $record['variables'], $record['bodyHtml'] ?? '');

					return true;
				}

				$record = self::EMAIL_DEFINITIONS[$key];

				// Do I need to update the record? We check the variables, subject and the plaintext and HTML bodies.
				try
				{
					$params         = json_decode($templateInDB['params'], true);
					$variablesInDB  = array_map('strtoupper', (array) $params['tags'][0] ?? []);
					$variablesKnown = array_map('strtoupper', $record['variables'] ?? []);
					$isIdentical    = empty(array_diff($variablesKnown, $variablesInDB));

					$isIdentical = $isIdentical && ($templateInDB['subject'] == $record['subject']);
					$isIdentical = $isIdentical && ($templateInDB['body'] == $record['bodyPlaintext']);
					$isIdentical = $isIdentical && ($templateInDB['htmlbody'] == $record['bodyHtml']);
				}
				catch (\Exception $e)
				{
					// The template is corrupt. We will reset it.
					return self::actOnTemplate($key, 'reset');
				}

				// The template in the DB is up-to-date. Bye-bye!
				if ($isIdentical)
				{
					return true;
				}

				// There were differences. Let's update the template.
				self::updateTemplateInDB($key, $record['subject'], $record['bodyPlaintext'], $record['variables'], $record['bodyHtml'] ?? '');

				return true;
				break;

			// Forcibly update a template if exists
			case 'update':
				if (!$knownTemplate)
				{
					return false;
				}

				if (!$hasTemplate)
				{
					return true;
				}

				$record = self::EMAIL_DEFINITIONS[$key];
				self::updateTemplateInDB($key, $record['subject'], $record['bodyPlaintext'], $record['variables'], $record['bodyHtml'] ?? '');

				return true;
				break;

			// Forcibly reset a template
			case 'reset':
				if (!$knownTemplate)
				{
					return false;
				}

				if ($hasTemplate)
				{
					MailTemplate::deleteTemplate($key);
				}

				$record = self::EMAIL_DEFINITIONS[$key];
				self::createTemplate($key, $record['subject'], $record['bodyPlaintext'], $record['variables'], $record['bodyHtml'] ?? '');

				return true;
				break;

			// Only return whether a template exists
			case 'return':
			default:
				return $hasTemplate;
				break;
		}
	}

	private static function actOnTemplates(string $action = 'return'): int
	{
		$count = 0;

		foreach (array_keys(self::EMAIL_DEFINITIONS) as $key)
		{
			if ($action === 'delete')
			{
				MailTemplate::deleteTemplate($key);

				continue;
			}

			if (self::actOnTemplate($key, $action))
			{
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Fork of MailTemplate::createTemplate WHICH ACTUALLY WORKS WITHOUT THROWING ERRORS.
	 *
	 * Insert a new mail template into the system
	 *
	 * @param   string  $key       Mail template key
	 * @param   string  $subject   A default subject (normally a translatable string)
	 * @param   string  $body      A default body (normally a translatable string)
	 * @param   array   $tags      Associative array of tags to replace
	 * @param   string  $htmlbody  A default htmlbody (normally a translatable string)
	 *
	 * @return  bool  True on success, false on failure
	 *
	 * @since   7.0.0
	 */
	private static function createTemplate(string $key, string $subject, string $body, array $tags, string $htmlbody = ''): bool
	{
		/** @var DatabaseDriver $db */
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		$template              = new stdClass();
		$template->template_id = $key;
		$template->language    = '';
		$template->subject     = $subject;
		$template->body        = $body;
		$template->htmlbody    = $htmlbody;
		$template->attachments = '';
		$template->extension   = explode('.', $key, 2)[0];
		$params                = new stdClass();
		$params->tags          = $tags;
		$template->params      = json_encode($params);

		return $db->insertObject('#__mail_templates', $template);
	}

	/**
	 * Fork of MailTemplate::updateTemplate WHICH ACTUALLY WORKS WITHOUT THROWING ERRORS.
	 *
	 * Update an existing mail template
	 *
	 * @param   string  $key       Mail template key
	 * @param   string  $subject   A default subject (normally a translatable string)
	 * @param   string  $body      A default body (normally a translatable string)
	 * @param   array   $tags      Associative array of tags to replace
	 * @param   string  $htmlbody  A default htmlbody (normally a translatable string)
	 *
	 * @return  bool  True on success, false on failure
	 *
	 * @since   7.0.0
	 */
	private static function updateTemplateInDB($key, $subject, $body, $tags, $htmlbody = '')
	{
		/** @var DatabaseDriver $db */
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		$template              = new stdClass();
		$template->template_id = $key;
		$template->language    = '';
		$template->subject     = $subject;
		$template->body        = $body;
		$template->htmlbody    = $htmlbody;
		$params                = new stdClass();
		$params->tags          = (array) $tags;
		$template->params      = json_encode($params);

		return $db->updateObject('#__mail_templates', $template, ['template_id', 'language']);
	}

}