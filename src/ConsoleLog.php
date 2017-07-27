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

	private static $_callers = [];
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

		// Find caller file and line
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$backtrace = $backtrace[$this->_bt] ?? null;
		$caller = $backtrace
			? "{$backtrace['file']} : {$backtrace['line']}"
			: 'unknown';

		// Add new log row
		self::_addRow($data, $caller, $type);
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



	protected function _addRow(array $data, string $caller, string $type)
	{
		// No caller for these
		if(in_array($type, static::NO_BACKTRACE))
			$caller = null;

		// And none for repeaters
		if(end(self::$_callers) == $caller)
			$caller = null;
		else
			self::$_callers[] = $caller;
		
		// Add to log array and write header
		self::$_log['rows'][] = [$data, $caller, $type];
		self::_writeHeader();
	}



	protected function _writeHeader()
	{
		$log = json_encode(self::$_log);
		$log = base64_encode($log);
		header(self::HEADER_NAME.': '.$log, true);
	}



	/**
	 * Helper: Substitute to use for duplicates in log data.
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
