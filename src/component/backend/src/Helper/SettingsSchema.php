<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.25): definicion de los ajustes que se pueden cambiar desde la pantalla de opciones modernas, LEIDA de
 * los propios manifiestos (config.xml del componente y <config> de los plugins de Engage): ese es el unico origen de claves
 * permitidas. Valida y normaliza cada valor con la definicion del campo (opciones de las listas, rangos de enteros, longitud,
 * caracteres). Funciones puras: no tocan Joomla ni la base de datos.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use SimpleXMLElement;

final class SettingsSchema
{
	/**
	 * Ambitos permitidos: el componente y SOLO los plugins de Engage. Cualquier otro ambito (otro componente, otro plugin)
	 * se rechaza antes de mirar nada mas.
	 */
	public const SCOPES = [
		'com_engage'          => ['type' => 'component', 'element' => 'com_engage', 'folder' => ''],
		'plg_engage_gravatar' => ['type' => 'plugin', 'element' => 'gravatar', 'folder' => 'engage'],
		'plg_engage_akismet'  => ['type' => 'plugin', 'element' => 'akismet', 'folder' => 'engage'],
		'plg_engage_email'    => ['type' => 'plugin', 'element' => 'email', 'folder' => 'engage'],
	];

	/** Tipos de campo que NO se pueden cambiar desde aqui (dinamicos, ficheros o permisos): siguen en las opciones clasicas. */
	private const UNSETTABLE = ['note', 'spacer', 'rules', 'media', 'modulesmodule', 'calendar', 'editor', 'password', 'hidden', 'subform'];

	/** Longitud maxima por tipo de control (cuando el campo no declara maxlength). */
	private const MAXLEN = ['text' => 255, 'textarea' => 4000];

	/**
	 * Reglas extra por clave (ademas de la definicion del campo). Son restricciones de seguridad: una URL para el enlace de
	 * la IP no puede ser javascript:, la lista blanca de HTML Purifier no puede llevar caracteres raros, etc.
	 */
	private const EXTRA = [
		'iplookup'                  => ['regex' => '#^(|https?://[^\s"\'<>%]+(%s[^\s"\'<>%]*)?)$#', 'maxlen' => 500],
		'jbcookies_group'           => ['regex' => '/^[A-Za-z0-9_\- ]{0,64}$/', 'maxlen' => 64],
		'key'                       => ['regex' => '/^[A-Za-z0-9]{0,64}$/', 'maxlen' => 64],
		'htmlpurifier_configstring' => ['regex' => '/^[A-Za-z0-9,\[\]|*.#:_\-\s]{0,4000}$/', 'maxlen' => 4000],
		'tos_prompt'                => ['maxlen' => 500],
	];

	/**
	 * Lee los campos ajustables de un manifiesto.
	 *
	 * @param   string  $xml    Contenido del manifiesto (config.xml o <extension> del plugin)
	 * @param   string  $scope  Uno de self::SCOPES
	 *
	 * @return array{fields:array<string,array<string,mixed>>,skipped:array<int,string>}
	 */
	public static function parse(string $xml, string $scope): array
	{
		$out = ['fields' => [], 'skipped' => []];

		if (!isset(self::SCOPES[$scope]))
		{
			return $out;
		}

		$prev = libxml_use_internal_errors(true);
		$root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
		libxml_use_internal_errors($prev);

		if ($root === false)
		{
			return $out;
		}

		$nodes = self::SCOPES[$scope]['type'] === 'component'
			? $root->xpath('//fieldset/field')
			: $root->xpath('//config/fields[@name="params"]//field');

		foreach ($nodes ?: [] as $f)
		{
			$name = (string) $f['name'];
			$type = strtolower((string) $f['type']);

			if ($name === '' || in_array($type, self::UNSETTABLE, true))
			{
				if ($name !== '')
				{
					$out['skipped'][] = $name;
				}

				continue;
			}

			$def = [
				'key'       => $name,
				'scope'     => $scope,
				'type'      => $type,
				'label'     => (string) $f['label'],
				'desc'      => (string) $f['description'],
				'default'   => isset($f['default']) ? (string) $f['default'] : '',
				'showon'    => (string) $f['showon'],
				'filter'    => (string) $f['filter'],
				'useglobal' => in_array(strtolower((string) $f['useglobal']), ['1', 'true'], true),
				'options'   => [],
				'min'       => null,
				'max'       => null,
				'step'      => 1,
				'maxlength' => isset($f['maxlength']) ? (int) $f['maxlength'] : 0,
				'hint'      => (string) $f['hint'],
				'dynamic'   => '',
			];

			foreach ($f->xpath('option') ?: [] as $o)
			{
				$def['options'][(string) $o['value']] = trim((string) $o);
			}

			switch ($type)
			{
				case 'radio':
					$def['control'] = (count($def['options']) === 2 && self::isZeroOne($def['options'])) ? 'switch' : 'segmented';
					break;

				case 'list':
					$n = count($def['options']) + ($def['useglobal'] ? 1 : 0);
					$def['control'] = $n <= 5 ? 'segmented' : 'select';
					break;

				case 'plugins':
					// Lista dinamica (plugins instalados de un grupo): el servidor la calcula; aqui solo el grupo.
					$def['dynamic'] = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $f['folder']));
					$def['control'] = 'select';
					break;

				case 'integer':
					$def['min']  = (int) ($f['first'] ?? 0);
					$def['max']  = (int) ($f['last'] ?? 0);
					$def['step'] = max(1, (int) ($f['step'] ?? 1));
					$count       = intdiv($def['max'] - $def['min'], $def['step']) + 1;
					$def['control'] = $count <= 7 ? 'segmented' : 'number';

					if ($def['control'] === 'segmented')
					{
						for ($v = $def['min']; $v <= $def['max']; $v += $def['step'])
						{
							$def['options'][(string) $v] = (string) $v;
						}
					}
					break;

				case 'number':
					$def['min']     = isset($f['min']) ? (int) $f['min'] : 0;
					$def['max']     = isset($f['max']) ? (int) $f['max'] : PHP_INT_MAX;
					$def['step']    = max(1, (int) ($f['step'] ?? 1));
					$def['control'] = 'number';
					break;

				case 'textarea':
					$def['control'] = 'textarea';
					break;

				case 'text':
				case 'url':
				case 'email':
					$def['type']    = 'text';
					$def['control'] = 'text';
					break;

				default:
					$out['skipped'][] = $name;
					continue 2;
			}

			if (isset(self::EXTRA[$name]['maxlen']))
			{
				$def['maxlength'] = self::EXTRA[$name]['maxlen'];
			}

			$out['fields'][$name] = $def;
		}

		return $out;
	}

	/**
	 * Valida y normaliza un valor. Devuelve SIEMPRE una cadena. Lanza InvalidArgumentException si no es valido (el mensaje es
	 * solo para depurar: el controlador contesta con un 4xx generico).
	 *
	 * @param   array  $def      Definicion del campo (parse)
	 * @param   mixed  $raw      Valor tal como llega
	 * @param   array  $dynamic  Valores permitidos de las listas dinamicas: grupo => [elementos]
	 */
	public static function validate(array $def, $raw, array $dynamic = []): string
	{
		if (is_int($raw))
		{
			$raw = (string) $raw;
		}
		elseif (is_bool($raw))
		{
			$raw = $raw ? '1' : '0';
		}

		if (!is_string($raw))
		{
			throw new \InvalidArgumentException('tipo');
		}

		if (!mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0"))
		{
			throw new \InvalidArgumentException('codificacion');
		}

		$control = $def['control'];
		$multi   = $control === 'textarea';

		// Caracteres de control: solo salto de linea y tabulador en textareas
		if (preg_match($multi ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $raw) === 1)
		{
			throw new \InvalidArgumentException('control');
		}

		$max = (int) ($def['maxlength'] ?: (self::MAXLEN[$def['type']] ?? 255));

		if (mb_strlen($raw) > $max)
		{
			throw new \InvalidArgumentException('longitud');
		}

		switch ($control)
		{
			case 'switch':
			case 'segmented':
			case 'select':
				$allowed = array_map('strval', array_keys($def['options']));

				if ($def['useglobal'])
				{
					$allowed[] = '';
				}

				if ($def['type'] === 'plugins')
				{
					$allowed = array_merge($allowed, ['', '-1'], array_map('strval', $dynamic[$def['dynamic']] ?? []));
				}

				if (!in_array($raw, $allowed, true))
				{
					throw new \InvalidArgumentException('opcion');
				}

				return $raw;

			case 'number':
				if (preg_match('/^\d{1,12}$/', $raw) !== 1)
				{
					throw new \InvalidArgumentException('entero');
				}

				$v = (int) $raw;

				if ($v < $def['min'] || $v > $def['max'] || (($v - $def['min']) % $def['step']) !== 0)
				{
					throw new \InvalidArgumentException('rango');
				}

				return (string) $v;
		}

		// text / textarea
		$value = $multi ? str_replace("\r\n", "\n", $raw) : trim($raw);

		if (isset(self::EXTRA[$def['key']]['regex']) && preg_match(self::EXTRA[$def['key']]['regex'], $value) !== 1)
		{
			throw new \InvalidArgumentException('formato');
		}

		return $value;
	}

	/** Cierto si las opciones son exactamente 0 y 1 (en cualquier orden): es un interruptor. */
	private static function isZeroOne(array $options): bool
	{
		$k = array_map('strval', array_keys($options));
		sort($k);

		return $k === ['0', '1'];
	}

	/** Claves sobre las que hay reglas extra (para pruebas). */
	public static function extraKeys(): array
	{
		return array_keys(self::EXTRA);
	}
}
