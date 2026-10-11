<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Mixin;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Language\Text;
use RuntimeException;

trait ControllerEventsTrait
{
	use TriggerEventTrait;

	/**
	 * Execute a task by triggering a method in the derived class.
	 *
	 * Overridden to apply a custom ACL check and trigger before/after methods.
	 *
	 * @param   string  $task  The task to perform. If no matching task is found, the '__default' task is executed, if
	 *                         defined.
	 *
	 * @return  mixed   The value returned by the called method.
	 *
	 * @throws  Exception
	 * @since   3.0.0
	 */
	public function execute($task)
	{
		$this->task = $task;

		$task = strtolower($task);

		// 0.8.3: closed list of tasks (fail-closed), before anything else runs
		$this->assertEngageTaskAllowed($task);

		if (isset($this->taskMap[$task]))
		{
			$doTask = $this->taskMap[$task];
		}
		elseif (isset($this->taskMap['__default']))
		{
			$doTask = $this->taskMap['__default'];
		}
		else
		{
			throw new RuntimeException(Text::sprintf('JLIB_APPLICATION_ERROR_TASK_NOT_FOUND', $task), 404);
		}

		// Execute onBeforeExecute and onBefore<Task> events
		$eventName = 'onBefore' . ucfirst($task);

		$this->triggerEvent('onBeforeExecute', [&$task]);
		$this->triggerEvent($eventName);

		// The task may have changed, so let's try that once again.
		$this->assertEngageTaskAllowed($task);

		if (isset($this->taskMap[$task]))
		{
			$doTask = $this->taskMap[$task];
		}
		elseif (isset($this->taskMap['__default']))
		{
			$doTask = $this->taskMap['__default'];
		}
		else
		{
			throw new RuntimeException(Text::sprintf('JLIB_APPLICATION_ERROR_TASK_NOT_FOUND', $task), 404);
		}

		// Record the actual task being fired and execute it.
		$this->doTask = $doTask;
		$result       = $this->$doTask();

		// Execute onAfter<Task> and onAfterExecute events
		$eventName = 'onAfter' . ucfirst($task);

		$this->triggerEvent($eventName);
		$this->triggerEvent('onAfterExecute', [$task]);

		return $result;
	}

	/**
	 * 0.8.3: the closed list of tasks that the URL may reach in this controller, in lowercase; NULL = no restriction.
	 *
	 * Joomla's controllers register EVERY public method as a task and send any unknown task to the default one. On the public side
	 * (frontend controllers) that exposed methods which were never meant to be called by URL (getModel, redirect, loadhistory,
	 * runTransition...), and the default task showed whatever the view showed. The frontend controllers override this method with the
	 * handful of tasks the pages actually use; everything else answers 403, the same as any other denied request.
	 *
	 * @return  string[]|null
	 * @since   0.8.3
	 */
	protected function engageAllowedTasks(): ?array
	{
		return null;
	}

	/**
	 * @throws  RuntimeException  403 when the task is not in the closed list
	 * @since   0.8.3
	 */
	private function assertEngageTaskAllowed(string $task): void
	{
		$allowed = $this->engageAllowedTasks();

		if ($allowed !== null && !in_array($task, $allowed, true))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}

}