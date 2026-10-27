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
 * Database access of the reports (0.9.14). Every query is bound; nothing here decides policy (see ReportRules) or permissions (the controllers do).
 */
final class ReportStore
{
	/** @var \Joomla\Database\DatabaseInterface */
	private $db;

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function q()
	{
		return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
	}

	/** Reports made by a person since a UTC date (SQL). */
	public function countByUserSince(int $uid, string $since): int
	{
		$q = $this->q()->select('COUNT(*)')->from($this->db->quoteName('#__engage_reports'))
			->where($this->db->quoteName('user_id') . ' = :u')
			->where($this->db->quoteName('created') . ' >= :s')
			->bind(':u', $uid, ParameterType::INTEGER)->bind(':s', $since, ParameterType::STRING);

		return (int) $this->db->setQuery($q)->loadResult();
	}

	public function countOpen(): int
	{
		$st = ReportRules::STATUS_OPEN;
		$q  = $this->q()->select('COUNT(*)')->from($this->db->quoteName('#__engage_reports'))
			->where($this->db->quoteName('status') . ' = :st')->bind(':st', $st, ParameterType::INTEGER);

		return (int) $this->db->setQuery($q)->loadResult();
	}

	public function exists(int $commentId, int $uid): bool
	{
		$q = $this->q()->select('COUNT(*)')->from($this->db->quoteName('#__engage_reports'))
			->where($this->db->quoteName('comment_id') . ' = :c')->where($this->db->quoteName('user_id') . ' = :u')
			->bind(':c', $commentId, ParameterType::INTEGER)->bind(':u', $uid, ParameterType::INTEGER);

		return (int) $this->db->setQuery($q)->loadResult() > 0;
	}

	/** Stores a report. false if the pair (comment, person) already exists (unique key) or the insert failed. */
	public function add(int $commentId, int $uid, int $reason, string $note, string $now): bool
	{
		try
		{
			$row = (object) ['comment_id' => $commentId, 'user_id' => $uid, 'reason' => $reason, 'note' => $note, 'created' => $now, 'status' => ReportRules::STATUS_OPEN];
			$this->db->insertObject('#__engage_reports', $row);

			return true;
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/** Comment IDs (of the given list) that this person already reported. */
	public function reportedBy(int $uid, array $commentIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $commentIds), static fn(int $i): bool => $i > 0));

		if ($uid <= 0 || $ids === [])
		{
			return [];
		}

		$q = $this->q()->select($this->db->quoteName('comment_id'))->from($this->db->quoteName('#__engage_reports'))
			->where($this->db->quoteName('user_id') . ' = :u')->whereIn($this->db->quoteName('comment_id'), $ids, ParameterType::INTEGER)
			->bind(':u', $uid, ParameterType::INTEGER);

		return array_map('intval', $this->db->setQuery($q)->loadColumn() ?: []);
	}

	/**
	 * The queue for moderators: one row per report with the comment and the reporter. Filters: status (0, 1, 2 or -1 = all), reason (0 = all).
	 *
	 * @return  array{rows: array, total: int}
	 */
	public function queue(int $status, int $reason, int $offset, int $limit): array
	{
		$db = $this->db;
		$q  = $this->q()
			->select([
				'r.id', 'r.comment_id', 'r.user_id', 'r.reason', 'r.note', 'r.created', 'r.status', 'r.handled_by', 'r.handled_on',
				$db->quoteName('c.body', 'body'), $db->quoteName('c.enabled', 'comment_enabled'), $db->quoteName('c.name', 'comment_name'), $db->quoteName('c.created_by', 'comment_by'),
				$db->quoteName('ru.name', 'reporter_name'), $db->quoteName('ru.username', 'reporter_username'),
			])
			->from($db->quoteName('#__engage_reports', 'r'))
			->join('INNER', $db->quoteName('#__engage_comments', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('r.comment_id'))
			->join('LEFT', $db->quoteName('#__users', 'ru'), $db->quoteName('ru.id') . ' = ' . $db->quoteName('r.user_id'));
		$cq = $this->q()->select('COUNT(*)')->from($db->quoteName('#__engage_reports', 'r'))
			->join('INNER', $db->quoteName('#__engage_comments', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('r.comment_id'));

		foreach ([$q, $cq] as $qq)
		{
			if ($status >= 0)
			{
				$qq->where($db->quoteName('r.status') . ' = :st')->bind(':st', $status, ParameterType::INTEGER);
			}

			if ($reason > 0)
			{
				$qq->where($db->quoteName('r.reason') . ' = :rs')->bind(':rs', $reason, ParameterType::INTEGER);
			}
		}

		$q->order($db->quoteName('r.created') . ' DESC')->order($db->quoteName('r.id') . ' DESC');

		return ['rows' => $db->setQuery($q, max(0, $offset), max(1, min(200, $limit)))->loadAssocList() ?: [], 'total' => (int) $db->setQuery($cq)->loadResult()];
	}

	/** Marks every OPEN report of a comment (or one report) with a status, by a moderator. Returns the number of rows changed. */
	public function resolve(int $id, int $commentId, int $status, int $by, string $now): int
	{
		if (!in_array($status, [ReportRules::STATUS_DISMISSED, ReportRules::STATUS_HANDLED], true) || ($id <= 0 && $commentId <= 0))
		{
			return 0;
		}

		$open = ReportRules::STATUS_OPEN;
		$q    = $this->q()->update($this->db->quoteName('#__engage_reports'))
			->set($this->db->quoteName('status') . ' = :st')->set($this->db->quoteName('handled_by') . ' = :by')->set($this->db->quoteName('handled_on') . ' = :on')
			->where($this->db->quoteName('status') . ' = :open')
			->bind(':st', $status, ParameterType::INTEGER)->bind(':by', $by, ParameterType::INTEGER)->bind(':on', $now, ParameterType::STRING)->bind(':open', $open, ParameterType::INTEGER);

		if ($id > 0)
		{
			$q->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
		}
		else
		{
			$q->where($this->db->quoteName('comment_id') . ' = :cid')->bind(':cid', $commentId, ParameterType::INTEGER);
		}

		$this->db->setQuery($q)->execute();

		return (int) $this->db->getAffectedRows();
	}

	/** Open reports per comment, for the badge in the comments list: [comment id => count]. */
	public function openCounts(array $commentIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $commentIds), static fn(int $i): bool => $i > 0));

		if ($ids === [])
		{
			return [];
		}

		$open = ReportRules::STATUS_OPEN;
		$q    = $this->q()->select([$this->db->quoteName('comment_id'), 'COUNT(*) AS n'])->from($this->db->quoteName('#__engage_reports'))
			->where($this->db->quoteName('status') . ' = :open')->whereIn($this->db->quoteName('comment_id'), $ids, ParameterType::INTEGER)
			->group($this->db->quoteName('comment_id'))->bind(':open', $open, ParameterType::INTEGER);
		$out  = [];

		foreach ($this->db->setQuery($q)->loadAssocList() ?: [] as $r)
		{
			$out[(int) $r['comment_id']] = (int) $r['n'];
		}

		return $out;
	}

	/** Open reports not yet announced to the moderators: [count, oldest date]. */
	public function pendingDigest(): array
	{
		$open = ReportRules::STATUS_OPEN;
		$q    = $this->q()->select(['COUNT(*) AS n', 'MIN(' . $this->db->quoteName('created') . ') AS oldest'])->from($this->db->quoteName('#__engage_reports'))
			->where($this->db->quoteName('status') . ' = :open')->where($this->db->quoteName('notified_on') . ' IS NULL')->bind(':open', $open, ParameterType::INTEGER);
		$r    = $this->db->setQuery($q)->loadAssoc() ?: [];

		return [(int) ($r['n'] ?? 0), (string) ($r['oldest'] ?? '')];
	}

	public function markNotified(string $now): void
	{
		$open = ReportRules::STATUS_OPEN;
		$q    = $this->q()->update($this->db->quoteName('#__engage_reports'))->set($this->db->quoteName('notified_on') . ' = :on')
			->where($this->db->quoteName('status') . ' = :open')->where($this->db->quoteName('notified_on') . ' IS NULL')
			->bind(':on', $now, ParameterType::STRING)->bind(':open', $open, ParameterType::INTEGER);
		$this->db->setQuery($q)->execute();
	}

	/** Housekeeping: reports of comments that no longer exist, and the handled reports older than the given date. */
	public function cleanup(string $handledBefore): void
	{
		$db = $this->db;
		$this->db->setQuery('DELETE r FROM ' . $db->quoteName('#__engage_reports') . ' AS r LEFT JOIN ' . $db->quoteName('#__engage_comments') . ' AS c ON c.id = r.comment_id WHERE c.id IS NULL')->execute();
		$open = ReportRules::STATUS_OPEN;
		$q    = $this->q()->delete($db->quoteName('#__engage_reports'))->where($db->quoteName('status') . ' <> :open')->where($db->quoteName('handled_on') . ' < :b')
			->bind(':open', $open, ParameterType::INTEGER)->bind(':b', $handledBefore, ParameterType::STRING);
		$db->setQuery($q)->execute();
	}

	/** Reports made by a person are removed with the account. */
	public function deleteForUser(int $uid): void
	{
		$q = $this->q()->delete($this->db->quoteName('#__engage_reports'))->where($this->db->quoteName('user_id') . ' = :u')->bind(':u', $uid, ParameterType::INTEGER);
		$this->db->setQuery($q)->execute();
	}
}
