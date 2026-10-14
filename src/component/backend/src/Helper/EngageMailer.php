<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Mail\Mail;
use Throwable;

/**
 * Mailer that decorates the message right before it is sent (0.9.0): rich HTML wrapper and confidentiality notice (see EmailWrapper).
 *
 * It is a copy of the mailer that Joomla created (the public configuration is copied: transport, SMTP, sender), so it does not depend on how the
 * templates are stored or on which MailTemplate class sends them. If anything fails while decorating, the message is sent as it was.
 *
 * @since 0.9.0
 */
class EngageMailer extends Mail
{
	/** @var array|null Decoration context (see decorate()); consumed by send(). */
	public $engageContext = null;

	/** A copy of $mailer (public state only: nothing has been addressed or attached yet) with the decoration context. */
	public static function from(Mail $mailer, array $context): self
	{
		if ($mailer instanceof self)
		{
			$mailer->engageContext = $context;

			return $mailer;
		}

		$new = new self(true);

		foreach (get_object_vars($mailer) as $k => $v)
		{
			$new->$k = $v;
		}

		$new->engageContext = $context;

		return $new;
	}

	public function send()
	{
		$ctx                 = $this->engageContext;
		$this->engageContext = null;

		if (is_array($ctx))
		{
			try
			{
				$this->decorate($ctx);
			}
			catch (Throwable $e)
			{
				// The message goes out undecorated
			}
		}

		return parent::send();
	}

	/** @param   array  $ctx  See EmailWrapper::compose() */
	private function decorate(array $ctx): void
	{
		$isHtml = stripos((string) $this->ContentType, 'text/html') === 0;
		[$body, $alt] = EmailWrapper::compose($ctx, (string) $this->Body, (string) $this->AltBody, $isHtml);

		$this->Body    = $body;
		$this->AltBody = $alt;
	}
}
