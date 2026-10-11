<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Throwable;

/**
 * Consent-gated Gravatar photos without putting the e-mail hash in the page (0.8.3, H4 of the independent review of 0.8.2).
 *
 * Until 0.8.2, in "ask" mode the PUBLIC HTML carried `data-engage-gravatar="https://www.gravatar.com/avatar/<sha256(e-mail)>?..."` for every commenter,
 * BEFORE any consent: the hash of the e-mail address of each commenter (also guests) was published to everybody, scrapers included, and a hash of an
 * address can be tested against a list of addresses. Now the HTML only carries an OPAQUE reference `<comment id>.<size>.<token>`, where the token is an
 * HMAC of the comment ID and the size with the secret of the site: it contains nothing of the address, nobody can compute it without the secret and it
 * proves nothing to a third party. After the visitor consents, the script asks THIS endpoint (POST + CSRF token) for the real URL of the comments it
 * shows. The endpoint answers only for comments that the visitor could see in the page (published, whole chain of parents published, content viewable),
 * with the same answer (null) for comments that do not exist, are not visible or carry a wrong token, and it is rate limited.
 *
 * Pure logic: everything external (store, permissions, secret, rate limit, URL source) is injected, so the tests run every branch with fake data.
 *
 * @since 0.8.3
 */
final class AvatarResolver
{
	/** References per request (a page shows at most 100 comments per request of the reactions endpoint too). */
	public const MAX_ITEMS = 100;

	/** The only URLs that may ever be returned (the same rule as Gravatar::isAllowedGravatarURL and gravatar.js). */
	public const URL_PATTERN = '#^https://www\.gravatar\.com/avatar/[0-9a-f]{32,64}(\?[A-Za-z0-9_=&%.+\-]*)?$#D';

	public static function token(string $secret, int $commentId, int $size): string
	{
		return substr(hash_hmac('sha256', 'engage-gravatar-v1|' . $commentId . '|' . $size, $secret), 0, 32);
	}

	/** The reference printed in the HTML (`data-engage-gravatar`). Empty if there is no secret or the ID or size are not valid. */
	public static function reference(string $secret, int $commentId, int $size): string
	{
		if ($secret === '' || $commentId <= 0 || $size < 1 || $size > 2048)
		{
			return '';
		}

		return $commentId . '.' . $size . '.' . self::token($secret, $commentId, $size);
	}

	/**
	 * Parses the `c` field: references separated by commas. NULL if anything is malformed or there are too many (the whole request is refused).
	 *
	 * @return  array<string, array{0:int,1:int,2:string}>|null  "id.size" => [id, size, token]
	 */
	public static function parse($raw): ?array
	{
		if (!is_string($raw) || $raw === '' || strlen($raw) > 6000)
		{
			return null;
		}

		$out = [];

		foreach (explode(',', $raw) as $part)
		{
			if (preg_match('/^([1-9][0-9]{0,17})\.([1-9][0-9]{0,3})\.([0-9a-f]{32})$/D', $part, $m) !== 1 || (int) $m[2] > 2048)
			{
				return null;
			}

			$out[$m[1] . '.' . $m[2]] = [(int) $m[1], (int) $m[2], $m[3]];
		}

		return (count($out) > self::MAX_ITEMS) ? null : $out;
	}

	/**
	 * @param   array  $ctx  store (ReactionStoreInterface), secret (string), canViewAsset (callable(int):bool), allowRead (callable():bool|null),
	 *                       urlFor (callable(array $commentRow, int $size):string)
	 *
	 * @return  array{status:int, body:array}
	 */
	public function resolve(array $ctx, $raw): array
	{
		$refs = self::parse($raw);

		if ($refs === null)
		{
			return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid']];
		}

		if (isset($ctx['allowRead']) && !($ctx['allowRead'])())
		{
			return ['status' => 429, 'body' => ['ok' => false, 'error' => 'rate']];
		}

		$secret = (string) ($ctx['secret'] ?? '');
		$urls   = [];

		foreach (array_keys($refs) as $k)
		{
			$urls[$k] = null;
		}

		if ($secret === '')
		{
			return ['status' => 200, 'body' => ['ok' => true, 'urls' => (object) $urls]];
		}

		try
		{
			$ids      = array_values(array_unique(array_map(static fn(array $r): int => $r[0], $refs)));
			$store    = $ctx['store'];
			$comments = $store->comments($ids);

			// A page shows the comments of ONE content item: a request that mixes more than MAX_ASSETS is refused whole (each check costs queries)
			if (count(array_unique(array_map(static fn(array $c): int => (int) $c['asset_id'], $comments))) > Reactions::MAX_ASSETS)
			{
				return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid']];
			}

			// Only the comments with a right token are worth looking at (the others get the same null as a comment that does not exist)
			$valid = [];

			foreach ($refs as $k => [$id, $size, $token])
			{
				if (hash_equals(self::token($secret, $id, $size), $token) && isset($comments[$id]))
				{
					$valid[$k] = [$id, $size];
				}
			}

			if ($valid)
			{
				$chain  = (new Reactions($store))->chainVisibility($comments);
				$assets = [];

				foreach ($valid as $k => [$id, $size])
				{
					$c = $comments[$id];

					if ((int) $c['enabled'] !== 1 || empty($chain[$id]))
					{
						continue;
					}

					$a          = (int) $c['asset_id'];
					$assets[$a] = $assets[$a] ?? (bool) ($ctx['canViewAsset'])($a);

					if (!$assets[$a])
					{
						continue;
					}

					$url = (string) ($ctx['urlFor'])($c, $size);

					if ($url !== '' && preg_match(self::URL_PATTERN, $url) === 1)
					{
						$urls[$k] = $url;
					}
				}
			}
		}
		catch (Throwable $e)
		{
			return ['status' => 500, 'body' => ['ok' => false, 'error' => 'failed']];
		}

		return ['status' => 200, 'body' => ['ok' => true, 'urls' => (object) $urls]];
	}
}
