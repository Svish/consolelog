<?php
require '../vendor/autoload.php';
use Geekality\ConsoleLog;


class TestClass
{
	public $a = 1;
	protected $b = 2;
	private $c = 3;
}

/**
 * Variable-length parameters.
 */
ConsoleLog::log('Use', 'as many', 'paramterers', ['as', 'you', 'want']);
ConsoleLog::log('As long as Unicode, even emoji 👍🎉');


/**
 * Grouping and available log levels.
 */
ConsoleLog::group('Log levels');
ConsoleLog::log('ConsoleLog::log( stuff )');
ConsoleLog::info('ConsoleLog::info( stuff )');
ConsoleLog::warn('ConsoleLog::warn( stuff )');
ConsoleLog::error('ConsoleLog::error( stuff )');
ConsoleLog::groupEnd();


/**
 * Arrays and objects can be passed too.
 */
ConsoleLog::group('Arrays and objects');
ConsoleLog::log('Simple array:', [1,2,3]);
ConsoleLog::log('Array with keys:', ['a' => 1, 'b' => 2, 'c' => 3]);
ConsoleLog::log('An object:', new TestClass);
ConsoleLog::log('Mixed array:', [1, 'a', new TestClass, ['an', 'array']]);
ConsoleLog::groupEnd();


/**
 * Using ConsoleLog as part of a custom logger.
 */
ConsoleLog::group('ConsoleLog inside');
$myLog = new class
{
	private $console;
	public function __construct()
	{
		# Setting backtrace level to 2
		$this->console = new ConsoleLog(2);
	}
	public function formatted(string $format, ...$data)
	{
		// file_put_contents('my.log', json_encode($data)."\r\n", FILE_APPEND);

		# Otherwise the following line will be logged as backtrace
		$this->console->info(sprintf($format, ...$data));
	}
};

		# Instead of this next line
		$myLog->formatted('This info log line should be backtraced to %s:%s', __FILE__, __LINE__);
ConsoleLog::groupEnd();


/**
 * Various tables.
 */
ConsoleLog::group('Tables');
ConsoleLog::log('Plain, simple table:');
ConsoleLog::table([
	['Alice', 'Rabbit', 'F'],
	['Bob', 'Cat', 'M'],
	['Clark', 'Horse', 'M'],
	['Diana', 'Lynx', 'F'],
	]);
ConsoleLog::log('Table with headers:');
ConsoleLog::table([
	['Name' => 'Alice', 'Type' => 'Rabbit', 'Sex' => 'F'],
	['Name' => 'Bob', 'Type' => 'Cat', 'Sex' => 'M'],
	['Name' => 'Clark', 'Type' => 'Horse', 'Sex' => 'M'],
	['Name' => 'Diana', 'Type' => 'Lynx', 'Sex' => 'F'],
	]);
ConsoleLog::log('Table with headers and custom index:');
ConsoleLog::table([
	'Row 1' => ['Name' => 'Alice', 'Type' => 'Rabbit', 'Sex' => 'F'],
	'Row 2' => ['Name' => 'Bob', 'Type' => 'Cat', 'Sex' => 'M'],
	'Row 3' => ['Name' => 'Clark', 'Type' => 'Horse', 'Sex' => 'M'],
	'Row 4' => ['Name' => 'Diana', 'Type' => 'Lynx', 'Sex' => 'F'],
	]);
ConsoleLog::log('Inconsistent table:');
ConsoleLog::table([
	'Row 1' => ['Name' => 'Alice', 'Type' => 'Rabbit', 'Sex' => 'F'],
	'Row 2' => ['Bob', 'Cat', 'M'],
	['Name' => 'Clark', 'Type' => 'Horse', 'Sex' => 'M'],
	['Diana', 'Lynx', 'F'],
	]);
ConsoleLog::groupEnd();


/**
 * Object recursion cycles and duplicates in log messages.
 */
ConsoleLog::group('Object de-duplication and recursion prevention');
$x = new TestClass;
$y = new TestClass;
$y->a = $x;
$x->a = [$x, $x, $y, $y];
ConsoleLog::log('If an object is "repeated", further "mentions" are replaced with a string reference to prevent recursion cycles and to limit data sent in header:');
ConsoleLog::log($x);
ConsoleLog::log('And logging that object again later, will of course log it again:');
ConsoleLog::log($y);
ConsoleLog::groupEnd();



?>
<pre>
See developer console.

Might need to refresh if it wasn't open when page loaded... 🙂
</pre>
<hr>

<?php highlight_file(__FILE__) ?>
