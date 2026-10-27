<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Rules for the reports of comments made by registered users (0.9.14). Pure functions: everything external (the facts about the person, the comment and the
 * counters) is passed in, so every branch is tested with fake data.
 *
 * Principles: only a logged in person with an old enough account reports; one report per person and comment; limits per hour and per day per person; a
 * global ceiling of open reports; a reason from a CLOSED list and an optional short note; a report NEVER hides, unpublishes or deletes a comment by itself
 * (it only flags it for the moderators).
 */
final class ReportRules
{
	/** Reason codes (closed list). */
	public const REASONS = [1 => 'spam', 2 => 'offensive', 3 => 'personal', 4 => 'other'];

	public const STATUS_OPEN      = 0;
	public const STATUS_DISMISSED = 1;
	public const STATUS_HANDLED   = 2;

	public const NOTE_MAX = 200;

	/** [min, max, default] of each administrator option. The maximums cannot be exceeded from any form. */
	public const LIMITS = [
		'reports_per_hour'    => [1, 20, 5],
		'reports_per_day'     => [1, 100, 20],
		'reports_min_account' => [0, 720, 24],   // hours
		'reports_open_max'    => [50, 5000, 500],
	];

	/** An integer option clamped to its closed range; anything that is not a plain integer gives the default. */
	public static function limit(string $key, $raw): int
	{
		[$min, $max, $def] = self::LIMITS[$key] ?? [0, 0, 0];

		if (is_int($raw))
		{
			$v = $raw;
		}
		elseif (is_string($raw) && preg_match('/^\s*[0-9]{1,6}\s*$/', $raw) === 1)
		{
			$v = (int) trim($raw);
		}
		else
		{
			return $def;
		}

		return ($v >= $min && $v <= $max) ? $v : $def;
	}

	/** The reason code of the form field, or 0 if it is not one of the list. */
	public static function reason($raw): int
	{
		if (is_int($raw))
		{
			$v = $raw;
		}
		elseif (is_string($raw) && preg_match('/^[1-9]$/D', $raw) === 1)
		{
			$v = (int) $raw;
		}
		else
		{
			return 0;
		}

		return isset(self::REASONS[$v]) ? $v : 0;
	}

	/** The optional note: plain text, no tags, no control characters, collapsed spaces, at most NOTE_MAX characters. */
	public static function cleanNote($raw): string
	{
		if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8'))
		{
			return '';
		}

		$t = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
		$t = strip_tags($t);
		$t = (string) preg_replace('/\s+/u', ' ', $t);
		$t = trim($t);

		return mb_substr($t, 0, self::NOTE_MAX, 'UTF-8');
	}

	/**
	 * Decides whether a report may be stored. The order is the order of the checks; the first failure is returned.
	 *
	 * @param   array  $f  enabled(bool), uid(int), accountAgeHours(int), commentFound(bool), commentVisible(bool: published, chain published, content viewable),
	 *                     ownComment(bool), reason(int), perHour(int: reports made by this person in the last hour), perDay(int), open(int: open reports in
	 *                     total), already(bool), limits(array of the four options)
	 *
	 * @return  string  'ok' or: disabled, login, young, notfound, own, reason, hour, day, full, already
	 */
	public static function decide(array $f): string
	{
		$lim = (array) ($f['limits'] ?? []);

		if (empty($f['enabled']))
		{
			return 'disabled';
		}

		if ((int) ($f['uid'] ?? 0) <= 0)
		{
			return 'login';
		}

		if ((int) ($f['accountAgeHours'] ?? 0) < self::limit('reports_min_account', $lim['reports_min_account'] ?? null))
		{
			return 'young';
		}

		// Not found and not visible are the same answer: nobody learns which comments exist
		if (empty($f['commentFound']) || empty($f['commentVisible']))
		{
			return 'notfound';
		}

		if (!empty($f['ownComment']))
		{
			return 'own';
		}

		if (self::reason($f['reason'] ?? 0) === 0)
		{
			return 'reason';
		}

		if (!empty($f['already']))
		{
			return 'already';
		}

		if ((int) ($f['perHour'] ?? 0) >= self::limit('reports_per_hour', $lim['reports_per_hour'] ?? null))
		{
			return 'hour';
		}

		if ((int) ($f['perDay'] ?? 0) >= self::limit('reports_per_day', $lim['reports_per_day'] ?? null))
		{
			return 'day';
		}

		if ((int) ($f['open'] ?? 0) >= self::limit('reports_open_max', $lim['reports_open_max'] ?? null))
		{
			return 'full';
		}

		return 'ok';
	}
}
