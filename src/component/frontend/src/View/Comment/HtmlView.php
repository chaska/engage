<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Site\View\Comment;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\UserFetcher;
use Akeeba\Component\Engage\Administrator\Table\CommentTable;
use Akeeba\Component\Engage\Site\Helper\CommentAccess;
use Akeeba\Component\Engage\Site\Mixin\ModuleRenderAware;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use RuntimeException;

class HtmlView extends BaseHtmlView
{
	use ModuleRenderAware;

	/**
	 * The comment editing form.
	 *
	 * @var   Form
	 * @since 3.0.0
	 */
	public $form;

	/**
	 * The URL to return to after saving the comment.
	 *
	 * @var   string
	 * @since 3.0.0
	 */
	public $returnUrl;

	/**
	 * The comment we are editing.
	 *
	 * @var   CommentTable
	 * @since 3.0.0
	 */
	public $item;

	/**
	 * The URL option for the component.
	 *
	 * This is used to automatically determine the template overrides path when using _setPath().
	 *
	 * @var    string
	 * @since  3.0.6
	 */
	protected $option = 'com_engage';

	/** @inheritDoc */
	public function display($tpl = null)
	{
		$this->setLayout('edit');
		$this->_setPath('template', [
			JPATH_SITE . '/components/com_engage/tmpl/comment'
		]);
		$this->_setPath('helper', [
			JPATH_SITE . '/components/com_engage/helpers'
		]);

		// 0.8.3: belt and braces; the controller has already decided, the view does not trust that it was the one called
		$requestedId = CommentAccess::requestedId(Factory::getApplication()->getInput());

		if (!CommentAccess::mayOpen($requestedId, UserFetcher::getUser(), !empty(Factory::getApplication()->getUserState('com_engage.edit.comment.data', []))))
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$this->form = $this->getForm();
		$this->item = $this->get('Item');

		parent::display($tpl);
	}

}