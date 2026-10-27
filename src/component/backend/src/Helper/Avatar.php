<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

use Exception;
use Throwable;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Event\Event;

defined('_JEXEC') or die;

/**
 * Helper class to get the avatar of a user
 *
 * @since 3.0.0
 */
class Avatar
{
	/**
	 * Cache of avatars per user ID. Each item is an array with keys src, deferred and notice.
	 *
	 * @var   array
	 * @since 3.0.0
	 */
	private static $avatarImages = [];

	/**
	 * 0.9.9: contexts. `page` = an interactive page seen by ONE person (the plugins of the `engage` group are asked; their photo may depend on who is looking,
	 * e.g. a photo served with the session cookie of the viewer). Any other value (`email`, `static`: emails, previews, exports, anything that is stored or sent to
	 * somebody else) NEVER asks the plugins: only sources that are public by nature (the validated user field, as an absolute address on the trusted base) or nothing.
	 */
	public const CONTEXT_PAGE = 'page';

	/** @var string[] contexts that are not interactive */
	public const STATIC_CONTEXTS = ['email', 'static'];

	/**
	 * Get the user's avatar image for a specific size
	 *
	 * @param   int|null  $user_id        User ID to get the avatar for
	 * @param   int       $size           Image width in pixels
	 * @param   null      $fallbackEmail  Fallback email address is the user does not exist
	 *
	 * @return  string
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	public static function getUserAvatar(?int $user_id, int $size = 128, $fallbackEmail = null, string $context = self::CONTEXT_PAGE): string
	{
		return self::getUserAvatarData($user_id, $size, $fallbackEmail, null, $context)['src'];
	}

	/**
	 * Get the user's avatar image plus, if the avatar provider asked for it, a third party URL which must NOT be loaded
	 * until the visitor consents (see the Gravatar plugin, mode "ask").
	 *
	 * @param   int|null  $user_id        User ID to get the avatar for
	 * @param   int       $size           Image width in pixels
	 * @param   null      $fallbackEmail  Fallback email address is the user does not exist
	 *
	 * @return  array{src: string, deferred: string, notice: bool, source: string, group: string}  src is always safe to
	 *          put in the img src; deferred is empty or a URL for the data-engage-gravatar attribute; source is "engage"
	 *          or "jbcookies" (0.6.19) and group the JBCookies group slug (or '')
	 * @throws  Exception
	 * @since   0.6.17
	 */
	public static function getUserAvatarData(?int $user_id, int $size = 128, $fallbackEmail = null, ?int $commentId = null, string $context = self::CONTEXT_PAGE): array
	{
		$data = self::resolveAvatarData($user_id, $size, $fallbackEmail, $context);

		// 0.9.13: the Gravatar «ask» mode is decided by the SERVER: only a logged in person can ever get a Gravatar address (after consenting, see AvatarController),
		// so a guest page carries no deferred reference at all (just the generic avatar, no notice). Nothing is cached per person: pages of people with a session are
		// not shared through the page cache.
		if ($data['deferred'] !== '' && !self::viewerHasSession())
		{
			$data['deferred'] = '';
		}

		// 0.9.10: a comment with no user and no e-mail (imported from another system) or with a provider that gave nothing: the generic local avatar of Engage, like
		// every other card (no request to a third party, no consent notice)
		if ($data['src'] === '')
		{
			$data['src'] = self::genericSrc($context !== self::CONTEXT_PAGE);
		}

		/**
		 * 0.8.3 (H4): the page NEVER receives the third party URL (it carries the hash of the e-mail address). `deferred` is now the opaque reference
		 * `<comment id>.<size>.<token>` (AvatarResolver) that gravatar.js trades for the real URL, through an endpoint with ACL, after the visitor consents.
		 * Without a comment ID there is no way to resolve it later, so a deferred avatar without it has no reference and simply stays the local image.
		 */
		if ($data['deferred'] !== '')
		{
			$secret = '';

			try
			{
				$secret = (string) Factory::getApplication()->get('secret', '');
			}
			catch (Exception $e)
			{
				$secret = '';
			}

			$data['deferred'] = ($commentId !== null) ? AvatarResolver::reference($secret, $commentId, $size) : '';
		}

		return $data;
	}

	/** Is the person who looks at this page logged in? (fail-closed: any doubt is «no») */
	private static function viewerHasSession(): bool
	{
		try
		{
			$user = Factory::getApplication()->getIdentity();

			return $user !== null && !$user->guest && (int) $user->id > 0;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/** The generic local avatar (`avatar-generico.svg`): a path of the site, or absolute on the trusted base for emails ('' without base). */
	public static function genericSrc(bool $absolute = false): string
	{
		$base = '';

		try
		{
			$base = \Joomla\CMS\Uri\Uri::root(true);
		}
		catch (\Throwable $e)
		{
		}

		$path = $base . '/media/com_engage/images/avatar-generico.svg';

		if (!$absolute)
		{
			return $path;
		}

		$origin = (string) (TrustedBase::current()['origin'] ?? '');

		return $origin === '' ? '' : $origin . $path;
	}

	/**
	 * The REAL deferred (third party) URL for a comment's author, for the endpoint that answers after the visitor consents. Never printed in a page.
	 *
	 * @throws  Exception
	 * @since   0.8.3
	 */
	public static function getDeferredURL(?int $user_id, int $size, $fallbackEmail = null): string
	{
		return self::resolveAvatarData($user_id, $size, $fallbackEmail)['deferred'];
	}

	/**
	 * @internal  The full data, including the third party URL: only for getUserAvatarData() and getDeferredURL().
	 */
	private static function resolveAvatarData(?int $user_id, int $size, $fallbackEmail, string $context = self::CONTEXT_PAGE): array
	{
		$empty = ['src' => '', 'deferred' => '', 'notice' => true, 'source' => 'engage', 'group' => ''];

		// 0.9.9: not interactive (email, preview, export…): no plugin is asked, nothing that depends on who looks is stored or sent
		if ($context !== self::CONTEXT_PAGE)
		{
			$id = (is_numeric($user_id) && ($user_id > 0)) ? (int) $user_id : 0;
			$key = $id . '|static';

			if ($id === 0)
			{
				return $empty;
			}

			if (!array_key_exists($key, self::$avatarImages))
			{
				$path = self::getAvatarFromCustomField($id);
				$origin = (string) (TrustedBase::current()['origin'] ?? '');
				// Without a photo in the user field: the generic avatar of Engage (public, local to the site). An empty src would be turned by Joomla's mail layer into
				// the address of the site root.
				$base = '';

				try
				{
					$base = \Joomla\CMS\Uri\Uri::root(true);
				}
				catch (\Throwable $e)
				{
				}

				self::$avatarImages[$key] = $origin === '' ? $empty : ['src' => $origin . ($path ?? ($base . '/media/com_engage/images/avatar-generico.svg'))] + $empty;
			}

			return self::$avatarImages[$key];
		}

		// Get the user and the normalised user ID.
		$user    = (is_numeric($user_id) && ($user_id > 0)) ? UserFetcher::getUser($user_id) : null;
		$user_id = is_object($user) ? $user->id : null;

		// Guest comment. Use a fake user and our plugin events. Return empty if no fallback email or plugin response.
		if (empty($user_id))
		{
			if (empty($fallbackEmail))
			{
				return $empty;
			}

			$fakeUser        = new User();
			$fakeUser->email = $fallbackEmail;

			return self::pickAvatar(self::getAvatarFromPluginEvents($fakeUser, $size));
		}

		if (array_key_exists($user_id, self::$avatarImages))
		{
			return self::$avatarImages[$user_id];
		}

		// Support custom fields
		$fromField = self::getAvatarFromCustomField($user_id);

		if (!empty($fromField))
		{
			return self::$avatarImages[$user_id] = ['src' => $fromField, 'deferred' => '', 'notice' => true, 'source' => 'engage', 'group' => ''];
		}

		// TODO Support Joomla plugin events — if Joomla ever has such an event...

		// Support our custom plugin events
		return self::$avatarImages[$user_id] = self::pickAvatar(self::getAvatarFromPluginEvents($user, $size));
	}

	/**
	 * Picks the first PHOTO (not the generic local avatar) or, without one, the first non-empty avatar from the plugin event results, with its deferred (consent-gated) URL if any.
	 *
	 * @param   array  $eventData  ['results' => string[], 'deferred' => array<string, array{url: string, notice: bool}>]
	 *
	 * @return  array{src: string, deferred: string, notice: bool, source: string, group: string}
	 * @since   0.6.17
	 */
	private static function pickAvatar(array $eventData): array
	{
		// 0.9.12: the plugin results are untrusted: only a path of the site (one «/»), an https address on the trusted base or a Gravatar address (docs/AVATARES.md)
		$origin = '';

		try
		{
			$origin = (string) (TrustedBase::current()['origin'] ?? '');
		}
		catch (\Throwable $e)
		{
		}

		$avatars = array_filter($eventData['results'] ?? [], function ($x) use ($origin) {
			return AvatarField::pluginResultOk($x, $origin);
		});

		if (empty($avatars))
		{
			return ['src' => '', 'deferred' => '', 'notice' => true, 'source' => 'engage', 'group' => ''];
		}

		// 0.9.8: a real photo (of a plugin) wins over Gravatar, and Gravatar over the generic local avatar, whatever the order of the plugins (the generic one,
		// with the Gravatar deferred URL if any, is used only when no plugin has a photo)
		$src  = null;
		$best = 3;

		foreach ($avatars as $candidate)
		{
			$rank = AvatarField::rank((string) $candidate);

			if ($rank < $best)
			{
				$best = $rank;
				$src  = $candidate;
			}
		}

		$deferred = $eventData['deferred'][$src] ?? null;

		return [
			'src'      => $src,
			'deferred' => (is_array($deferred) && is_string($deferred['url'] ?? null)) ? $deferred['url'] : '',
			'notice'   => !is_array($deferred) || !array_key_exists('notice', $deferred) || (bool) $deferred['notice'],
			// 0.6.19: where the consent decision comes from. Anything other than exactly "jbcookies" is "engage".
			'source'   => (is_array($deferred) && ($deferred['source'] ?? null) === 'jbcookies') ? 'jbcookies' : 'engage',
			'group'    => (is_array($deferred) && is_string($deferred['group'] ?? null) && preg_match('/^[a-z0-9_-]{1,64}$/D', $deferred['group'])) ? $deferred['group'] : '',
		];
	}

	/**
	 * Returns the URL for the user's profile page, empty if no profile is available
	 *
	 * @return  string  The user's profile page, empty if no profile is available
	 */
	public static function getProfileURL(User $user): string
	{
		try
		{
			PluginHelper::importPlugin('engage');

			$dispatcher = Factory::getApplication()->getDispatcher();
			$eventName  = 'onAkeebaEngageUserProfileURL';
			$event      = new Event($eventName, [$user]);
			$result     = $dispatcher->dispatch($eventName, $event);

			$results = !isset($result['result']) || \is_null($result['result']) ? [] : $result['result'];
		}
		catch (Exception $e)
		{
			$results = [];
		}

		$results = array_filter($results, function ($x) {
			return is_string($x) && !empty($x);
		});

		if (empty($results))
		{
			return '';
		}

		return array_shift($results);
	}

	/**
	 * Get the user's avatar from a custom field.
	 *
	 * The custom field is expected to render EITHER an img element OR a URL to the avatar image.
	 *
	 * @param   int|null  $user_id  The user ID to get the avatar for
	 *
	 * @return  string|null  The avatar URL (best guess!) or NULL if it is not possible
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	private static function getAvatarFromCustomField(?int $user_id): ?string
	{
		$cParams       = ComponentHelper::getParams('com_engage');
		$customFieldId = AvatarField::validId($cParams->get('customfield_avatar', ''));

		if (empty($customFieldId))
		{
			return null;
		}

		$user = UserFetcher::getUser($user_id);

		if ($user->guest)
		{
			return null;
		}

		$fields = FieldsHelper::getFields('com_users.user', $user, true);
		$fields = array_filter($fields, function (object $field) use ($customFieldId) {
			return $field->id == $customFieldId;
		});

		if (empty($fields))
		{
			return null;
		}

		$field = array_shift($fields);

		// 0.9.8: the value is written by the user itself (not trusted). Only a path of this site (or an https URL of the trusted base), only an image,
		// no «..», nothing else: otherwise there is no photo and the next method is used. The caller escapes the result for the HTML.
		$origin = (string) (TrustedBase::current()['origin'] ?? '');
		$base   = '';

		try
		{
			$base = \Joomla\CMS\Uri\Uri::root(true);
		}
		catch (\Throwable $e)
		{
		}

		$path = AvatarField::imagePath($field->value ?? '', $origin, $base);

		if ($path === '')
		{
			$path = AvatarField::imagePath($field->rawvalue ?? '', $origin, $base);
		}

		return $path !== '' ? $path : null;
	}

	/**
	 * Get the user avatar from our custom plugin events
	 *
	 * @param   User  $user  The user to get the avatar for
	 * @param   int   $size  The desired avatar maximum dimension in pixels.
	 *
	 * @return  array  ['results' => string[], 'deferred' => array]
	 * @since   3.0.0
	 */
	private static function getAvatarFromPluginEvents(User $user, int $size): array
	{
		try
		{
			PluginHelper::importPlugin('engage');

			$dispatcher = Factory::getApplication()->getDispatcher();
			$eventName  = 'onAkeebaEngageUserAvatarURL';
			$event      = new Event($eventName, [$user, $size, self::CONTEXT_PAGE]);
			$result     = $dispatcher->dispatch($eventName, $event);

			$results  = !isset($result['result']) || \is_null($result['result']) ? [] : $result['result'];
			$deferred = $result->getArgument('deferred', []);

			return [
				'results'  => \is_array($results) ? $results : [],
				'deferred' => \is_array($deferred) ? $deferred : [],
			];
		}
		catch (Exception $e)
		{
			return ['results' => [], 'deferred' => []];
		}
	}


}
