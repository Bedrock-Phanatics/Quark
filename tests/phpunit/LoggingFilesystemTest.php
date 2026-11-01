<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use pmmp\thread\Thread;
use quark\crash\CrashDump;
use quark\crash\CrashDumpData;
use quark\crash\CrashDumpDataGeneral;
use quark\crash\CrashDumpRenderer;
use quark\log\LogDispatcher;
use quark\utils\Filesystem;
use function bin2hex;
use function clearstatcache;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function microtime;
use function mkdir;
use function preg_match_all;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function strlen;
use function strpos;
use function substr_count;
use function sys_get_temp_dir;
use const DIRECTORY_SEPARATOR;

final class LoggingProducer extends Thread{
	public function __construct(private LogDispatcher $writer, private string $prefix){}

	public function run() : void{
		require dirname(__DIR__, 2) . "/vendor/autoload.php";
		for($i = 0; $i < 200; ++$i){
			$this->writer->submit(log\Event::create("info", "$this->prefix:$i", thread: $this->prefix), false);
		}
	}
}

final class LoggingFilesystemTest extends TestCase{
	private string $root;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "quark-logging-" . bin2hex(random_bytes(8));
		mkdir($this->root);
	}

	protected function tearDown() : void{
		Filesystem::recursiveUnlink($this->root);
	}

	public function testConcurrentWritesAndShutdownDrain() : void{
		$file = $this->root . "/server.log";
		$writer = new LogDispatcher($file, null);
		$writer->start(Thread::INHERIT_NONE);
		$workers = [];
		for($i = 0; $i < 4; ++$i){
			$worker = new LoggingProducer($writer, "worker$i");
			$worker->start(Thread::INHERIT_NONE);
			$workers[] = $worker;
		}
		foreach($workers as $worker){
			$worker->join();
		}
		$writer->syncFlushBuffer();
		$writer->shutdown();
		$content = file_get_contents($file);
		self::assertIsString($content);
		self::assertSame(800, substr_count($content, "\n"));
		for($i = 0; $i < 4; ++$i){
			self::assertStringContainsString("worker$i:199\n", $content);
			$previous = -1;
			for($sequence = 0; $sequence < 200; ++$sequence){
				$position = strpos($content, "worker$i:$sequence\n");
				self::assertIsInt($position);
				self::assertGreaterThan($previous, $position);
				$previous = $position;
			}
		}
	}

	public function testRotationRetentionAndUnrelatedFiles() : void{
		$file = $this->root . "/server.log";
		$archive = $this->root . "/archive";
		$writer = new LogDispatcher($file, $archive, 1024, 2, configuration: ["console-level" => "emergency"], console: true);
		mkdir($archive);
		file_put_contents($archive . "/keep.txt", "untouched");
		$writer->start(Thread::INHERIT_NONE);
		for($i = 0; $i < 100; ++$i){
			$writer->submit(log\Event::create("info", "line $i " . str_repeat("x", 56)), false);
		}
		$writer->syncFlushBuffer();
		$writer->shutdown();
		$archived = glob($archive . "/server.*.log");
		self::assertIsArray($archived);
		self::assertCount(2, $archived);
		self::assertSame("untouched", file_get_contents($archive . "/keep.txt"));
		$content = file_get_contents($file);
		self::assertIsString($content);
		self::assertStringContainsString("line 99", $content);
	}

	public function testBufferIsBoundedAndReportsDroppedLines() : void{
		$file = $this->root . "/server.log";
		$writer = new LogDispatcher($file, null, configuration: ["buffer-bytes" => 262144]);
		for($i = 0; $i < 12; ++$i){
			$writer->submit(log\Event::create("info", "line $i " . str_repeat("x", 60000)), false);
		}
		self::assertGreaterThan(0, $writer->getQueue()->stats()["dropped"]);
		self::assertLessThanOrEqual(262144, $writer->getQueue()->stats()["bytes"]);
		$writer->submit(log\Event::create("error", "important diagnostic"), true);
		$writer->start(Thread::INHERIT_NONE);
		$writer->syncFlushBuffer();
		$writer->shutdown();
		$content = file_get_contents($file);
		self::assertIsString($content);
		self::assertStringContainsString("Dropped", $content);
		self::assertStringContainsString("important diagnostic", $content);
		self::assertStringContainsString("line 0 ", $content);
	}

	public function testWriterFailureUnblocksFlush() : void{
		$file = $this->root . "/server.log";
		$archive = $this->root . "/archive";
		file_put_contents($file, str_repeat("x", 2048));
		$writer = new LogDispatcher($file, $archive, 1024);
		mkdir($archive);
		rmdir($archive);
		file_put_contents($archive, "blocks rotation");
		$writer->start(Thread::INHERIT_NONE);
		try{
			$writer->syncFlushBuffer();
			self::fail("Flush must report the writer failure");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("Log writer failed", $e->getMessage());
		}
		try{
			$writer->shutdown();
			self::fail("Shutdown must report the writer failure");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("Log writer failed", $e->getMessage());
		}
		$content = file_get_contents($file);
		self::assertIsString($content);
		self::assertSame(2048, strlen($content));
	}

	public function testRetentionKeepsCurrentReportAndUnknownFiles() : void{
		file_put_contents($this->root . "/crash_old.txt", "old");
		file_put_contents($this->root . "/crash_new.txt", "new");
		file_put_contents($this->root . "/unknown.txt", "keep");
		Filesystem::retainNewestFiles($this->root, "crash_", ".txt", 1, $this->root . "/crash_new.txt");
		clearstatcache();
		self::assertFileDoesNotExist($this->root . "/crash_old.txt");
		self::assertFileExists($this->root . "/crash_new.txt");
		self::assertFileExists($this->root . "/unknown.txt");
		file_put_contents($this->root . "/pulse_old.qpulse", "old");
		file_put_contents($this->root . "/pulse_new.qpulse", "new");
		Filesystem::retainNewestFiles($this->root, "pulse_", ".qpulse", 0);
		self::assertFileExists($this->root . "/pulse_old.qpulse");
		Filesystem::retainNewestFiles($this->root, "pulse_", ".qpulse", 1, $this->root . "/pulse_new.qpulse");
		self::assertFileDoesNotExist($this->root . "/pulse_old.qpulse");
		self::assertFileExists($this->root . "/pulse_new.qpulse");
	}

	public function testCrashReportsArePublishedWithoutOverwriting() : void{
		$server = $this->createMock(Server::class);
		(new \ReflectionProperty(Server::class, "paths"))->setValue($server, new FilesystemPaths($this->root, $this->root . "/plugins"));
		$data = new CrashDumpData();
		$data->time = microtime(true);
		$data->general = new CrashDumpDataGeneral("Quark", "1.0.0-test", 0, false, 1, "commit", "system", "8.4", "Zend", "Windows", "Windows", []);
		$data->plugin_involvement = CrashDump::PLUGIN_INVOLVEMENT_NONE;
		$data->thread = "Main";
		$data->error = ["type" => "Error", "message" => "sample", "file" => "test.php", "line" => 1, "thread" => "Main"];
		$data->trace = [];
		$dump = $this->createMock(CrashDump::class);
		$dump->method("getData")->willReturn($data);
		$dump->method("encodeData")->willReturnCallback(static function(CrashDumpRenderer $renderer) : void{ $renderer->addLine("\x1b[31mencoded data\x1b[0m"); });
		$write = new \ReflectionMethod(Server::class, "writeCrashDumpFile");
		$first = $write->invoke($server, $dump);
		$second = $write->invoke($server, $dump);
		self::assertIsString($first);
		self::assertIsString($second);
		self::assertNotSame($first, $second);
		$firstReport = file_get_contents($first);
		$secondReport = file_get_contents($second);
		self::assertIsString($firstReport);
		self::assertIsString($secondReport);
		self::assertStringContainsString("encoded data", $firstReport);
		self::assertStringContainsString("encoded data", $secondReport);
		self::assertStringNotContainsString("\x1b", $firstReport);
		self::assertStringNotContainsString("\x1b", $secondReport);
		$failed = $this->createMock(CrashDump::class);
		$failed->method("getData")->willReturn($data);
		$failed->method("encodeData")->willThrowException(new \RuntimeException("render failed"));
		try{
			$write->invoke($server, $failed);
			self::fail("Incomplete crash report must not be published");
		}catch(\RuntimeException $e){
			self::assertSame("render failed", $e->getMessage());
		}
		$reports = glob($this->root . "/diagnostics/crashes/*.txt");
		self::assertIsArray($reports);
		self::assertCount(2, $reports);
		self::assertSame([], glob($this->root . "/diagnostics/crashes/*.tmp"));
	}
	public function testShutdownRacingActiveProducersAccountsForEveryEvent() : void{
		$file = $this->root . "/shutdown.log";
		$writer = new LogDispatcher($file, null);
		$writer->start(Thread::INHERIT_NONE);
		$workers = [];
		for($i = 0; $i < 4; ++$i){
			$worker = new LoggingProducer($writer, "worker$i");
			$worker->start(Thread::INHERIT_NONE);
			$workers[] = $worker;
		}
		$writer->shutdown();
		foreach($workers as $worker){ $worker->join(); }
		$stats = $writer->getQueue()->stats();
		self::assertSame(800, $stats["accepted"] + $stats["dropped"]);
		self::assertSame($stats["accepted"], preg_match_all("/worker[0-3]:[0-9]+/", Filesystem::fileGetContents($file)));
		self::assertSame(0, $stats["queued"]);
		$writer->shutdown();
	}

}
