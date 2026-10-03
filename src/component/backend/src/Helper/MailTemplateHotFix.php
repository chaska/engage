<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Mail\MailTemplate;

defined('_JEXEC') or die;

/**
 * A hotfix for the borkage in Joomla! 5.2's core MailTemplate class.
 */
class MailTemplateHotFix
{
	private const WRAPPER_NAME = 'akmtwa://';

	/**
	 * Buffer hash
	 *
	 * @var    array
	 */
	public static $buffers = [];

	public static $canRegisterWrapper = null;

	/**
	 * Stream position
	 *
	 * @var    integer
	 */
	public $position = 0;

	/**
	 * Buffer name
	 *
	 * @var    string
	 */
	public $name = null;

	public $context;

	/**
	 * Should I register the stream wrapper
	 *
	 * @return  bool  True if the stream wrapper can be registered
	 */
	private static function canRegisterWrapper()
	{
		if (is_null(static::$canRegisterWrapper))
		{
			static::$canRegisterWrapper = false;

			// Maybe the host has disabled registering stream wrappers altogether?
			if (!function_exists('stream_wrapper_register'))
			{
				return false;
			}

			// Check for Suhosin
			if (function_exists('extension_loaded'))
			{
				$hasSuhosin = extension_loaded('suhosin');
			}
			else
			{
				$hasSuhosin = -1; // Can't detect
			}

			if ($hasSuhosin !== true)
			{
				$hasSuhosin = defined('SUHOSIN_PATCH') ? true : -1;
			}

			if ($hasSuhosin === -1)
			{
				if (function_exists('ini_get'))
				{
					$hasSuhosin = false;

					$maxIdLength = ini_get('suhosin.session.max_id_length');

					if ($maxIdLength !== false)
					{
						$hasSuhosin = ini_get('suhosin.session.max_id_length') !== '';
					}
				}
			}

			// If we can't detect whether Suhosin is installed we won't proceed to prevent a White Screen of Death
			if ($hasSuhosin === -1)
			{
				return false;
			}

			// If Suhosin is installed but ini_get is not available we won't proceed to prevent a WSoD
			if ($hasSuhosin && !function_exists('ini_get'))
			{
				return false;
			}

			// If Suhosin is installed check if our stream wrapper is whitelisted
			if ($hasSuhosin)
			{
				$whiteList = ini_get('suhosin.executor.include.whitelist');

				// Nothing in the whitelist? I can't go on, sorry.
				if (empty($whiteList))
				{
					return false;
				}

				$whiteList = explode(',', $whiteList);
				$whiteList = array_map(function ($x) {
					return trim($x);
				}, $whiteList);

				if (!in_array(self::WRAPPER_NAME, $whiteList))
				{
					return false;
				}
			}

			static::$canRegisterWrapper = true;
		}

		return static::$canRegisterWrapper;
	}

	/**
	 * Register the stream wrapper
	 *
	 * @return void
	 */
	private static function registerStreamWrapper()
	{
		if (defined('_AKEEBA_STREAM_WRAPPER_REGISTERED'))
		{
			return;
		}

		if (self::canRegisterWrapper())
		{
			stream_wrapper_register(rtrim(self::WRAPPER_NAME, ':/'), self::class);

			define('_AKEEBA_STREAM_WRAPPER_REGISTERED', true);
		}
	}

	/**
	 * Primera versión de Joomla con el fallo (MailTemplate convierte las URL relativas ANTES de aplicar la plantilla).
	 */
	public const BUGGY_FROM = '5.2.0';

	/**
	 * Primera versión de Joomla que ya lo trae corregido (comprobado en 5.2.2, 5.2.3, 5.3.0, 5.4.0, 6.0.0 y las ramas
	 * 5.4-dev y 6.1-dev: la conversión está justo antes de setBody()).
	 */
	public const FIXED_IN = '5.2.2';

	/**
	 * ¿Es esta versión de Joomla una de las afectadas? Solo 5.2.0 y 5.2.1 (rango cerrado).
	 */
	public static function isAffectedVersion(string $version): bool
	{
		return version_compare($version, self::BUGGY_FROM, '>=') && version_compare($version, self::FIXED_IN, '<');
	}

	/**
	 * Devuelve el código de MailTemplate parcheado, o NULL si el código no está exactamente en el estado defectuoso
	 * conocido (en ese caso no se parchea nada y se usa la clase estándar del núcleo).
	 *
	 * Estado defectuoso: la conversión aparece una sola vez, ANTES de crear el FileLayout, y setBody($htmlBody)
	 * aparece una sola vez; además "class MailTemplate" aparece una sola vez.
	 */
	public static function buildPatchedSource(string $sourceCode): ?string
	{
		$convert = '$htmlBody = MailHelper::convertRelativeToAbsoluteUrls($htmlBody);';
		$setBody = '$this->mailer->setBody($htmlBody)';

		if (substr_count($sourceCode, 'class MailTemplate') !== 1
			|| substr_count($sourceCode, $convert) !== 1
			|| substr_count($sourceCode, $setBody) !== 1)
		{
			return null;
		}

		$posLayout = strpos($sourceCode, 'new FileLayout(');

		if ($posLayout === false || strpos($sourceCode, $convert) > $posLayout)
		{
			return null;
		}

		$sourceCode = str_replace(
			'class MailTemplate',
			'class MailTemplateAkeebaWorkaround extends \\Joomla\\CMS\\Mail\\MailTemplate',
			$sourceCode
		);

		$sourceCode = str_replace($convert, '', $sourceCode);

		return str_replace($setBody, $convert . $setBody, $sourceCode);
	}

	/**
	 * Aplica el parche solo en Joomla 5.2.0 y 5.2.1 y solo si el código tiene el estado defectuoso conocido.
	 *
	 * @return  bool  True si MailTemplateAkeebaWorkaround está disponible.
	 */
	private static function hotFixMailTemplate(): bool
	{
		if (!defined('JVERSION') || !self::isAffectedVersion(JVERSION))
		{
			return false;
		}

		if (class_exists(\Joomla\CMS\Mail\MailTemplateAkeebaWorkaround::class, false))
		{
			return true;
		}

		if (!self::canRegisterWrapper())
		{
			return false;
		}

		$sourceFile = JPATH_LIBRARIES . '/src/Mail/MailTemplate.php';
		$sourceCode = is_file($sourceFile) ? file_get_contents($sourceFile) : false;

		if (!is_string($sourceCode))
		{
			return false;
		}

		$sourceCode = self::buildPatchedSource($sourceCode);

		if ($sourceCode === null)
		{
			return false;
		}

		self::registerStreamWrapper();

		$tempFile = self::WRAPPER_NAME . 'akeebamailtemplate.php';

		if (!file_put_contents($tempFile, $sourceCode))
		{
			return false;
		}

		@include_once $tempFile;

		return class_exists(\Joomla\CMS\Mail\MailTemplateAkeebaWorkaround::class, false);
	}

	public static function getAWorkingMailTemplate($templateId, $language, ?Mail $mailer = null): MailTemplate
	{
		try
		{
			if (self::hotFixMailTemplate())
			{
				return new \Joomla\CMS\Mail\MailTemplateAkeebaWorkaround($templateId, $language, $mailer);
			}
		}
		catch (\Throwable $e)
		{
			// Se usa la clase estándar del núcleo.
		}

		return new \Joomla\CMS\Mail\MailTemplate($templateId, $language, $mailer);
	}

	/**
	 * Function to open file or url
	 *
	 * @param   string   $path          The URL that was passed
	 * @param   string   $mode          Mode used to open the file @see fopen
	 * @param   integer  $options       Flags used by the API, may be STREAM_USE_PATH and
	 *                                  STREAM_REPORT_ERRORS
	 * @param   string  &$opened_path   Full path of the resource. Used with STREAM_USE_PATH option
	 *
	 * @return  boolean
	 *
	 * @see     streamWrapper::stream_open
	 */
	public function stream_open($path, $mode, $options, &$opened_path)
	{
		$url            = parse_url($path);
		$this->name     = $url['host'] . ($url['path'] ?? '');
		$this->position = 0;

		if (!isset(static::$buffers[$this->name]))
		{
			static::$buffers[$this->name] = null;
		}

		return true;
	}

	public function stream_set_option($option, $arg1 = null, $arg2 = null)
	{
		return false;
	}

	public function unlink($path)
	{
		$url  = parse_url($path);
		$name = $url['host'];

		if (isset(static::$buffers[$name]))
		{
			unset (static::$buffers[$name]);
		}
	}

	public function stream_stat()
	{
		return [
			'dev'     => 0,
			'ino'     => 0,
			'mode'    => 0644,
			'nlink'   => 0,
			'uid'     => 0,
			'gid'     => 0,
			'rdev'    => 0,
			'size'    => strlen(static::$buffers[$this->name]),
			'atime'   => 0,
			'mtime'   => 0,
			'ctime'   => 0,
			'blksize' => -1,
			'blocks'  => -1,
		];
	}

	/**
	 * Read stream
	 *
	 * @param   integer  $count  How many bytes of data from the current position should be returned.
	 *
	 * @return  mixed    The data from the stream up to the specified number of bytes (all data if
	 *                   the total number of bytes in the stream is less than $count. Null if
	 *                   the stream is empty.
	 *
	 * @see     streamWrapper::stream_read
	 * @since   11.1
	 */
	public function stream_read($count)
	{
		$ret            = substr(static::$buffers[$this->name], $this->position, $count);
		$this->position += strlen($ret);

		return $ret;
	}

	/**
	 * Write stream
	 *
	 * @param   string  $data  The data to write to the stream.
	 *
	 * @return  integer
	 *
	 * @see     streamWrapper::stream_write
	 * @since   11.1
	 */
	public function stream_write($data)
	{
		$left                         = substr(static::$buffers[$this->name] ?? '', 0, $this->position);
		$right                        = substr(static::$buffers[$this->name] ?? '', $this->position + strlen($data));
		static::$buffers[$this->name] = $left . $data . $right;
		$this->position               += strlen($data);

		return strlen($data);
	}

	/**
	 * Function to get the current position of the stream
	 *
	 * @return  integer
	 *
	 * @see     streamWrapper::stream_tell
	 * @since   11.1
	 */
	public function stream_tell()
	{
		return $this->position;
	}

	/**
	 * Function to test for end of file pointer
	 *
	 * @return  boolean  True if the pointer is at the end of the stream
	 *
	 * @see     streamWrapper::stream_eof
	 * @since   11.1
	 */
	public function stream_eof()
	{
		return $this->position >= strlen(static::$buffers[$this->name]);
	}

	/**
	 * The read write position updates in response to $offset and $whence
	 *
	 * @param   integer  $offset  The offset in bytes
	 * @param   integer  $whence  Position the offset is added to
	 *                            Options are SEEK_SET, SEEK_CUR, and SEEK_END
	 *
	 * @return  boolean  True if updated
	 *
	 * @see     streamWrapper::stream_seek
	 * @since   11.1
	 */
	public function stream_seek($offset, $whence)
	{
		switch ($whence)
		{
			case SEEK_SET:
				if ($offset < strlen(static::$buffers[$this->name]) && $offset >= 0)
				{
					$this->position = $offset;

					return true;
				}
				else
				{
					return false;
				}
				break;

			case SEEK_CUR:
				if ($offset >= 0)
				{
					$this->position += $offset;

					return true;
				}
				else
				{
					return false;
				}
				break;

			case SEEK_END:
				if (strlen(static::$buffers[$this->name]) + $offset >= 0)
				{
					$this->position = strlen(static::$buffers[$this->name]) + $offset;

					return true;
				}
				else
				{
					return false;
				}
				break;

			default:
				return false;
		}
	}
}