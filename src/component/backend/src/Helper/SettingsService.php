<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.25): logica del guardado de UN ajuste desde la pantalla de opciones. Todo lo que toca Joomla (permisos,
 * lectura y escritura de parametros, filtro de texto, registro de acciones) entra por funciones inyectadas, de modo que las
 * pruebas unitarias ejercitan permisos, claves, valores, fusion e idempotencia sin Joomla. El comprobador de token CSRF vive
 * en el controlador (Session::checkToken) y se prueba en el Joomla real.
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

final class SettingsService
{
	/**
	 * @param   array<string,array<string,array>>  $schema      ambito => clave => definicion (SettingsSchema::parse()['fields'])
	 * @param   callable                           $authorise   fn(string $scope): bool       permiso para cambiar ajustes de ese ambito
	 * @param   callable                           $load        fn(string $scope): array       parametros ACTUALES de la base de datos
	 * @param   callable                           $save        fn(string $scope, array $p): void   los guarda (lanza si falla)
	 * @param   callable                           $filter      fn(string $value, string $filter): string   filtro de texto de Joomla
	 * @param   callable                           $dynamic     fn(): array                     listas dinamicas (grupo => [elementos])
	 * @param   callable|null                      $log         fn(string $scope, string $key): void   registro de acciones (opcional)
	 */
	/** @var array<string,array<string,array>> */
	private array $schema;
	private $authorise;
	private $load;
	private $save;
	private $filter;
	private $dynamic;
	private $log;

	public function __construct(array $schema, callable $authorise, callable $load, callable $save, callable $filter, callable $dynamic, ?callable $log = null)
	{
		$this->schema    = $schema;
		$this->authorise = $authorise;
		$this->load      = $load;
		$this->save      = $save;
		$this->filter    = $filter;
		$this->dynamic   = $dynamic;
		$this->log       = $log;
	}

	/**
	 * @param   mixed  $scope  Ambito tal como llega
	 * @param   mixed  $key    Clave tal como llega
	 * @param   mixed  $value  Valor tal como llega
	 *
	 * @return array{scope:string,key:string,value:string,changed:bool}
	 * @throws SettingsException
	 */
	public function apply($scope, $key, $value): array
	{
		// 1. El ambito: lista cerrada (el componente y los plugins de Engage)
		if (!is_string($scope) || !isset(SettingsSchema::SCOPES[$scope]) || !isset($this->schema[$scope]))
		{
			throw SettingsException::invalid('scope');
		}

		// 2. Permiso sobre ese ambito, ANTES de mirar la clave o el valor
		if (!($this->authorise)($scope))
		{
			throw SettingsException::denied();
		}

		// 3. La clave: solo las declaradas en el manifiesto
		if (!is_string($key) || !isset($this->schema[$scope][$key]) || !array_key_exists($key, $this->schema[$scope]))
		{
			throw SettingsException::invalid('key');
		}

		$def = $this->schema[$scope][$key];

		// 4. El valor: validado con la definicion del campo, luego filtrado con el filtro del propio campo
		try
		{
			$clean = SettingsSchema::validate($def, $value, ($this->dynamic)());
		}
		catch (\InvalidArgumentException $e)
		{
			throw SettingsException::invalid('value');
		}

		if ($def['control'] === 'text' || $def['control'] === 'textarea')
		{
			$filtered = ($this->filter)($clean, $def['filter'] ?: 'string');

			if (!is_string($filtered))
			{
				throw SettingsException::invalid('filter');
			}

			// Si el filtro cambia el valor (etiquetas, entidades...) se guarda el valor filtrado, ya validado de nuevo
			try
			{
				$clean = SettingsSchema::validate($def, $filtered, ($this->dynamic)());
			}
			catch (\InvalidArgumentException $e)
			{
				throw SettingsException::invalid('value');
			}
		}

		// 5. Fusion con los parametros existentes (nunca se pisa lo demas) y guardado solo si cambia
		try
		{
			$params = ($this->load)($scope);
			$old    = $params[$key] ?? null;

			if ($old !== null && (string) $old === $clean)
			{
				return ['scope' => $scope, 'key' => $key, 'value' => $clean, 'changed' => false];
			}

			$params[$key] = $clean;
			($this->save)($scope, $params);
		}
		catch (\Throwable $e)
		{
			throw SettingsException::failed($e);
		}

		if ($this->log !== null)
		{
			try
			{
				($this->log)($scope, $key);
			}
			catch (\Throwable $e)
			{
				// El registro de acciones nunca debe impedir el guardado
			}
		}

		return ['scope' => $scope, 'key' => $key, 'value' => $clean, 'changed' => true];
	}
}
