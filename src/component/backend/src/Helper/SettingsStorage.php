<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.25): la parte de la pantalla de opciones que habla con Joomla: manifiestos, parametros (Table Extension,
 * el mecanismo estandar de Joomla; no se inventa ninguna tabla), listas dinamicas, permisos, filtro de texto y registro de
 * acciones. Consultas con el constructor de Joomla y parametros enlazados.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Extension as ExtensionTable;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

final class SettingsStorage
{
	public function __construct(private DatabaseInterface $db)
	{
	}

	private function query()
	{
		return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
	}

	/** Ruta del manifiesto con la lista de campos de un ambito (solo ambitos conocidos). */
	public static function manifestPath(string $scope): string
	{
		$s = SettingsSchema::SCOPES[$scope] ?? null;

		if ($s === null)
		{
			return '';
		}

		return $s['type'] === 'component'
			? JPATH_ADMINISTRATOR . '/components/com_engage/config.xml'
			: JPATH_PLUGINS . '/' . $s['folder'] . '/' . $s['element'] . '/' . $s['element'] . '.xml';
	}

	/** Esquema de todos los ambitos instalados: ambito => clave => definicion. */
	public static function schema(): array
	{
		$out = [];

		foreach (array_keys(SettingsSchema::SCOPES) as $scope)
		{
			$f = self::manifestPath($scope);

			if ($f !== '' && is_file($f))
			{
				$fields = SettingsSchema::parse((string) file_get_contents($f), $scope)['fields'];

				if ($fields)
				{
					$out[$scope] = $fields;
				}
			}
		}

		return $out;
	}

	private function extensionId(string $scope): int
	{
		$s = SettingsSchema::SCOPES[$scope] ?? null;

		if ($s === null)
		{
			return 0;
		}

		$q = $this->query();
		$q->select($this->db->quoteName('extension_id'))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = :type')
			->where($this->db->quoteName('element') . ' = :element')
			->where($this->db->quoteName('folder') . ' = :folder')
			->bind(':type', $s['type'], ParameterType::STRING)
			->bind(':element', $s['element'], ParameterType::STRING)
			->bind(':folder', $s['folder'], ParameterType::STRING);

		return (int) $this->db->setQuery($q)->loadResult();
	}

	/** Estado de un plugin del esquema: ['exists' => bool, 'enabled' => bool, 'id' => int]. */
	public function pluginState(string $scope): array
	{
		$id = $this->extensionId($scope);

		if ($id === 0)
		{
			return ['exists' => false, 'enabled' => false, 'id' => 0];
		}

		$q = $this->query();
		$q->select($this->db->quoteName('enabled'))->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('extension_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);

		return ['exists' => true, 'enabled' => (int) $this->db->setQuery($q)->loadResult() === 1, 'id' => $id];
	}

	/** Parametros actuales, leidos de la base de datos (no de la cache: se van a fusionar y escribir). */
	public function load(string $scope): array
	{
		$id = $this->extensionId($scope);

		if ($id === 0)
		{
			throw new \RuntimeException('extension');
		}

		$q = $this->query();
		$q->select($this->db->quoteName('params'))->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('extension_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
		$json = (string) $this->db->setQuery($q)->loadResult();
		$arr  = json_decode($json === '' ? '{}' : $json, true);

		return is_array($arr) ? $arr : [];
	}

	/** Guarda con Table Extension y limpia la cache de sistema. Lanza si algo falla. */
	public function save(string $scope, array $params): void
	{
		$id = $this->extensionId($scope);

		if ($id === 0)
		{
			throw new \RuntimeException('extension');
		}

		$table = new ExtensionTable($this->db);

		if (!$table->load($id))
		{
			throw new \RuntimeException('load');
		}

		$table->params = (new Registry($params))->toString('JSON');

		if (!$table->check() || !$table->store())
		{
			throw new \RuntimeException('store');
		}

		// Comprobacion de lectura: si no quedo guardado, que se note
		if ($this->load($scope) != $params)
		{
			throw new \RuntimeException('verify');
		}

		try
		{
			Factory::getApplication()->bootComponent('com_engage')->getCacheCleanerService()->clearGroups(['_system']);
		}
		catch (\Throwable $e)
		{
			// la cache no impide el guardado
		}
	}

	/** Plugins de captcha activados (valores validos del campo captcha). */
	public function captchaPlugins(): array
	{
		$grp = 'captcha';
		$q   = $this->query();
		$q->select($this->db->quoteName('element'))->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . " = 'plugin'")
			->where($this->db->quoteName('folder') . ' = :grp')
			->where($this->db->quoteName('enabled') . ' = 1')
			->bind(':grp', $grp, ParameterType::STRING);

		return array_map('strval', $this->db->setQuery($q)->loadColumn() ?: []);
	}

	public static function authorise(User $user, string $scope): bool
	{
		if (($scope === 'com_engage'))
		{
			return $user->authorise('core.admin', 'com_engage') || $user->authorise('core.options', 'com_engage');
		}

		// Plugins de Engage: solo con permiso de edicion de plugins
		return isset(SettingsSchema::SCOPES[$scope]) && $user->authorise('core.manage', 'com_plugins') && $user->authorise('core.edit', 'com_plugins');
	}

	/** Filtro de texto de Joomla segun el atributo filter del campo. */
	public static function filter(string $value, string $filter): string
	{
		if (strtolower($filter) === 'safehtml')
		{
			return (string) InputFilter::getInstance([], [], 1, 1)->clean($value, 'html');
		}

		return (string) InputFilter::getInstance()->clean($value, preg_match('/^[a-z_]+$/i', $filter) === 1 ? $filter : 'string');
	}

	/** Registro de acciones, solo si el plugin de registro de Engage esta activado. Nunca incluye el valor (puede ser una clave). */
	public static function log(User $user, string $scope, string $key): void
	{
		if (!PluginHelper::isEnabled('actionlog', 'engage') || $user->guest)
		{
			return;
		}

		$app   = Factory::getApplication();
		$model = $app->bootComponent('com_actionlogs')->getMVCFactory()->createModel('Actionlog', 'Administrator', ['ignore_request' => true]);
		$model->addLog([[
			'title'       => $key,
			'setting'     => $key,
			'scope'       => $scope,
			'userid'      => $user->id,
			'username'    => $user->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
		]], 'COM_ENGAGE_USERLOG_SETTING_CHANGED', 'com_engage', $user->id);
	}
}
