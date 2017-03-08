<?php

namespace Geekality;
use Exception;
use Reflection, ReflectionClass, ReflectionProperty;

/**
 * Class for logging via Chrome Logger protocol.
 *
 * @see http://www.chromelogger.com
 * @see https://craig.is/writing/chrome-logger/techspecs
 */
class ConsoleLog
{
	const VERSION = '1.0.0';
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

	public $backtrace_level = 1;


	private static $_instance;
	public static final function instance()
	{
		if( ! self::$_instance)
			self::$_instance = new static;
		return self::$_instance;
	}


	/**
	 * Call via instance.
	 */
	public static final function __callStatic($type, $data)
	{
		self::instance()->$type(...$data);
	}


	/**
	 * Add another log row to be sent to the browser.
	 */
	public final function __call($type, $data)
	{
		if( ! in_array($type, static::ALLOWED_TYPES))
			throw new Exception("Unsupported chrome logger type: $type");

		if(empty($data) && $type != 'groupEnd')
			throw new Exception("No arguments; Nothing to log");

		if($type == 'log')
			$type = '';

		$this->_log($type, $data);
		return $this;
	}



	private $_processed;
	private $_backtraces = [];
	private $_json = [
		'version' => self::VERSION,
		'columns' => ['log', 'backtrace', 'type'],
		'rows' => [],
	];



	protected function _log($type, array $data)
	{
		$this->_processed = [];
		$logs = $this->_convert($data);

		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$backtrace = $backtrace[$this->backtrace_level] ?? null;
		$backtrace_message = $backtrace
			? $this->formatBacktrace($backtrace)
			: 'unknown';

		$this->_addRow($logs, $backtrace_message, $type);
		$this->_writeHeader($this->_json);
	}


	protected function formatBacktrace(array $backtrace): string
	{
		return "{$backtrace['file']} : {$backtrace['line']}";
	}


	protected function _addRow(array $logs, $backtrace, $type)
	{
		if(in_array($type, self::NO_BACKTRACE))
			$backtrace = null;

		if(end($this->_backtraces) == $backtrace)
			$backtrace = null;
		else
			$this->_backtraces[] = $backtrace;
		
		$this->_json['rows'][] = [$logs, $backtrace, $type];
	}



	protected function _writeHeader($data)
	{
		header(self::HEADER_NAME . ': ' . self::_encode($data), true);
	}
	private final static function _encode($data): string
	{
		return base64_encode(json_encode($data));
	}


	/**
	 * Converts $object to more log friendly format.
	 */
	protected function _convert($object)
	{
		if(is_array($object))
			return array_map([$this, '_convert'], $object);

		if( ! is_object($object))
			return $object;

		// Store already processed objects
		$object_hash = spl_object_hash($object);
		if( ! array_key_exists($object_hash, $this->_processed))
			$this->_processed[$object_hash] = $this->_objRef($object);

		// Convert object into array
		$obj = [];

		// First add the class name
		$obj['___class_name'] = get_class($object);

		// Add properties
		foreach (self::_getProperties($object) as $name => $value)
		{
			// Prevent recursion by using object reference if processed before
			if(is_object($value))
				$value = $this->_processed[spl_object_hash($value)] ?? $value;

			$obj[$name] = $this->_convert($value);
		}
		return $obj;
	}



	/**
	 * Returns string substitute to use if $obj appears again.
	 */
	protected function _objRef($obj): string
	{
		return sprintf('object (%s) [%s]', get_class($obj), ++$this->_objCount);
	}
	private $_objCount = 0;


	/**
	 * Yields property key => property value.
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
	 * Returns property name with modifiers prepended.
	 */
	private final static function _getPropertyKey(ReflectionProperty $p): string
	{
		$m = Reflection::getModifierNames($p->getModifiers());
		$m[] = $p->getName();
		return implode(' ', $m);
	}
}
