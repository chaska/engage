<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\Database\ParameterType;
use Throwable;

/**
 * Permissions matrix (0.9.16): a simple view of Joomla's NATIVE permissions on the `com_engage` asset (access.xml), groups in rows (indented as the
 * group tree) and six actions in columns. The source of truth is Joomla (Access / Rules); this class only reads, classifies and, when told, merges
 * explicit «Allow» entries into the rules of the component asset. The pure parts (classification and merge) take everything from outside, so every branch is
 * tested with fake data; the glue to Joomla is at the end.
 *
 * States of a cell: `always` (Super Users: Joomla grants everything, cannot be changed), `allow` (explicit Allow to THIS group), `inherit` (allowed because a
 * group above it, or a parent asset, allows it), `unset` (not assigned: cannot), `deny` (denied on purpose, here or in a group above, or an Allow that a
 * Deny overrides: only changed in Joomla's advanced permissions).
 *
 * NEVER touched from here: `core.admin`, `core.options`, any explicit Deny, the Super Users group, any group that does not exist.
 */
final class PermissionsMatrix
{
	/** The closed list of actions (columns): action => short key for the language strings. */
	public const ACTIONS = [
		'core.create'     => 'CREATE',
		'core.edit.own'   => 'EDITOWN',
		'core.edit'       => 'EDIT',
		'core.edit.state' => 'STATE',
		'core.delete'     => 'DELETE',
		'core.manage'     => 'MANAGE',
	];

	/** Granting any of these to a broad group (Public, Guest, Registered, the group of new users) needs an explicit confirmation. */
	public const SENSITIVE = ['core.edit', 'core.edit.state', 'core.delete', 'core.manage'];

	/** Most changes accepted in one save. */
	public const MAX_CHANGES = 600;

	public const STATES = ['always', 'allow', 'inherit', 'unset', 'deny'];

	/**
	 * Classifies every cell.
	 *
	 * @param   array<int,array{id:int,parent:int,title:string,depth:int}>  $groups     in tree order
	 * @param   array<string,array<int,int>>                                $explicit   action => [group id => 1 (Allow) | 0 (Deny)] of the component asset
	 * @param   callable                                                    $effective  fn(int $groupId, string $action): bool|null  Joomla's decision (Access::checkGroup)
	 * @param   callable                                                    $isSuper    fn(int $groupId): bool
	 *
	 * @return  array<int,array<string,string>>  group id => action => state
	 */
	public static function cells(array $groups, array $explicit, callable $effective, callable $isSuper): array
	{
		$parent = [];

		foreach ($groups as $g)
		{
			$parent[(int) $g['id']] = (int) $g['parent'];
		}

		$out = [];

		foreach ($groups as $g)
		{
			$gid = (int) $g['id'];

			foreach (array_keys(self::ACTIONS) as $action)
			{
				if ($isSuper($gid))
				{
					$out[$gid][$action] = 'always';
					continue;
				}

				$exp = $explicit[$action][$gid] ?? null;
				$eff = (bool) $effective($gid, $action);

				// A Deny on this group or on any group above it, at this asset
				$denied = ($exp === 0);

				for ($p = $parent[$gid] ?? 0, $n = 0; !$denied && $p > 0 && $n < 64; $p = $parent[$p] ?? 0, $n++)
				{
					if (($explicit[$action][$p] ?? null) === 0)
					{
						$denied = true;
					}
				}

				if ($denied)
				{
					$out[$gid][$action] = 'deny';
				}
				elseif ($eff)
				{
					$out[$gid][$action] = ($exp === 1) ? 'allow' : 'inherit';
				}
				else
				{
					// An explicit Allow that is still not enough means that something else (a Deny in a parent asset) wins
					$out[$gid][$action] = ($exp === 1) ? 'deny' : 'unset';
				}
			}
		}

		return $out;
	}

	/** Does the state mean «allowed»? */
	public static function isAllowed(string $state): bool
	{
		return in_array($state, ['always', 'allow', 'inherit'], true);
	}

	/**
	 * Reads and validates the list of changes sent by the browser: a JSON array of {g: group id, a: action, v: 1|0}. Anything else is dropped (never trusted).
	 *
	 * @return  array<int,array{0:int,1:string,2:int}>|null  null if the whole payload is unusable
	 */
	public static function parseChanges($raw): ?array
	{
		if (!is_string($raw) || $raw === '' || strlen($raw) > 100000)
		{
			return null;
		}

		$data = json_decode($raw, true, 8);

		if (!is_array($data) || !array_is_list($data) || count($data) > self::MAX_CHANGES)
		{
			return null;
		}

		$out = [];

		foreach ($data as $c)
		{
			if (!is_array($c) || !isset($c['g'], $c['a'], $c['v']))
			{
				continue;
			}

			$g = $c['g'];
			$v = $c['v'];

			if (!((is_int($g) && $g > 0) || (is_string($g) && preg_match('/^[1-9][0-9]{0,9}$/D', $g) === 1)) || !is_string($c['a']) || !isset(self::ACTIONS[$c['a']]) || !(($v === 0 || $v === 1) || $v === '0' || $v === '1'))
			{
				continue;
			}

			$out[(int) $g . '|' . $c['a']] = [(int) $g, $c['a'], (int) $v];
		}

		return array_values($out);
	}

	/**
	 * Merges the requested changes into the explicit rules. Allow entries are added or removed; a Deny is never touched; Super Users and groups that do not
	 * exist are ignored; the actions that are not in the closed list (core.admin, core.options…) are never touched.
	 *
	 * @param   array<string,array<int,int>>  $explicit      rules of the component asset
	 * @param   array                         $changes       from parseChanges()
	 * @param   int[]                         $validGroups   ids that exist
	 * @param   int[]                         $superGroups   ids that cannot be changed
	 * @param   int[]                         $broadGroups   ids that need confirmation for the sensitive actions
	 *
	 * @return  array{rules: array, applied: array, needsConfirm: bool, ignored: int}
	 */
	public static function merge(array $explicit, array $changes, array $validGroups, array $superGroups, array $broadGroups): array
	{
		$valid   = array_flip(array_map('intval', $validGroups));
		$super   = array_flip(array_map('intval', $superGroups));
		$broad   = array_flip(array_map('intval', $broadGroups));
		$applied = [];
		$ignored = 0;
		$confirm = false;

		foreach ($changes as [$gid, $action, $want])
		{
			if (!isset($valid[$gid]) || isset($super[$gid]) || !isset(self::ACTIONS[$action]))
			{
				$ignored++;
				continue;
			}

			$cur = $explicit[$action][$gid] ?? null;

			if ($cur === 0)
			{
				$ignored++; // a Deny stays
				continue;
			}

			if ($want === 1 && $cur !== 1)
			{
				$explicit[$action][$gid] = 1;
				$applied[]               = [$gid, $action, '+'];

				if (isset($broad[$gid]) && in_array($action, self::SENSITIVE, true))
				{
					$confirm = true;
				}
			}
			elseif ($want === 0 && $cur === 1)
			{
				unset($explicit[$action][$gid]);
				$applied[] = [$gid, $action, '-'];
			}
		}

		return ['rules' => $explicit, 'applied' => $applied, 'needsConfirm' => $confirm, 'ignored' => $ignored];
	}

	/** Short, personal-data-free summary for the action log: «Registered: +core.create; Manager: -core.delete». */
	public static function summary(array $applied, array $titles): string
	{
		$parts = [];

		foreach ($applied as [$gid, $action, $sign])
		{
			$t       = preg_replace('/[^\p{L}\p{N} _.\-()]/u', '', (string) ($titles[$gid] ?? ('#' . $gid)));
			$parts[] = $t . ': ' . $sign . $action;
		}

		return mb_substr(implode('; ', $parts), 0, 600);
	}

	// ----------------------------------------------------------------------------------------------------------------------------- Joomla glue

	/** @return array<int,array{id:int,parent:int,title:string,depth:int}> */
	public static function loadGroups($db): array
	{
		$q = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->select($db->quoteName(['id', 'parent_id', 'title']))
			->from($db->quoteName('#__usergroups'))
			->order($db->quoteName('lft') . ' ASC');
		$rows   = $db->setQuery($q)->loadAssocList() ?: [];
		$parent = [];

		foreach ($rows as $r)
		{
			$parent[(int) $r['id']] = (int) $r['parent_id'];
		}

		$out = [];

		foreach ($rows as $r)
		{
			$depth = 0;

			for ($p = (int) $r['parent_id']; $p > 0 && $depth < 32; $p = $parent[$p] ?? 0)
			{
				$depth++;
			}

			$out[] = ['id' => (int) $r['id'], 'parent' => (int) $r['parent_id'], 'title' => (string) $r['title'], 'depth' => $depth];
		}

		return $out;
	}

	/** Explicit rules of the component asset as action => [group => 1|0]; only the closed list of actions is returned. */
	public static function loadExplicit($db): array
	{
		return self::filterRules(self::loadRulesJson($db));
	}

	public static function loadRulesJson($db): array
	{
		$name = 'com_engage';
		$q    = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
			->select($db->quoteName('rules'))->from($db->quoteName('#__assets'))->where($db->quoteName('name') . ' = :n')->bind(':n', $name, ParameterType::STRING);
		$j = json_decode((string) $db->setQuery($q)->loadResult(), true);

		return is_array($j) ? $j : [];
	}

	/** Keeps the closed list of actions, with group ids and 0/1 values only. */
	public static function filterRules(array $rules): array
	{
		$out = [];

		foreach (array_keys(self::ACTIONS) as $a)
		{
			foreach ((array) ($rules[$a] ?? []) as $g => $v)
			{
				if ((int) $g > 0 && ($v === 0 || $v === 1 || $v === '0' || $v === '1'))
				{
					$out[$a][(int) $g] = (int) $v;
				}
			}
		}

		return $out;
	}

	/**
	 * Writes the new Allow entries into the rules of the component asset with Joomla's own Asset table and Rules class. Everything that is not one of the six
	 * actions (core.admin, core.options and any other) is carried over exactly as it was.
	 */
	public static function store(array $newExplicit): bool
	{
		$asset = \Joomla\CMS\Table\Table::getInstance('Asset');

		if (!$asset || !$asset->loadByName('com_engage'))
		{
			return false;
		}

		$all = json_decode((string) $asset->rules, true);
		$all = is_array($all) ? $all : [];

		foreach (array_keys(self::ACTIONS) as $a)
		{
			$rule = [];

			foreach ((array) ($newExplicit[$a] ?? []) as $g => $v)
			{
				$rule[(string) (int) $g] = (int) $v;
			}

			if ($rule === [])
			{
				unset($all[$a]);
			}
			else
			{
				ksort($rule);
				$all[$a] = $rule;
			}
		}

		$asset->rules = (string) new \Joomla\CMS\Access\Rules(json_encode($all, JSON_FORCE_OBJECT));

		if (!$asset->check() || !$asset->store())
		{
			return false;
		}

		try
		{
			\Joomla\CMS\Access\Access::clearStatics();
			\Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => '_system'])->clean();
		}
		catch (Throwable $e)
		{
			// the rules are stored; a stale cache only lasts until it expires
		}

		return true;
	}

	/** Groups that need confirmation: the root (Public), Guest and Registered, plus the group Joomla gives to new and guest users. */
	public static function broadGroups(array $groups): array
	{
		$ids = [];

		foreach ($groups as $g)
		{
			if ($g['parent'] === 0 || $g['id'] === 2 || $g['id'] === 9)
			{
				$ids[] = $g['id'];
			}
		}

		try
		{
			$p = \Joomla\CMS\Component\ComponentHelper::getParams('com_users');

			foreach (['guest_usergroup', 'new_usertype'] as $k)
			{
				if ((int) $p->get($k, 0) > 0)
				{
					$ids[] = (int) $p->get($k);
				}
			}
		}
		catch (Throwable $e)
		{
		}

		return array_values(array_unique($ids));
	}

	/** Decision of Joomla for every group (the native source of truth). */
	public static function joomlaCells(array $groups, array $explicit): array
	{
		return self::cells(
			$groups,
			$explicit,
			static fn(int $g, string $a): bool => \Joomla\CMS\Access\Access::checkGroup($g, $a, 'com_engage') === true,
			static fn(int $g): bool => \Joomla\CMS\Access\Access::checkGroup($g, 'core.admin') === true
		);
	}
}
