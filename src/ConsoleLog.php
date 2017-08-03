<?php

namespace Geekality;
use Exception;
use Reflection, ReflectionClass, ReflectionProperty;


header_register_callback([ConsoleLog::class, '_writeHeader']);


/**
 * Class for logging via Chrome Logger protocol.
 * 
 * TODO: Unit tests
 *   - https://phpunit.de/getting-started.html
 * 
 * 
 * @see https://github.com/svish/consolelog
 * @see https://craig.is/writing/chrome-logger
 * @see https://craig.is/writing/chrome-logger/techspecs
 */
class ConsoleLog
{
	const VERSION = '1.1';
	const HEADER_NAME = 'X-ChromeLogger-Data';
	const ALLOWED_TYPES = [
		'log',
		'info',
		'warn',
		'error',
		'group',
		'groupEnd',
		'groupCollapsed',
		'table',
		];
	const NO_BACKTRACE = [
		'group',
		'groupEnd',
		'groupCollapsed',
		];

	protected static $rows = [];

	private static $_log = [
		'version' => self::VERSION,
		'columns' => ['log', 'backtrace', 'type'],
		'rows' => null,
	];

	private static $_instance;
	private static $_backtraces = [];
	private $_processed = [];
	private $_bt;

	/**
	 * Constructor.
	 * 
	 * @param int $bt Backtrace level to get caller id.
	 */
	public function __construct(int $bt = 1)
	{
		$this->_bt = $bt;
		if(is_null(self::$_log['rows']))
			self::$_log['rows'] = &self::$rows;
	}



	/**
	 * The instance used by the static logging methods.
	 * 
	 * @param self $instance If provided, and called before any static logging calls have been made, the given $instance will be used by the static logging methods instead of creating a default one.
	 * 
	 * @see self::__callStatic
	 */
	public static final function instance(self $instance = null)
	{
		if( ! self::$_instance)
		{
			self::$_instance = ! is_null($instance)
				? $instance
				: new static(2);
		}
		return self::$_instance;
	}



	public static final function __callStatic($type, $data)
	{
		return self::instance()->$type(...$data);
	}



	public final function __call($type, $data)
	{
		// Check if type is supported
		if( ! in_array($type, static::ALLOWED_TYPES))
			throw new Exception("Unsupported chrome logger type: '$type'");

		// Check we got something to log
		if(empty($data) && $type != 'groupEnd')
			throw new Exception("No arguments; Nothing to log");

		// Empty string for 'log' (recommended for less header data)
		if($type == 'log')
			$type = '';

		// Log the data
		return $this->_log($type, $data);
	}



	protected function _log($type, array $data): self
	{
		// Convert $data to something safer to send
		$this->_processed = [];
		$data = $this->_convert($data);

		// Find backtrace file and line
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$backtrace = $backtrace[$this->_bt] ?? null;
		$backtrace = $backtrace
			? $this->_backtrace($backtrace['file'], $backtrace['line'])
			: 'unknown';

		// Add new log row
		$row = self::_row($data, $backtrace, $type);
		if( ! is_null($row))
			self::$rows[] = $row;

		// Write the header
		//$this->_writeHeader(); 
		return $this;
	}


	protected function _convert($object)
	{
		// Return mapped "recursed" array
		if(is_array($object))
			return array_map([$this, '_convert'], $object);

		// Return if not object
		if( ! is_object($object))
			return $this->_filterData($object);

		// Remember object if new
		if(is_object($object))
		{
			$object_hash = spl_object_hash($object);
			if( ! array_key_exists($object_hash, $this->_processed))
			{
				$n = count($this->_processed) + 1;
				$this->_processed[$object_hash] = $this->_objRef($object, $n);
			}
			// If not new, return obj ref instead
			else
				return $this->_processed[$object_hash];
		}

		// Array for object data
		$obj = [];

		// Add class name
		$obj['___class_name'] = get_class($object);

		// Add properties
		foreach (self::_getProperties($object) as $prop => $value)
			$obj[$prop] = $this->_convert($value);

		return $obj;
	}



	protected function _row(array $data, string $backtrace, string $type): array
	{
		// No backtrace for these
		if(in_array($type, static::NO_BACKTRACE))
			$backtrace = null;

		// And none for repeaters
		if(end(self::$_backtraces) == $backtrace)
			$backtrace = null;
		else
			self::$_backtraces[] = $backtrace;
		
		return [$data, $backtrace, $type];
	}



	/**
	 * Write the console log header.
	 */
	public static function _writeHeader(): void
	{
		if( ! headers_sent())
			header(static::_getHeader());
	}



	/**
	 * @return string The complete header with name and encoded log data.
	 */
	protected static function _getHeader(): string
	{
		$data = static::_encode(static::$_log);
		return static::HEADER_NAME.': '.$data;
	}



	/**
	 * @return string The data JSON and base64 encoded.
	 */
	protected static function _encode(array $data): string
	{
		array_walk_recursive($data, [static::class, '_filterData']);
		$data = json_encode($data);
		return base64_encode($data);
	}



	/**
	 * Overwrites $data with something safe, if something json_encode chokes on.
	 * @return void
	 */
	protected static function _filterData(&$data)
	{
		// Null
		if(is_null($data))
			return 'null';

		// Resources
		elseif(is_resource($data))
			$data = sprintf('%s (%s)', $data, get_resource_type($data));
		
		// Non-finite numbers
		elseif(is_numeric($data) && !is_finite($data))
			$data = sprintf('%s (%s)', $data, 'numeric');
		
		// Other weird stuff, e.g. NaN
		elseif(!is_object($data) && !is_null($data) && !is_scalar($data))
			$data = print_r($data, true);

		return $data;
	}



	/**
	 * @return string Substitute for recurring objects.
	 */
	protected function _objRef($obj, int $n): string
	{
		return sprintf('object (%s) [%s]', get_class($obj), $n);
	}


	/**
	 * @return string $file : $line
	 */
	protected static final function _backtrace(string $file, int $line): string
	{
		return "$file : $line";
	}



	private static function _getProperties($object): iterable
	{
		$class = new ReflectionClass($object);
		foreach($class->getProperties() as $p)
		{
			$p->setAccessible(true);
			yield self::_getPropertyKey($p) => $p->getValue($object);
		}
	}



	private static function _getPropertyKey(ReflectionProperty $p): string
	{
		$m = Reflection::getModifierNames($p->getModifiers());
		$m[] = $p->getName();
		return implode(' ', $m);
	}
}
