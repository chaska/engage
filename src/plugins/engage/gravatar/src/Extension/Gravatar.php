<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\Engage\Gravatar\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Gravatar integration for Akeeba Engage
 *
 * @since  1.0.0
 */
class Gravatar extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Disallow registering legacy listeners since we use SubscriberInterface
	 *
	 * @var   bool
	 * @since 3.0.0
	 */
	protected $allowLegacyListeners = false;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   3.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAkeebaEngageUserAvatarURL'  => 'onAkeebaEngageUserAvatarURL',
			'onAkeebaEngageUserProfileURL' => 'onAkeebaEngageUserProfileURL',
		];
	}

	/**
	 * Never contact Gravatar. A generic local avatar is shown.
	 *
	 * @since 0.6.17
	 */
	public const MODE_OFF = 'off';

	/**
	 * Show a generic local avatar until the visitor consents (in the browser) to load the Gravatar images. DEFAULT.
	 *
	 * @since 0.6.17
	 */
	public const MODE_ASK = 'ask';

	/**
	 * Always load the images from Gravatar (the visitor's browser contacts gravatar.com without asking).
	 *
	 * @since 0.6.17
	 */
	public const MODE_ALWAYS = 'always';

	/**
	 * The visitor's decision is taken by the plugin's own notice / the JavaScript API (localStorage). DEFAULT.
	 *
	 * @since 0.6.19
	 */
	public const SOURCE_ENGAGE = 'engage';

	/**
	 * The decision is taken from the JBCookies module (cookie "jbcookies"); the plugin's own notice is never shown.
	 *
	 * @since 0.6.19
	 */
	public const SOURCE_JBCOOKIES = 'jbcookies';

	/**
	 * Returns the configured mode. A missing or unknown value is "ask", the safest option that still shows avatars.
	 *
	 * @return  string
	 * @since   0.6.17
	 */
	public function getMode(): string
	{
		$mode = $this->params->get('mode', self::MODE_ASK);

		return in_array($mode, [self::MODE_OFF, self::MODE_ASK, self::MODE_ALWAYS], true) ? $mode : self::MODE_ASK;
	}

	/**
	 * Returns where the consent decision comes from. Only "ask" mode can use an external source; with any other mode, and
	 * with a missing or unknown value, it is "engage" (the behaviour of 0.6.17 / 0.6.18, unchanged).
	 *
	 * @return  string
	 * @since   0.6.19
	 */
	public function getConsentSource(): string
	{
		if ($this->getMode() !== self::MODE_ASK)
		{
			return self::SOURCE_ENGAGE;
		}

		return $this->params->get('consent_source', self::SOURCE_ENGAGE) === self::SOURCE_JBCOOKIES
			? self::SOURCE_JBCOOKIES
			: self::SOURCE_ENGAGE;
	}

	/**
	 * Returns the JBCookies preference group which must be switched on for "Save selection" to grant consent. Strict
	 * filter: lower case a-z, 0-9, "_" and "-" (max 64). Anything else, the group "necessary" (it is always on, so it
	 * proves nothing) and names which exist on every JavaScript object give an empty string = only "Accept all" grants.
	 *
	 * @return  string
	 * @since   0.6.19
	 */
	public function getJbcookiesGroup(): string
	{
		return self::sanitizeGroup($this->params->get('jbcookies_group', ''));
	}

	/**
	 * Strict filter for the JBCookies group slug. The same rule is applied again by gravatar.js.
	 *
	 * @param   mixed  $group  The raw value
	 *
	 * @return  string  The slug, or '' if it is not acceptable
	 * @since   0.6.19
	 */
	public static function sanitizeGroup($group): string
	{
		if (!is_string($group))
		{
			return '';
		}

		$group = trim($group);

		if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $group) || in_array($group, ['necessary', '__proto__', 'constructor', 'prototype'], true))
		{
			return '';
		}

		return $group;
	}

	/**
	 * Returns the avatar image URL for a user
	 *
	 * In "always" mode the result is the Gravatar URL. In "off" and "ask" modes the result is a local image (a file of this site) so that the page never makes the browser contact a third party. In "ask" mode the Gravatar
	 * URL is also given in the "deferred" argument, indexed by the local image, for the data-engage-gravatar attribute.
	 *
	 * @param   Event  $event  The Joomla event we are handling
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function onAkeebaEngageUserAvatarURL(Event $event): void
	{
		/**
		 * @var   User $user The Joomla user object
		 * @var   int  $size The size of the avatar in pixels (avatars are square)
		 */
		[$user, $size] = array_values($event->getArguments());

		$size   = max(1, min(2048, (int) ($size ?? 48)));
		$mode   = $this->getMode();
		$result = $event->getArgument('result', []);

		if ($mode === self::MODE_ALWAYS)
		{
			$url = $this->getGravatarURL($user, $size);

			if ($url !== null)
			{
				$event->setArgument('result', array_merge($result, [$url]));
			}

			return;
		}

		$local = $this->getLocalAvatar();

		$event->setArgument('result', array_merge($result, [$local]));

		if ($mode !== self::MODE_ASK || empty(trim((string) ($user->email ?? ''))))
		{
			return;
		}

		$url = $this->getGravatarURL($user, $size);

		if ($url === null)
		{
			return;
		}

		$deferred = $event->getArgument('deferred', []);
		$external = $this->getConsentSource() === self::SOURCE_JBCOOKIES;

		$deferred[$local] = [
			'url'    => $url,
			// With JBCookies the module is the only consent UI: the plugin's own notice is never shown.
			'notice' => !$external && (int) $this->params->get('show_notice', 1) !== 0,
			'source' => $external ? self::SOURCE_JBCOOKIES : self::SOURCE_ENGAGE,
			'group'  => $external ? $this->getJbcookiesGroup() : '',
		];

		$event->setArgument('deferred', $deferred);
	}

	/**
	 * Returns the user's profile link
	 *
	 * The link is only given in "always" mode. In "ask" the server cannot know whether the visitor consented, and the
	 * link carries the e-mail hash to Gravatar, so it is never given; in "off" nothing is sent to Gravatar.
	 *
	 * @param   Event  $event  The Joomla event we are handling
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function onAkeebaEngageUserProfileURL(Event $event): void
	{
		/**
		 * @var   User $user The Joomla user object
		 */
		[$user] = array_values($event->getArguments());

		$useProfile = $this->params->get('profile_link', 1);
		$url        = null;

		if ($useProfile && $this->getMode() === self::MODE_ALWAYS)
		{
			$url = 'https://www.gravatar.com/' . $this->getEmailHash($user);
		}

		$result = $event->getArgument('result', []);
		$event->setArgument('result', array_merge($result, [$url]));
	}

	/**
	 * Builds the Gravatar avatar URL. Only the fixed Gravatar origin and whitelisted parameter values are used.
	 *
	 * @param   User  $user  The user
	 * @param   int   $size  Size in pixels
	 *
	 * @return  string|null  NULL if the resulting URL does not pass the whitelist
	 * @since   0.6.17
	 */
	private function getGravatarURL($user, int $size): ?string
	{
		$rating = strtolower((string) $this->params->get('rating', 'g'));
		$rating = in_array($rating, ['g', 'pg', 'r', 'x'], true) ? $rating : 'g';
		$url    = 'https://www.gravatar.com/avatar/' . $this->getEmailHash($user) . '?s=' . $size . '&r=' . $rating;

		$defaultImage = (string) $this->params->get('default_image', 'mp');
		$customImage  = trim((string) $this->params->get('custom_default', ''));

		if (!in_array($defaultImage, ['mp', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'blank', 'custom'], true))
		{
			$defaultImage = 'mp';
		}

		if ($defaultImage === 'custom' && empty($customImage))
		{
			$defaultImage = 'mp';
		}

		if ($defaultImage === 'custom')
		{
			$url .= '&d=' . urlencode(Uri::base(false) . $customImage);
		}
		else
		{
			$url .= '&d=' . $defaultImage;
		}

		if ($this->params->get('force_default', 0))
		{
			$url .= '&f=y';
		}

		return self::isAllowedGravatarURL($url) ? $url : null;
	}

	/**
	 * Strict whitelist for URLs which may be loaded from Gravatar: HTTPS, host www.gravatar.com, path /avatar/<hash>,
	 * no credentials, no whitespace or control characters. Also used by the JavaScript (same rule).
	 *
	 * @param   string  $url  The URL to check
	 *
	 * @return  bool
	 * @since   0.6.17
	 */
	public static function isAllowedGravatarURL(string $url): bool
	{
		return (bool) preg_match('#^https://www\.gravatar\.com/avatar/[0-9a-f]{32,64}(\?[A-Za-z0-9_=&%.+\-]*)?$#D', $url);
	}

	/**
	 * Returns the hash of the user's e-mail. It is not a secret, but it identifies the e-mail: never log it.
	 *
	 * @param   User  $user  The user
	 *
	 * @return  string
	 * @since   0.6.17
	 */
	private function getEmailHash($user): string
	{
		$email = strtolower(trim((string) ($user->email ?? '')));

		return function_exists('hash') && function_exists('hash_algos') && in_array('sha256', hash_algos())
			? hash('sha256', $email)
			: hash('md5', $email);
	}

	/**
	 * Returns the local avatar: the site's own custom default image if one is set, a transparent image for "blank",
	 * or a generic silhouette (static SVG of the component). Never an external URL.
	 *
	 * @return  string
	 * @since   0.6.17
	 */
	private function getLocalAvatar(): string
	{
		$defaultImage = (string) $this->params->get('default_image', 'mp');

		if ($defaultImage === 'custom')
		{
			// Joomla's media field stores "images/foo.png#joomlaImage://local-images/foo.png?width=1&height=1"
			$path = trim(explode('#', trim((string) $this->params->get('custom_default', '')))[0]);
			$path = ltrim($path, '/');

			if ($path !== ''
				&& strpos($path, '..') === false
				&& preg_match('#^[A-Za-z0-9_\-./]+\.(png|jpe?g|gif|webp|svg)$#i', $path))
			{
				return rtrim(Uri::root(true), '/') . '/' . $path;
			}
		}

		// Static files of the component (same origin, nothing external, no data: URI so strict CSP img-src still works).
		$file = $defaultImage === 'blank' ? 'avatar-blanco.svg' : 'avatar-generico.svg';

		return rtrim(Uri::root(true), '/') . '/media/com_engage/images/' . $file;
	}
}
