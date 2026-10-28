<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

/**
 * 0.9.17: the ONE way into «Permissions». Everything that used to lead to Joomla's options (toolbar, tiles, settings, help) opens the matrix
 * directly; the matrix links to Joomla's native screen. Only fixed parameters: nothing from the request, no host.
 */
final class PermissionsNav
{
	/** The matrix (relative, fixed). */
	public const MATRIX = 'index.php?option=com_engage&view=permissions';

	/** Can the current identity open the matrix? Same rule as the screen itself: core.admin on the component. */
	public static function canOpen(): bool
	{
		try
		{
			$user = Factory::getApplication()->getIdentity();

			return $user && !$user->guest && $user->authorise('core.admin', 'com_engage');
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	/** Joomla's native options screen of the component, permissions tab (the fragment is ignored by Joomla if the tab changes: it then opens the options). Fixed parameters only.. */
	public static function advancedRaw(): string
	{
		return 'index.php?option=com_config&view=component&component=com_engage&return=' . base64_encode(self::MATRIX);
	}

	public static function matrixUrl(): string
	{
		return Route::_(self::MATRIX, false);
	}

	public static function advancedUrl(): string
	{
		return Route::_(self::advancedRaw(), false) . '#permissions';
	}
}
