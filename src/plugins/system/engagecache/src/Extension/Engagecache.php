<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Plugin\System\EngageCache\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Workaround for paginated frontend comments display to guest users when caching is enabled.
 */
class Engagecache extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Disallow registering legacy listeners since we use SubscriberInterface
	 *
	 * @var   bool
	 * @since 3.0.0
	 */
	protected $allowLegacyListeners = false;

	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterRoute'   => 'onAfterRoute',
			'onBeforeRender' => 'onBeforeRender',
		];
	}

	/**
	 * Fixes the frontend display of comments for guests when caching is enabled.
	 *
	 * Joomla caches the entire article contents, including the plugin output, for guest users. The problem is that
	 * while this takes into account Joomla's pagination it does not take into account the comment pagination.
	 *
	 * Fortunately, BaseController::display does take into account a stdClass object named registeredurlparams if it's
	 * already set in the Joomla application object. This property does not exist in the base CMSApplication class and
	 * you'd be hard pressed to know it's a thing just by reading Joomla's developer documentation. Anyway, it is used
	 * if it's there so we prime it with the contents of our pagination parameters. This forces Joomla to take into
	 * account BOTH the active component's (e.g. com_content) caching parameters AND our comment pagination parameters.
	 *
	 * @param   Event  $event  The event we are handling
	 *
	 * @return  void
	 *
	 * @see     BaseController::display()
	 */
	public function onAfterRoute(Event $event)
	{
		$app = $this->getApplication();

		if (!$app->isClient('site'))
		{
			return;
		}

		if ($app->input->getCmd('option') !== 'com_content')
		{
			return;
		}

		if (!empty($app->registeredurlparams))
		{
			$registeredurlparams = $app->registeredurlparams;
		}
		else
		{
			$registeredurlparams = new \stdClass();
		}

		// 0.8.1: the cache key must not grow with whatever a visitor types. Before the parameters become part of the key, every value
		// that is not allowed is removed from the request (the page is then the default one) and, for a GET, the visitor is sent to the
		// same URL without them: no cache entry (of the page cache, whose key is the whole URL, or of the conservative cache) is created
		// for an invented value, and a page rendered for one never reaches another visitor.
		$dropped = $this->normalizeRequest($app->getInput());

		if ($dropped && in_array(strtoupper((string) $app->getInput()->getMethod()), ['GET', 'HEAD'], true))
		{
			try
			{
				$uri = Uri::getInstance();

				foreach ($dropped as $name)
				{
					$uri->delVar($name);
				}

				// The query is built again with every value percent-encoded (nothing of the request goes into the header as it came)
				$path  = (string) $uri->getPath();
				$query = http_build_query((array) $uri->getQuery(true), '', '&', PHP_QUERY_RFC3986);
				$target = $path . ($query !== '' ? '?' . $query : '');

				// Only a path of this site: one single slash at the start and printable characters (never `//host` or `\\host`)
				if (preg_match('~^/(?![/\\\\])[\x21-\x7E]*$~D', $target) === 1 && strpbrk($path, "\"'<>`") === false)
				{
					$app->redirect($target, 301);

					return;
				}
			}
			catch (\Throwable $e)
			{
				if ($e instanceof \Joomla\CMS\Application\ExitException || stripos(get_class($e), 'Exit') !== false)
				{
					throw $e;
				}

				// Without the redirect the request is still served as the default page
			}
		}

		$registeredurlparams->akengage_limitstart = 'INT';
		$registeredurlparams->akengage_limit      = 'INT';
		$registeredurlparams->akengage_cid        = 'INT';
		// 0.8.0: sort selector and "only my favourites" are part of the cache key (a sorted list is not the default one)
		$registeredurlparams->akengage_sort       = 'CMD';
		$registeredurlparams->akengage_fav        = 'CMD';

		$app->registeredurlparams = $registeredurlparams;
	}

	/** Closed lists of the values that this fork produces in its own links. */
	private const SORT_VALUES  = ['newest', 'oldest', 'top'];
	private const LIMIT_VALUES = [5, 10, 15, 20, 25, 30, 50, 100, 200, 500];
	private const MAX_START    = 10000;

	/**
	 * Keeps only the values of our own URL parameters that the component itself can produce, so that the cache (whose key includes
	 * them) cannot be filled with entries created by arbitrary values (0.8.1). A value that is not allowed is removed from the
	 * request: the component behaves as if it had not been sent.
	 *
	 * - akengage_sort: newest, oldest or top. akengage_fav: 1.
	 * - akengage_limit: one of the page sizes of Joomla's pagination (or the default size of the component).
	 * - akengage_limitstart: a multiple of the page size, up to 10000.
	 * - akengage_cid: the ID of a comment that exists.
	 *
	 * @param   \Joomla\Input\Input  $input
	 *
	 * @return  string[]  Names of the parameters that were removed
	 */
	private function normalizeRequest($input): array
	{
		$dropped = [];
		$drop    = static function (string $name) use ($input, &$dropped): void {
			$dropped[] = $name;
			$input->set($name, null);

			try
			{
				$input->get->set($name, null);
			}
			catch (\Throwable $e)
			{
				// Only the main input counts for the cache key
			}
		};

		$sort = $input->get('akengage_sort', null, 'raw');

		if ($sort !== null && !(is_string($sort) && in_array($sort, self::SORT_VALUES, true)))
		{
			$drop('akengage_sort');
		}

		$fav = $input->get('akengage_fav', null, 'raw');

		if ($fav !== null && $fav !== '1')
		{
			$drop('akengage_fav');
		}

		$defaultLimit = 0;

		try
		{
			$defaultLimit = (int) ComponentHelper::getParams('com_engage')->get('default_limit', 0);
		}
		catch (\Throwable $e)
		{
			$defaultLimit = 0;
		}

		$limit = $input->get('akengage_limit', null, 'raw');

		if ($limit !== null)
		{
			$ok = is_string($limit) && preg_match('/^[0-9]{1,3}$/D', $limit) === 1
				&& (in_array((int) $limit, self::LIMIT_VALUES, true) || ((int) $limit === $defaultLimit && $defaultLimit > 0));

			if (!$ok)
			{
				$drop('akengage_limit');
				$limit = null;
			}
		}

		$start = $input->get('akengage_limitstart', null, 'raw');

		if ($start !== null)
		{
			$size = ($limit !== null) ? (int) $limit : ($defaultLimit > 0 ? $defaultLimit : (int) Factory::getApplication()->get('list_limit', 20));
			$ok   = is_string($start) && preg_match('/^[0-9]{1,5}$/D', $start) === 1 && ((int) $start <= self::MAX_START)
				&& ($size <= 0 || ((int) $start % $size) === 0);

			if (!$ok)
			{
				$drop('akengage_limitstart');
			}
		}

		$cid = $input->get('akengage_cid', null, 'raw');

		if ($cid !== null && !$this->commentExists($cid))
		{
			$drop('akengage_cid');
		}

		return array_values(array_unique($dropped));
	}

	/** Does a comment with this ID exist? (one lookup by primary key, only when akengage_cid is in the URL) */
	private function commentExists($cid): bool
	{
		if (!is_string($cid) || preg_match('/^[0-9]{1,10}$/D', $cid) !== 1 || (int) $cid <= 0)
		{
			return false;
		}

		try
		{
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$id    = (int) $cid;
			$query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select('1')
				->from($db->quoteName('#__engage_comments'))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $id, ParameterType::INTEGER);

			return (bool) $db->setQuery($query, 0, 1)->loadResult();
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Fixes some perplexing behaviour in Joomla.
	 *
	 * When caching is enabled Joomla will cache the JavaScript we told it load (good!) and its script options (great!),
	 * but not… the language strings. Which are used by the JavaScript code.
	 *
	 *
	 * @param   Event  $event
	 */
	public function onBeforeRender(Event $event)
	{
		$app = $this->getApplication();

		if (!$app->isClient('site'))
		{
			return;
		}

		// When caching is enabled Joomla does not call the events which allow for these lang strings to be included.
		$language = $this->getApplication()->getLanguage();
		$language->load('com_engage', JPATH_SITE);

		Text::script('COM_ENGAGE_COMMENTS_FORM_BTN_SUBMIT_PLEASE_WAIT');
		Text::script('COM_ENGAGE_COMMENTS_DELETE_PROMPT');
		// 0.8.0: texts of the copy-link notice (tools.js)
		Text::script('COM_ENGAGE_TOOLS_COPY_OK');
		Text::script('COM_ENGAGE_TOOLS_COPY_FAIL');
		// Texts of the Gravatar consent notice; only when that plugin is enabled (no change for other sites).
		if (PluginHelper::isEnabled('engage', 'gravatar'))
		{
			Text::script('COM_ENGAGE_GRAVATAR_NOTICE_TEXT');
			Text::script('COM_ENGAGE_GRAVATAR_BTN_ACCEPT');
			Text::script('COM_ENGAGE_GRAVATAR_BTN_REVOKE');
			Text::script('COM_ENGAGE_GRAVATAR_STATUS_ON');
			Text::script('COM_ENGAGE_GRAVATAR_NOTICE_LABEL');
		}
	}
}
