<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * GROUPED notification of new reports to the moderators (0.9.14, optional: `reports_notify`). ONE email per moderator for all the reports that have not been
 * announced yet (never one per report), at most every 8 hours (the caller keeps the clock). Plain text, no data of the reporters, no comment text. The link to
 * the queue is only added with a trusted base address. Also keeps the table tidy.
 */
final class ReportDigest
{
	/** Maximum recipients per digest. */
	public const MAX_RECIPIENTS = 50;

	/** Handled reports are kept this long (so the same person cannot report the same comment again right away), in days. */
	public const KEEP_DAYS = 180;

	/** Runs the digest and the housekeeping. Never throws. Returns the number of emails sent. */
	public static function run(bool $notify): int
	{
		$sent = 0;

		try
		{
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$store = new ReportStore($db);
			$store->cleanup(gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400));

			if (!$notify)
			{
				return 0;
			}

			[$n] = $store->pendingDigest();

			if ($n < 1)
			{
				return 0;
			}

			$app  = Factory::getApplication();
			$app->getLanguage()->load('com_engage', JPATH_ADMINISTRATOR);
			$site = (string) $app->get('sitename', '');
			$body = Text::sprintf('COM_ENGAGE_REPORTS_DIGEST_BODY', $n, $site);
			$link = TrustedBase::absolute('/administrator/index.php?option=com_engage&view=reports');

			if ($link !== '')
			{
				$body .= "\n\n" . Text::sprintf('COM_ENGAGE_REPORTS_DIGEST_LINK', $link);
			}

			$subject = Text::sprintf('COM_ENGAGE_REPORTS_DIGEST_SUBJECT', $site, $n);

			foreach (array_slice(self::recipients(), 0, self::MAX_RECIPIENTS) as $email)
			{
				try
				{
					$mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer($app->getConfig());
					$mailer->addRecipient($email);
					$mailer->setSubject($subject);
					$mailer->setBody($body);
					$mailer->isHtml(false);

					if ($mailer->send() === true)
					{
						$sent++;
					}
				}
				catch (Throwable $e)
				{
				}
			}

			if ($sent > 0)
			{
				$store->markNotified(gmdate('Y-m-d H:i:s'));
			}
		}
		catch (Throwable $e)
		{
		}

		return $sent;
	}

	/** E-mail addresses of the active people who can moderate comments (core.edit.state on the component). */
	public static function recipients(): array
	{
		$db     = Factory::getContainer()->get(DatabaseInterface::class);
		$groups = $db->setQuery($db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__usergroups')))->loadColumn() ?: [];
		$out    = [];

		foreach ($groups as $gid)
		{
			if (!Access::checkGroup((int) $gid, 'core.edit.state', 'com_engage') && !Access::checkGroup((int) $gid, 'core.admin'))
			{
				continue;
			}

			foreach (Access::getUsersByGroup((int) $gid) as $uid)
			{
				$u = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $uid);

				if ($u->id > 0 && !$u->block && filter_var((string) $u->email, FILTER_VALIDATE_EMAIL))
				{
					$out[strtolower((string) $u->email)] = (string) $u->email;
				}
			}
		}

		return array_values($out);
	}
}
