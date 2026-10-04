<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * Panel de control (pantalla de entrada del componente desde 0.6.24). Solo muestra datos: las acciones rapidas de la
 * lista de ultimos comentarios usan las tareas ya existentes de CommentsController (con su token y sus permisos).
 *
 * @since 0.6.24
 */
class ControlpanelController extends BaseController
{
	use ControllerEventsTrait;

	/** @inheritdoc */
	protected $default_view = 'controlpanel';
}
