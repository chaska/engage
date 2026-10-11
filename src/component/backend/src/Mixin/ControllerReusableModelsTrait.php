<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Mixin;

defined('_JEXEC') || die;

use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView;
use Joomla\CMS\MVC\View\ViewInterface;
use RuntimeException;

trait ControllerReusableModelsTrait
{
	static public $_models = [];

	/** 0.8.2: Engage's own cache of views (see getView): Joomla's static one is shared by every component of the request. */
	private static $engageViews = [];

	public function getModel($name = '', $prefix = '', $config = [])
	{
		if (empty($name))
		{
			$name = ucfirst($this->app->isClient('site') ? $this->default_view : $this->input->get('view', $this->default_view));
		}

		$prefix = ucfirst($prefix ?: $this->app->getName());

		$hash = hash('md5', strtolower($name . $prefix));

		if (isset(self::$_models[$hash]))
		{
			return self::$_models[$hash];
		}

		self::$_models[$hash] = parent::getModel($name, $prefix, $config);

		return self::$_models[$hash];
	}

	/**
	 * @param   string  $name
	 * @param   string  $type
	 * @param   string  $prefix
	 * @param   array   $config
	 *
	 * @return  ViewInterface|HtmlView
	 * @throws  Exception
	 * @since   3.0.0
	 */
	public function getView($name = '', $type = '', $prefix = '', $config = [])
	{
		$document = $this->app->getDocument();

		if (empty($name))
		{
			$name = $this->input->get('view', $this->default_view);
		}

		if (empty($type))
		{
			$type = $document->getType();
		}

		if (empty($config))
		{
			$viewLayout = $this->input->get('layout', 'default', 'cmd');
			$config     = ['base_path' => $this->basePath, 'layout' => $viewLayout];
		}

		/**
		 * 0.8.2: the layout is never taken from the request as is (path traversal / local file inclusion: `../../`, `%00`, `....//`):
		 * only letters and digits are accepted (no dots, slashes, NUL or underscores; `_` separates sub-templates internally); anything
		 * else is the `default` layout. The view name goes through the same closed alphabet.
		 */
		$layout = (string) ($config['layout'] ?? 'default');
		$config['layout'] = (preg_match('/^[A-Za-z0-9]{1,32}$/D', $layout) === 1) ? $layout : 'default';

		if (preg_match('/^[A-Za-z0-9]{1,32}$/D', (string) $name) !== 1)
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		/**
		 * 0.8.2: Joomla's BaseController::getView() keeps the views in a STATIC cache keyed by view name / type / prefix ("Site") that every
		 * component of the request shares. Another component with a view called `comments` (or one that dispatches to us in the same request)
		 * would hand us ITS view, or take ours. Engage therefore creates its view with ITS OWN MVC factory (namespace Akeeba\Component\Engage)
		 * and keeps it in its own cache, and refuses any view that is not an Engage class.
		 */
		if (!$prefix)
		{
			$prefix = (!empty($config['base_path']) && str_starts_with(str_replace('\\', '/', (string) $config['base_path']), str_replace('\\', '/', JPATH_ADMINISTRATOR))) ? 'Administrator' : $this->app->getName();
		}

		$hadView = isset(self::$engageViews[$name][$type][$prefix]);

		if (!$hadView)
		{
			$created = $this->factory->createView($name, $prefix, $type, $config);

			if (!is_object($created) || !str_starts_with(get_class($created), 'Akeeba\\Component\\Engage\\'))
			{
				throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 404);
			}

			self::$engageViews[$name][$type][$prefix] = $created;
		}

		$view = self::$engageViews[$name][$type][$prefix];

		if (!$hadView)
		{
			// Get/Create the model
			$side = $this->app->isClient('site') ? 'Site' : 'Administrator';

			if ($model = $this->getModel($name, $side, ['base_path' => $this->basePath]))
			{
				// Push the model into the view (as default)
				$view->setModel($model, true);
			}

			$view->document = $document;
		}

		return $view;
	}
}