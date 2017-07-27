<?php

namespace Geekality;
use Exception;
use Reflection, ReflectionClass, ReflectionProperty;



/**
 * Class for logging via Chrome Logger protocol.
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

	private static $_log = [
		'version' => self::VERSION,
		'columns' => ['log', 'backtrace', 'type'],
		'rows' => [],
	];

	private static $_backtraces = [];
	private $_processed = [];
	private $_bt;



	public function __construct(int $bt = 1)
	{
		$this->_bt = $bt;
	}



	private static $_instance;
	private static final function instance()
	{
		if( ! self::$_instance)
			self::$_instance = new static(2);
		return self::$_instance;
	}



	public static final function __callStatic($type, $data)
	{
		self::instance()->$type(...$data);
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



	protected function _log($type, array $data)
	{
		// Convert $data to something safer to send
		$this->_processed = [];
		$data = $this->_convert($data);

		// Find backtrace file and line
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$backtrace = $backtrace[$this->_bt] ?? null;
		$backtrace = $backtrace
			? "{$backtrace['file']} : {$backtrace['line']}"
			: 'unknown';

		// Add new log row
		$row = self::_row($data, $backtrace, $type);
		self::$_log['rows'][] = $row;

		// Write the header
		$this->_writeHeader();
		return $this;
	}



	protected function _convert($object)
	{
		// "Recurse" if array
		if(is_array($object))
			return array_map([$this, '_convert'], $object);

		// Return as is if not object
		if( ! is_object($object))
			return $object;

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



	protected function _row(array $data, string $backtrace, string $type)
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
	 * @return string The data JSON and base64 encoded.
	 */
	protected function _writeHeader()
	{
		header($this->_getHeader(self::$_log));
	}



	/**
	 * @param 
	 * @return string The header to send.
	 */
	protected function _getHeader(array $data): string
	{
		$data = $this->_encode($data);
		return self::HEADER_NAME.': '.$data;
	}



	/**
	 * @return string The data JSON and base64 encoded.
	 */
	protected function _encode(array $data): string
	{
        array_walk_recursive($data, [$this, '_filterData']);
		$data = json_encode($data);
		return base64_encode($data);
	}



	/**
	 * @return string The data JSON and base64 encoded.
	 */
	protected function _filterData(&$data)
	{
		// Resources
		if(is_resource($data))
			$data = sprintf('%s (%s)', $data, get_resource_type($data));
		// Non-finite numbers
		elseif(is_numeric($data) && !is_finite($data))
			$data = sprintf('%s (%s)', $data, 'numeric');
		// Other unknown stuff
        elseif(!is_object($data) && !is_null($data) && !is_scalar($data))
            $data = print_r($data, true);
	}



	/**
	 * @return string Substitute for recurring objects.
	 */
	protected function _objRef($obj, int $n): string
	{
		return sprintf('object (%s) [%s]', get_class($obj), $n);
	}



	/**
	 * Helper: Yield [prop] => [value] of $object.
	 */
	private final static function _getProperties($object): \Generator
	{
		$class = new ReflectionClass($object);
		foreach($class->getProperties() as $p)
		{
			$p->setAccessible(true);
			yield self::_getPropertyKey($p) => $p->getValue($object);
		}
	}



	/**
	 * Helper: Get property name with modifiers prepended.
	 */
	private final static function _getPropertyKey(ReflectionProperty $p): string
	{
		$m = Reflection::getModifierNames($p->getModifiers());
		$m[] = $p->getName();
		return implode(' ', $m);
	}
}
