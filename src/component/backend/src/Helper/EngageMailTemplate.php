<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Mail\MailTemplate;

/**
 * Mail template that removes the links which have no address (0.8.3).
 *
 * When the email carries no links (option «Include links in the emails» = No, or no trusted base URL) the tags of the links are empty. Left as they are,
 * the buttons of the template would be `href=""` (which Joomla turns into the home page of the request's Host) or `href="#"`. Once every tag has been
 * replaced, this class removes the buttons that have no address and keeps the text of the other links; in the plain text part it removes the lines that
 * only say «Comment URL:» or «unsubscribe:» with nothing after the colon. It only acts when the sender asks for it; otherwise it is the core class.
 *
 * @since 0.8.3
 */
class EngageMailTemplate extends MailTemplate
{
	/** @var bool */
	public $engageStripLinks = false;

	protected function replaceTags($text, $tags, $isHtml = false)
	{
		$out = parent::replaceTags($text, $tags, $isHtml);

		if (!$this->engageStripLinks || !is_string($out))
		{
			return $out;
		}

		return $isHtml ? self::stripDeadAnchors($out) : self::stripDeadLines($out);
	}

	/**
	 * Anchors whose href is empty or `#`: a button (class `engageemail-button*`) disappears; any other link is replaced by its text.
	 */
	public static function stripDeadAnchors(string $html): string
	{
		return preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			static function (array $m): string {
				if (!preg_match('/\bhref\s*=\s*(?:"\s*#?\s*"|\'\s*#?\s*\')/i', $m[1]))
				{
					return $m[0];
				}

				return preg_match('/\bclass\s*=\s*["\'][^"\']*\bengageemail-button\b/i', $m[1]) ? '' : trim($m[2]);
			},
			$html
		) ?? $html;
	}

	/** Lines of the plain text part that name a link and end in a colon with nothing after it. */
	public static function stripDeadLines(string $text): string
	{
		return preg_replace('/^[^\n]*(?:\bURL\b|\blink\b|\benlace\b|unsubscribe|\bbaja\b)[^\n]*:[ \t]*\r?(?:\n|$)/imu', '', $text) ?? $text;
	}
}
