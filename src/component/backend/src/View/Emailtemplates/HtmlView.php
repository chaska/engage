<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\View\Emailtemplates;

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\EmailPreview;
use Akeeba\Component\Engage\Administrator\Helper\TemplateEmails;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class HtmlView extends BaseHtmlView
{
	/** @var array<int,array{key:string,name:string,languages:string[],installed:bool,preview:string,edit:string}> 0.9.3: one row per template that Engage knows */
	public $rows = [];

	public function display($tpl = null)
	{
		ToolbarHelper::title(sprintf(Text::_('COM_ENGAGE_TITLE_EMAILTEMPLATES')), 'icon-engage');
		ToolbarHelper::back('JTOOLBAR_BACK', Route::_('index.php?option=com_engage&view=comments', false));

		Factory::getApplication()->getDocument()->getWebAssetManager()->useStyle('com_engage.lists')->useScript('com_engage.panel.theme');

		$this->rows = $this->buildRows();

		parent::display($tpl);
	}

	/** One row per known template: friendly name, languages with a stored template, state, preview and edit links. */
	private function buildRows(): array
	{
		$user    = Factory::getApplication()->getIdentity();
		$canEdit = $user && $user->authorise('core.edit', 'com_mails');
		$canView = $user && $user->authorise('core.admin', 'com_engage');
		$langs   = [];

		try
		{
			$db  = Factory::getContainer()->get(DatabaseInterface::class);
			$ext = 'com_engage';
			$q   = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
				->select([$db->quoteName('template_id'), $db->quoteName('language')])
				->from($db->quoteName('#__mail_templates'))
				->where($db->quoteName('extension') . ' = :ext')
				->bind(':ext', $ext, ParameterType::STRING);

			foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r)
			{
				$langs[(string) $r['template_id']][] = (string) $r['language'];
			}
		}
		catch (\Throwable $e)
		{
		}

		$rows = [];

		foreach (TemplateEmails::getKnownTemplatesKeys() as $key)
		{
			$short = preg_replace('/^com_engage\./', '', $key);
			$kind  = ['notify_managers' => 'moderator', 'notify_author' => 'subscriber'][$short] ?? '';
			$ls    = array_values(array_unique(array_map(static fn(string $l): string => $l === '' ? '*' : $l, $langs[$key] ?? [])));
			sort($ls);
			$nameKey = 'COM_ENGAGE_EMAILTEMPLATES_NAME_' . strtoupper($short);
			$name    = Text::_($nameKey);

			$rows[] = [
				'key'       => $key,
				'name'      => $name === $nameKey ? $key : $name,
				'languages' => $ls,
				'installed' => $ls !== [],
				'preview'   => ($kind !== '' && $canView) ? Route::_('index.php?option=com_engage&view=emailpreview&kind=' . $kind . '&lang=' . EmailPreview::lang(str_replace('*', 'en-GB', $ls[0] ?? 'en-GB'))) : '',
				'edit'      => ($canEdit && $ls !== []) ? Route::_('index.php?option=com_mails&task=template.edit&template_id=' . rawurlencode($key) . '&language=' . rawurlencode(str_replace('*', '', $ls[0]))) : '',
			];
		}

		return $rows;
	}
}
