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
use Throwable;

/**
 * Form filter for the options that need `core.admin` (0.9.1; SettingsSchema::ADMIN_ONLY).
 *
 * Joomla's options form (com_config) and its web services API only ask for `core.admin` OR `core.options`. The fields of config.xml that are sensitive
 * name this class as their filter (`filter="…\AdminOnlyFilter::<field name>"`; the field name arrives as the static method name). Joomla calls it with the
 * value that was posted: for anyone who is not a super administrator of Engage (core.admin) the STORED value is returned instead, so the change is
 * ignored; for the fields that no form may write (`email_base_learned`, `email_base_audit`) the stored value is always returned. For core.admin the
 * base URL is validated like in the modern panel. Fail-closed: any error keeps the stored value.
 *
 * @since 0.9.1
 */
final class AdminOnlyFilter
{
	/** Test hook: [bool $isAdmin, array $stored (key => value), array $posted (key => value)] replaces what is read from Joomla. */
	public static $override = null;

	public static function __callStatic(string $key, array $args)
	{
		$value = is_scalar($args[0] ?? null) ? (string) $args[0] : '';

		try
		{
			[$isAdmin, $stored, $posted] = self::context();

			$keep = array_key_exists($key, $stored) ? (string) $stored[$key] : self::defaultOf($key);

			if (in_array($key, SettingsSchema::NEVER_FORM, true) && $key !== 'email_base_audit')
			{
				return $keep;
			}

			if ($key === 'email_base_audit')
			{
				return self::audit($isAdmin, $stored, $posted, $keep);
			}

			if (!$isAdmin)
			{
				if ($value !== $keep)
				{
					self::deny($key);
				}

				return $keep;
			}

			if ($key === 'email_base_url')
			{
				return TrustedBase::validateOption($value);
			}

			if ($key === 'captcha')
			{
				// the filter that config.xml had before (cmd): letters, digits, dots, dashes and underscores
				return (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', $value);
			}

			return $value;
		}
		catch (Throwable $e)
		{
			try
			{
				return (string) ((self::context())[1][$key] ?? self::defaultOf($key));
			}
			catch (Throwable $e2)
			{
				return '';
			}
		}
	}

	/**
	 * May this submission leave the option as it is stored? (validation rule, see AdminonlyRule). Anyone: a value equal to the stored one (or to the
	 * default while nothing is stored). Only core.admin: a different value, except for the options that no form writes (`email_base_learned`,
	 * `email_base_audit`: those must come back unchanged, the audit note being the one Engage's own filter rewrites for core.admin).
	 *
	 * @param   mixed  $posted  The value that arrived (null = missing)
	 */
	public static function mayChange(string $key, $posted, string $default): bool
	{
		try
		{
			[$isAdmin, $stored] = self::context();
			$posted = $posted === null ? '' : (is_scalar($posted) ? (string) $posted : "\0invalid");
			$keep   = array_key_exists($key, $stored) ? (string) $stored[$key] : $default;

			if ($posted === $keep || ($default !== '' && $posted === '' && !array_key_exists($key, $stored)))
			{
				return true;
			}

			if ($isAdmin && $key !== 'email_base_learned')
			{
				return true;
			}

			self::deny($key);

			return false;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/** @return array{0:bool,1:array,2:array} */
	private static function context(): array
	{
		if (is_array(self::$override))
		{
			return [(bool) self::$override[0], (array) self::$override[1], (array) self::$override[2]];
		}

		$app    = Factory::getApplication();
		$user   = $app->getIdentity();
		$stored = ComponentHelper::getParams('com_engage')->toArray();
		$jform  = $app->getInput()->post->get('jform', [], 'array');
		$posted = is_array($jform) ? (is_array($jform['params'] ?? null) ? $jform['params'] : $jform) : [];

		return [$user && !$user->guest && $user->authorise('core.admin', 'com_engage'), $stored, $posted];
	}

	/** The default of the field in config.xml (what applies while nothing is stored). */
	private static function defaultOf(string $key): string
	{
		try
		{
			$xml = dirname(__DIR__, 2) . '/config.xml';

			return is_file($xml) ? (string) (SettingsSchema::parse((string) file_get_contents($xml), 'com_engage')['fields'][$key]['default'] ?? '') : '';
		}
		catch (Throwable $e)
		{
			return '';
		}
	}

	/** The note of the dashboard: who changed the base URL, when, from what to what. Only changes that are going to be stored. */
	private static function audit(bool $isAdmin, array $stored, array $posted, string $keep): string
	{
		if (!$isAdmin || !array_key_exists('email_base_url', $posted))
		{
			return $keep;
		}

		try
		{
			$new = TrustedBase::validateOption((string) $posted['email_base_url']);
		}
		catch (Throwable $e)
		{
			return $keep;
		}

		$old = (string) ($stored['email_base_url'] ?? '');

		if ($new === $old)
		{
			return $keep;
		}

		$user = is_array(self::$override) ? null : Factory::getApplication()->getIdentity();

		if ($user === null)
		{
			return (string) json_encode(['u' => 'test', 'i' => 1, 't' => gmdate('Y-m-d H:i'), 'o' => SettingsStorage::safeValue($old), 'n' => SettingsStorage::safeValue($new)]);
		}

		SettingsStorage::auditChange($user, 'com_engage', 'email_base_url', $old, $new, null);

		return SettingsStorage::auditRecord($user, $old, $new);
	}

	/** A change that was ignored: it is logged. */
	private static function deny(string $key): void
	{
		if (is_array(self::$override))
		{
			return;
		}

		try
		{
			$user = Factory::getApplication()->getIdentity();
			\Joomla\CMS\Log\Log::add(sprintf('Change of setting com_engage/%s ignored: user #%d has core.options but not core.admin', $key, (int) ($user->id ?? 0)), \Joomla\CMS\Log\Log::WARNING, 'com_engage');
		}
		catch (Throwable $e)
		{
		}
	}
}
