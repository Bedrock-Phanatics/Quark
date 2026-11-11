<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use pmmp\thread\Thread;
use quark\console\ConsoleCommandSender;
use quark\lang\Language;
use quark\log\ConsoleSink;
use quark\log\Event;
use quark\log\EventQueue;
use quark\log\FileSink;
use quark\log\JsonFormatter;
use quark\log\LogDispatcher;
use quark\log\LoggingConfig;
use quark\log\SourceLogger;
use quark\log\TextFormatter;
use quark\plugin\PluginLogger;
use quark\thread\log\ThreadSafeLoggerAttachment;
use quark\utils\Filesystem;
use quark\utils\MainLogger;
use quark\utils\Terminal;
use function array_keys;
use function array_map;
use function bin2hex;
use function dirname;
use function explode;
use function extension_loaded;
use function fclose;
use function file_exists;
use function file_put_contents;
use function flock;
use function fopen;
use function function_exists;
use function getenv;
use function glob;
use function hrtime;
use function implode;
use function json_decode;
use function mb_strwidth;
use function min;
use function mkdir;
use function pack;
use function posix_mkfifo;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function putenv;
use function random_bytes;
use function str_pad;
use function str_repeat;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const PHP_BINARY;
use const PHP_INT_MAX;
use const PHP_OS_FAMILY;

final class ShortLogStream{
	public mixed $context;
	public static string $data = "";
	public static bool $fail = false;
	public static string $acknowledgements = "";
	public static int $readLimit = PHP_INT_MAX;
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath) : bool{ return true; }
	public function stream_write(string $data) : int{
		if(self::$fail){ return 0; }
		$part = substr($data, 0, 3);
		self::$data .= $part;
		self::$acknowledgements .= pack("N", strlen($part));
		return strlen($part);
	}
	public function stream_read(int $count) : string{
		$count = min($count, self::$readLimit);
		$data = substr(self::$acknowledgements, 0, $count);
		self::$acknowledgements = substr(self::$acknowledgements, $count);
		return $data;
	}
	public function stream_eof() : bool{ return false; }
}

final class LoggingFlushWaiter extends Thread{
	public bool $done = false;
	public string $failure = "";
	public function __construct(private EventQueue $queue){}
	public function run() : void{
		try{ $this->queue->flush(2.0); $this->done = true; }catch(\Throwable $e){ $this->failure = $e->getMessage(); }
	}
}

final class LoggingPipelineTest extends TestCase{
	private string $root;
	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . "/quark-pipeline-" . bin2hex(random_bytes(8));
		mkdir($this->root);
	}
	protected function tearDown() : void{ Filesystem::recursiveUnlink($this->root); }

	public function testAlignedMultilineAndSelectiveAnsi() : void{
		$event = Event::create("warning", "first\nsecond", "Economy");
		$event["time"] = 1704067200.125;
		$plain = (new TextFormatter("UTC", true))->format($event);
		self::assertSame("00:00:00  WARN   Economy           first\n                                   second\n", $plain);
		$ansi = (new TextFormatter("UTC", true, true))->format($event);
		self::assertStringContainsString("\x1b[90m00:00:00", $ansi);
		self::assertStringContainsString("\x1b[33mWARN ", $ansi);
		self::assertSame($plain, Event::clean($ansi));
		$event["source"] = "長い名前のプラグインVeryLongName";
		self::assertSame(35, mb_strwidth(explode("first", (new TextFormatter("UTC", true))->format($event), 2)[0]));
		self::assertStringNotContainsString("\x1b", (new TextFormatter("UTC", true, false, true))->format($event));
	}

	public function testConsoleSeverityPalettePreservesPlainOutput() : void{
		$plain = new TextFormatter("UTC", true);
		$ansi = new TextFormatter("UTC", true, true);
		foreach([
			["emergency", "log", "EMERG", "1;91"],
			["alert", "log", "ALERT", "1;91"],
			["critical", "log", "CRIT", "1;91"],
			["error", "log", "ERROR", "31"],
			["warning", "log", "WARN", "33"],
			["notice", "log", "NOTE", "34"],
			["info", "log", "INFO", "32"],
			["debug", "log", "DEBUG", "90"],
			["info", "command", "CMD", "35"],
			["info", "output", "OUT", "36"]
		] as [$level, $category, $label, $color]){
			$event = Event::create($level, "First line\nSecond line", category: $category);
			$rendered = $ansi->format($event);
			self::assertStringContainsString("\x1b[" . $color . "m" . str_pad($label, 5) . "\x1b[0m", $rendered);
			self::assertSame($plain->format($event), Event::clean($rendered));
		}
	}

	public function testSafeTextAndStructuredRoundTrip() : void{
		$event = Event::create("info", "§ahello\r\n\x1b[31mred\x1b[0m\x1b]0;title\x07\x00", "Plugin\nName", "worker", "output", ["player" => "§bSteve", "count" => 2]);
		$event["time"] = 1704067200.125;
		$event = Event::cleanEvent($event);
		self::assertSame("hello\nred", $event["message"]);
		self::assertSame("Plugin Name", $event["source"]);
		$json = (new JsonFormatter("America/New_York"))->format($event);
		self::assertSame(1, substr_count($json, "\n"));
		$decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		self::assertSame("2023-12-31T19:00:00.125-05:00", $decoded["timestamp"]);
		self::assertSame(["player" => "Steve", "count" => 2], $decoded["context"]);
		self::assertSame("hello\nred", $decoded["message"]);
		self::assertStringNotContainsString("\x1b", $json);
		self::assertLessThanOrEqual(Event::MAX_MESSAGE_BYTES, strlen(Event::create("info", str_repeat("a", 100000))["message"]));
	}

	public function testQueueReservesCapacityAndPreservesAcceptedOrder() : void{
		$queue = new EventQueue(262144);
		self::assertTrue($queue->push(str_repeat("a", 100000), false));
		self::assertFalse($queue->push(str_repeat("b", 100000), false));
		self::assertTrue($queue->push(str_repeat("c", 100000), true));
		self::assertFalse($queue->push(str_repeat("d", 100000), true));
		$batch = $queue->take(1);
		self::assertSame([1, 2], array_keys($batch));
		self::assertSame("a", $batch[1][0]);
		self::assertSame("c", $batch[2][0]);
		self::assertSame(["queued" => 0, "bytes" => 0, "accepted" => 2, "dropped" => 1, "fallback" => 1, "console_dropped" => 0], $queue->stats());
		$queue->close();
		self::assertFalse($queue->push("closed", false));
		self::assertTrue($queue->drained());
	}

	public function testFlushHasABoundedDeadline() : void{
		$queue = new EventQueue();
		$start = hrtime(true);
		try{ $queue->flush(0.01); self::fail("An idle dispatcher cannot acknowledge a flush"); }catch(\RuntimeException $e){
			self::assertSame("Log flush timed out", $e->getMessage());
			self::assertLessThan(1000000000, hrtime(true) - $start);
		}
	}

	public function testCommandResponsesUseReservedQueueCapacity() : void{
		$file = $this->root . "/reserved-commands.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"), configuration: ["buffer-bytes" => 262144, "console-level" => "emergency"]);
		$queue = $logger->getDispatcher()->getQueue();
		try{
			$queue->synchronized(function() use ($logger, $queue) : void{
				for($i = 0; $i < 32; ++$i){ $logger->info(str_repeat("x", 10000)); }
				for($i = 0; $i < 32; ++$i){ $logger->info(str_repeat("x", 256)); }
				$before = $queue->stats();
				self::assertGreaterThan(0, $before["dropped"]);
				$logger->event("info", "reserved command", category: "command");
				$logger->output("reserved command response " . str_repeat("y", 512));
				self::assertSame($before["accepted"] + 2, $queue->stats()["accepted"]);
				self::assertSame(0, $queue->stats()["fallback"]);
			});
		}finally{ $logger->shutdownLogWriterThread(); }
		$content = Filesystem::fileGetContents($file);
		self::assertStringContainsString("reserved command", $content);
		self::assertStringContainsString("reserved command response", $content);
	}

	public function testIndependentThresholdsJsonAndExceptionChains() : void{
		$file = $this->root . "/server.log";
		$writer = new LogDispatcher($file, null, configuration: ["file-level" => "warning", "json" => true, "json-level" => "debug"]);
		$writer->start(Thread::INHERIT_NONE);
		try{
			$writer->submit(Event::create("info", "structured only", "Player", context: ["player" => "Steve"]), false);
			$error = new \RuntimeException("outer", 0, new \LogicException("inner"));
			$writer->submit(Event::create("error", "Save failed", "Storage", exceptions: Event::exceptions($error)), true);
			$writer->syncFlushBuffer();
		}finally{ $writer->shutdown(); }
		$text = Filesystem::fileGetContents($file);
		self::assertStringNotContainsString("structured only", $text);
		self::assertStringContainsString("RuntimeException: outer", $text);
		self::assertStringContainsString("Caused by LogicException: inner", $text);
		self::assertSame(1, substr_count($text, "ERROR"));
		$lines = explode("\n", trim(Filesystem::fileGetContents($file . ".jsonl")), 32);
		self::assertCount(2, $lines);
		/** @var list<array{context: array<string, scalar>, exceptions: list<mixed>}> $events */
		$events = array_map(static fn(string $line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), $lines);
		self::assertSame("Steve", $events[0]["context"]["player"]);
		self::assertCount(2, $events[1]["exceptions"]);
	}

	public function testOverflowAndLateErrorsUseEmergencyFile() : void{
		$file = $this->root . "/server.log";
		$writer = new LogDispatcher($file, null, configuration: ["buffer-bytes" => 262144]);
		for($i = 0; $i < 10; ++$i){ $writer->submit(Event::create("error", "error $i " . str_repeat("a", 60000)), true); }
		self::assertGreaterThan(0, $writer->getQueue()->stats()["fallback"]);
		$writer->start(Thread::INHERIT_NONE);
		$writer->shutdown();
		$writer->submit(Event::create("error", "after shutdown"), true);
		$emergency = Filesystem::fileGetContents($file . ".emergency");
		self::assertStringContainsString("error 9 ", $emergency);
		self::assertStringContainsString("after shutdown", $emergency);
		foreach(explode("\n", trim($emergency), 32) as $line){ self::assertIsArray(json_decode($line, true, 32, JSON_THROW_ON_ERROR)); }
	}

	public function testPartialWritesAndZeroProgress() : void{
		stream_wrapper_register("shortlog", ShortLogStream::class);
		$stream = fopen("shortlog://test", "wb");
		self::assertIsResource($stream);
		ShortLogStream::$data = "";
		ShortLogStream::$fail = false;
		try{
			FileSink::writeAll($stream, "abcdefghij");
			self::assertSame("abcdefghij", ShortLogStream::$data);
			ShortLogStream::$fail = true;
			$this->expectException(\RuntimeException::class);
			FileSink::writeAll($stream, "must fail");
		}finally{ fclose($stream); stream_wrapper_unregister("shortlog"); ShortLogStream::$fail = false; }
	}

	public function testCommandAndPluginOutputAndRecursiveAttachments() : void{
		pulse\internal\PulseZones::init();
		permission\DefaultPermissions::registerCorePermissions();
		$file = $this->root . "/server.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"));
		$attachment = new class($logger) extends ThreadSafeLoggerAttachment{
			public int $calls = 0;
			public function __construct(private MainLogger $logger){}
			public function log(string $level, string $message) : void{
				++$this->calls;
				$this->logger->info("from attachment");
			}
		};
		$logger->addAttachment($attachment);
		try{
			$plugin = new PluginLogger($logger, "Economy");
			$plugin->warning("§6Database delayed");
			$server = $this->createMock(Server::class);
			$server->method("getLogger")->willReturn($logger);
			$sender = new ConsoleCommandSender($server, $this->createMock(Language::class));
			$sender->sendMessage("§aThere are 0 players online\nNext line");
			$logger->debug("disabled debug");
			$logger->debug("enabled debug", true);
			$logger->syncFlushBuffer();
			self::assertSame(3, $attachment->calls);
		}finally{ $logger->removeAttachments(); $logger->shutdownLogWriterThread(); }
		$text = Filesystem::fileGetContents($file);
		self::assertStringContainsString("Economy", $text);
		self::assertStringContainsString("Database delayed", $text);
		self::assertStringContainsString("OUT    Console", $text);
		self::assertStringContainsString("Next line", $text);
		self::assertStringNotContainsString("disabled debug", $text);
		self::assertStringContainsString("enabled debug", $text);
		self::assertStringNotContainsString("§", $text);
	}

	public function testStrictConfigurationValidation() : void{
		foreach([["color" => "auto"], ["file-level" => "warn"], ["buffer-bytes" => 0], ["flush-ms" => 5001], ["json" => 1], ["max-archives" => -1], ["max-file-size" => 1], ["unknown" => true], ["file-level" => null]] as $values){
			try{ new LoggingConfig($values); self::fail("Invalid configuration accepted"); }catch(\InvalidArgumentException $e){ self::assertStringContainsString("logging.", $e->getMessage()); }
		}
		CoreConfig::validateServer(["logging" => ["json" => true, "console-level" => "error"]]);
		self::assertSame(6, (new LoggingConfig())->consoleLevel);
	}

	public function testTerminalColorOverridesAndNoColor() : void{
		$previous = getenv("NO_COLOR");
		putenv("NO_COLOR=1");
		try{
			Terminal::init(); self::assertFalse(Terminal::hasFormattingCodes());
			Terminal::init(true); self::assertTrue(Terminal::hasFormattingCodes());
			Terminal::init(false); self::assertSame("", Terminal::$FORMAT_RESET);
		}finally{ putenv($previous === false ? "NO_COLOR" : "NO_COLOR=$previous"); Terminal::init(false); }
	}
	public function testFlushWatermarkIgnoresLaterTraffic() : void{
		$queue = new EventQueue();
		$queue->push("before", false);
		$waiter = new LoggingFlushWaiter($queue);
		$waiter->start(Thread::INHERIT_NONE);
		$deadline = hrtime(true) + 1000000000;
		while($queue->flushRequest()[0] === 0 && hrtime(true) < $deadline){ usleep(1000); }
		try{
			self::assertSame([1, 1], $queue->flushRequest());
			$queue->push("after", false);
			self::assertSame([1, 1], $queue->flushRequest());
			$queue->acknowledge(1);
		}finally{ $waiter->join(); }
		self::assertTrue($waiter->done, $waiter->failure);
		self::assertSame(2, $queue->stats()["queued"]);
	}

	public function testBlockedConsolePreservesErrorsWithAndWithoutFileLogging() : void{
		foreach([true, false] as $fileEnabled){
			$file = $this->root . "/blocked-" . ($fileEnabled ? "file" : "console") . ".log";
			$done = $file . ".done.json";
			$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$logger = new \quark\utils\MainLogger($argv[4] === "1" ? $argv[2] : null, false, "Test", new DateTimeZone("UTC"));
for($i = 0; $i < 4000; ++$i){
 $logger->info("event $i " . str_repeat("x", 2048));
 if($i % 100 === 0){ $logger->syncFlushBuffer(); }
}
$logger->error("critical diagnostic after blocked output");
$logger->syncFlushBuffer();
$logger->shutdownLogWriterThread();
file_put_contents($argv[3], json_encode($logger->getDispatcher()->getQueue()->stats(), JSON_THROW_ON_ERROR));
PHP;
			$process = proc_open([PHP_BINARY, "-dopcache.enable_cli=0", "-dsys_temp_dir=" . $this->root, "-r", $code, dirname(__DIR__, 2), $file, $done, $fileEnabled ? "1" : "0"], [0 => ["pipe", "r"], 1 => ["socket"], 2 => ["pipe", "w"]], $pipes);
			self::assertIsResource($process);
			fclose($pipes[0]);
			try{
				$deadline = hrtime(true) + 10000000000;
				while(proc_get_status($process)["running"] && hrtime(true) < $deadline){ usleep(10000); }
				self::assertFalse(proc_get_status($process)["running"], "Blocked output prevented shutdown");
				self::assertFileExists($done);
				$errors = stream_get_contents($pipes[2]);
				if(!$fileEnabled){
					$journals = glob($this->root . "/qua*");
					self::assertIsArray($journals);
					$errors .= implode("", array_map(Filesystem::fileGetContents(...), $journals));
				}
				self::assertStringContainsString("critical diagnostic after blocked output", $fileEnabled ? Filesystem::fileGetContents($file) : $errors);
				$stats = json_decode(Filesystem::fileGetContents($done), true, 16, JSON_THROW_ON_ERROR);
				self::assertIsArray($stats);
				self::assertGreaterThan(0, $stats["console_dropped"]);
				self::assertSame(0, $stats["dropped"]);
				if($fileEnabled){
					self::assertStringContainsString("Console dropped", Filesystem::fileGetContents($file));
					self::assertStringContainsString("Console abandoned", Filesystem::fileGetContents($file . ".emergency"));
				}
			}finally{
				if(proc_get_status($process)["running"]){ proc_terminate($process); }
				fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
			}
		}
	}

	public function testBufferedClosureFailureStillDrainsMessages() : void{
		$file = $this->root . "/batch.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"));
		try{
			try{
				$logger->buffer(function() use ($logger) : void{
					$logger->info("batch first");
					$logger->buffer(fn() => $logger->warning("batch nested"));
					throw new \RuntimeException("callback failed");
				});
			}catch(\RuntimeException $e){ self::assertSame("callback failed", $e->getMessage()); }
			$logger->info("after batch");
		}finally{ $logger->shutdownLogWriterThread(); }
		$content = Filesystem::fileGetContents($file);
		self::assertStringContainsString("batch first", $content);
		self::assertStringContainsString("batch nested", $content);
		self::assertStringContainsString("after batch", $content);
		self::assertLessThan(strpos($content, "after batch"), strpos($content, "batch nested"));
	}

	public function testBlockedConsoleAndStderrDoNotPreventShutdown() : void{
		$done = $this->root . "/blocked-shutdown.json";
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$logger = new \quark\utils\MainLogger(null, false, "Test", new DateTimeZone("UTC"));
for($i = 0; $i < 1000; ++$i){
 $logger->output("pending command $i " . str_repeat("x", 2048));
 if($i % 100 === 0){ $logger->syncFlushBuffer(); }
}
$logger->error("error with both console streams blocked");
$logger->shutdownLogWriterThread();
file_put_contents($argv[2], json_encode($logger->getDispatcher()->getQueue()->stats(), JSON_THROW_ON_ERROR));
PHP;
		$process = proc_open([PHP_BINARY, "-dopcache.enable_cli=0", "-dsys_temp_dir=" . $this->root, "-r", $code, dirname(__DIR__, 2), $done], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
		self::assertIsResource($process);
		fclose($pipes[0]);
		try{
			$deadline = hrtime(true) + 3000000000;
			while(proc_get_status($process)["running"] && hrtime(true) < $deadline){ usleep(1000); }
			self::assertFalse(proc_get_status($process)["running"], "Full stderr blocked emergency persistence during shutdown");
			self::assertFileExists($done);
			$journals = glob($this->root . "/qua*");
			self::assertIsArray($journals);
			$content = implode("", array_map(Filesystem::fileGetContents(...), $journals));
			self::assertStringContainsString("error with both console streams blocked", $content);
			self::assertStringContainsString("pending command 999", $content);
			self::assertSame(1, substr_count($content, "pending command 999"));
		}finally{
			if(proc_get_status($process)["running"]){ proc_terminate($process, 9); }
			fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
		}
	}

	public function testServerShutdownDoesNotWriteToBlockedStdout() : void{
		if(PHP_OS_FAMILY !== "Linux" || !extension_loaded("bedwire") || !extension_loaded("qmath")){
			self::markTestSkipped("Full server shutdown requires the supported Linux runtime");
		}
		$code = <<<'PHP'
$stdout = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
if($stdout === false){ throw new RuntimeException("Cannot create console socket"); }
$server = proc_open([PHP_BINARY, "-dopcache.enable_cli=0", $argv[1] . "/src/Quark.php", "--no-wizard", "--disable-ansi", "--data=" . $argv[2], "--plugins=" . $argv[2] . "/plugins", "--server.level-type=FLAT", "--settings.async-workers=1", "--settings.enable-dev-builds=1", "--server.enable-query=0", "--server.enable-ipv6=0", "--transport.nethernet.lan-discovery=0"], [["pipe", "r"], $stdout[0], ["file", $argv[2] . "/server-errors.log", "ab"]], $pipes);
if($server === false){ throw new RuntimeException("Cannot start server"); }
try{
 $log = $argv[2] . "/logs/server.log";
 $deadline = hrtime(true) + 30000000000;
 while(!is_file($log) || !str_contains(file_get_contents($log), "Done (")){
  if(!proc_get_status($server)["running"] || hrtime(true) >= $deadline){ throw new RuntimeException("Server did not start: " . file_get_contents($argv[2] . "/server-errors.log")); }
  usleep(10000);
 }
 if(!stream_set_blocking($stdout[0], false)){ throw new RuntimeException("Cannot make stdout nonblocking"); }
 foreach([4096, 1] as $size){ while(@fwrite($stdout[0], str_repeat("x", $size)) === $size){} }
 fwrite($pipes[0], "stop\n");
 $deadline = hrtime(true) + 4000000000;
 do{
  $status = proc_get_status($server);
  if(!$status["running"]){ break; }
  usleep(1000);
 }while(hrtime(true) < $deadline);
 if($status["running"] || $status["exitcode"] !== 0){ throw new RuntimeException("Blocked stdout prevented clean server shutdown"); }
}finally{
 if(proc_get_status($server)["running"]){ proc_terminate($server, 9); }
 foreach($pipes as $pipe){ fclose($pipe); }
 foreach($stdout as $stream){ fclose($stream); }
 proc_close($server);
}
PHP;
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $this->root], 40.0);
		self::assertSame(0, $exit, $output . $errors);
	}

	public function testFlushFailuresDoNotDuplicateAcceptedEvents() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
final class FlushFailingSink implements \quark\log\Sink{
 public static bool $console;
 private ?array $event = null;
 private ?string $emergency;
 public function __construct(...$args){ $this->emergency = self::$console ? $args[1] : $args[0] . ".emergency"; }
 public function write(array $event) : bool{ $this->event = $event; return true; }
 public function flush() : void{ throw new RuntimeException("forced flush failure"); }
 public function getDroppedCount() : int{ return 0; }
 public function close() : void{
  if(self::$console && $this->event !== null){
   \quark\log\EmergencyWriter::write($this->emergency, $this->event, "Accepted console event persisted during close");
   $this->event = null;
  }
 }
}
FlushFailingSink::$console = $argv[3] === "console";
class_alias(FlushFailingSink::class, FlushFailingSink::$console ? \quark\log\ConsoleSink::class : \quark\log\FileSink::class);
$writer = new \quark\log\LogDispatcher($argv[2], null, console: true);
$writer->submit(\quark\log\Event::create("error", "accepted event must survive exactly once", category: "output"), true);
$writer->getQueue()->close();
$writer->run();
PHP;
		foreach(["console", "file"] as $sink){
			$file = $this->root . "/$sink-flush-failure.log";
			[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $file, $sink]);
			self::assertSame(0, $exit, $output . $errors);
			$emergency = Filesystem::fileGetContents($file . ".emergency");
			self::assertStringContainsString("$sink sink failed: forced flush failure", $emergency);
			self::assertStringNotContainsString("Dispatcher failure", $emergency);
			self::assertSame(1, substr_count($emergency, "accepted event must survive exactly once"));
		}
	}

	public function testLockedEmergencyFileDoesNotBlockFallback() : void{
		$file = $this->root . "/locked.emergency";
		$stream = fopen($file, "ab");
		self::assertIsResource($stream);
		self::assertTrue(flock($stream, LOCK_EX));
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
\quark\log\EmergencyWriter::write($argv[2], \quark\log\Event::create("error", "locked emergency target survived"), "locked target");
PHP;
		try{
			[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $file], 2.0);
			self::assertSame(0, $exit, $output . $errors);
			self::assertStringContainsString("locked emergency target survived", $errors);
		}finally{ fclose($stream); }
	}

	public function testReplacedEmergencyFileCannotBlockOnAFifo() : void{
		if(!function_exists("posix_mkfifo")){ self::markTestSkipped("FIFO creation requires POSIX"); }
		$file = $this->root . "/replaced.emergency";
		$ready = $this->root . "/ready";
		$go = $this->root . "/go";
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
file_put_contents($argv[2], "");
\quark\log\EmergencyWriter::write($argv[2], \quark\log\Event::create("error", "original file"), "initial write");
file_put_contents($argv[3], "ready");
$deadline = hrtime(true) + 1000000000;
while(!file_exists($argv[4]) && hrtime(true) < $deadline){ usleep(1000); }
if(!file_exists($argv[4])){ exit(2); }
\quark\log\EmergencyWriter::write($argv[2], \quark\log\Event::create("error", "replaced emergency target survived"), "replaced target");
PHP;
		try{
			[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $file, $ready, $go], 3.0, static function(string $output, string $errors) use ($file, $ready, $go) : bool{
				if(file_exists($ready) && !file_exists($go)){
					unlink($file);
					self::assertTrue(posix_mkfifo($file, 0600));
					file_put_contents($go, "go");
				}
				return false;
			});
		}finally{ if(file_exists($file)){ unlink($file); } }
		self::assertSame(0, $exit, $output . $errors);
		self::assertStringContainsString("replaced emergency target survived", $errors);
	}

	public function testFragmentedConsoleAcknowledgementsPreserveOrder() : void{
		stream_wrapper_register("fragmentedlog", ShortLogStream::class);
		ShortLogStream::$data = "";
		ShortLogStream::$acknowledgements = "";
		ShortLogStream::$readLimit = 1;
		$sink = (new \ReflectionClass(ConsoleSink::class))->newInstanceWithoutConstructor();
		$formatter = new TextFormatter("UTC", true);
		(new \ReflectionProperty(ConsoleSink::class, "formatter"))->setValue($sink, $formatter);
		(new \ReflectionProperty(ConsoleSink::class, "socket"))->setValue($sink, fopen("fragmentedlog://output", "wb"));
		$pending = new \ReflectionProperty(ConsoleSink::class, "pending");
		$expected = "";
		try{
			foreach(["first event", "second event"] as $message){
				$event = Event::create("info", $message, category: "output");
				$expected .= $formatter->format($event);
				self::assertTrue($sink->write($event));
			}
			for($attempt = 0; $attempt < 200 && $pending->getValue($sink) !== ""; ++$attempt){ $sink->flush(); }
			self::assertSame("", $pending->getValue($sink));
			self::assertSame([], (new \ReflectionProperty(ConsoleSink::class, "events"))->getValue($sink));
			self::assertSame($expected, ShortLogStream::$data);
		}finally{
			ShortLogStream::$readLimit = PHP_INT_MAX;
			$sink->close();
			stream_wrapper_unregister("fragmentedlog");
		}
	}

	public function testClosedConsoleCannotRestartItsHelper() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$sink = new \quark\log\ConsoleSink(new \quark\log\TextFormatter("UTC", true));
$sink->close();
if($sink->write(\quark\log\Event::create("info", "must not reopen console"))){ exit(2); }
$sink->flush();
$sink->close();
if((new ReflectionProperty($sink, "process"))->getValue($sink) !== null){ exit(3); }
PHP;
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2)]);
		self::assertSame(0, $exit, $output . $errors);
		self::assertSame("", $output . $errors);
	}

	public function testFileFailureDoesNotStopConsoleOrExceptionFallback() : void{
		$file = $this->root . "/failure.log";
		$archive = $this->root . "/archive";
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$logger = new \quark\utils\MainLogger($argv[2], false, "Test", new DateTimeZone("UTC"), logArchiveDir: $argv[3], maxFileSize: 1024, configuration: ["console-level" => "error"]);
$logger->info("before failure");
$logger->syncFlushBuffer();
rmdir($argv[3]);
file_put_contents($argv[3], "rotation blocked");
$logger->error("important error " . str_repeat("x", 2000));
$logger->output("console survived file failure");
$logger->logException(new RuntimeException("crash after file failure"));
try{ $logger->shutdownLogWriterThread(); }catch(RuntimeException){}
PHP;
		$process = proc_open([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $file, $archive], [0 => ["pipe", "r"], 1 => ["socket"], 2 => ["pipe", "w"]], $pipes);
		self::assertIsResource($process);
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		$output = "";
		try{
			$deadline = hrtime(true) + 10000000000;
			while(proc_get_status($process)["running"] && hrtime(true) < $deadline){
				$output .= stream_get_contents($pipes[1]);
				usleep(10000);
			}
			self::assertFalse(proc_get_status($process)["running"]);
			$output .= stream_get_contents($pipes[1]);
			self::assertStringContainsString("console survived file failure", $output);
			$emergency = Filesystem::fileGetContents($file . ".emergency");
			self::assertStringContainsString("important error", $emergency);
			self::assertStringContainsString("crash after file failure", $emergency);
		}finally{
			if(proc_get_status($process)["running"]){ proc_terminate($process); }
			fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
		}
	}

	public function testSubprocessDrainsBothStreamsAndBoundsWaits() : void{
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-r", 'fwrite(STDERR, str_repeat("e", 262144)); echo str_repeat("o", 262144);']);
		self::assertSame(0, $exit);
		self::assertSame(str_repeat("o", 262144), $output);
		self::assertSame(str_repeat("e", 262144), $errors);
		$start = hrtime(true);
		try{ ProcessTestHelper::run([PHP_BINARY, "-r", 'sleep(30);'], 0.1); self::fail("Stalled child must time out"); }catch(\RuntimeException $e){
			self::assertStringContainsString("Test subprocess timed out", $e->getMessage());
			self::assertLessThan(2000000000, hrtime(true) - $start);
		}
	}

	public function testAcceptedConsoleErrorRemainsQueuedUntilFlushed() : void{
		stream_wrapper_register("blockedlog", ShortLogStream::class);
		ShortLogStream::$fail = true;
		ShortLogStream::$data = "";
		ShortLogStream::$acknowledgements = "";
		$sink = (new \ReflectionClass(ConsoleSink::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ConsoleSink::class, "formatter"))->setValue($sink, new TextFormatter("UTC", true));
		(new \ReflectionProperty(ConsoleSink::class, "socket"))->setValue($sink, fopen("blockedlog://output", "wb"));
		$pending = new \ReflectionProperty(ConsoleSink::class, "pending");
		try{
			self::assertSame("", $pending->getValue($sink));
			self::assertTrue($sink->write(Event::create("error", "first unsent error")));
			$sink->flush();
			$unsent = $pending->getValue($sink);
			self::assertIsString($unsent);
			self::assertStringContainsString("first unsent error", $unsent);
			self::assertSame("", ShortLogStream::$data);
			ShortLogStream::$fail = false;
			$sink->flush();
			self::assertSame("", $pending->getValue($sink));
			self::assertStringContainsString("first unsent error", ShortLogStream::$data);
		}finally{
			ShortLogStream::$fail = false;
			$sink->close();
			stream_wrapper_unregister("blockedlog");
		}
	}

	public function testPluginAttachmentsDoNotReenterAndRecoverAfterFailure() : void{
		$file = $this->root . "/attachments.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"), configuration: ["console-level" => "emergency"]);
		$plugin = new PluginLogger($logger, "Plugin");
		$calls = 0;
		$plugin->addAttachment(static function() use ($plugin, &$calls) : void{
			if(++$calls <= 2){ $plugin->info("from plugin attachment"); }
		});
		try{
			$plugin->info("original message");
			self::assertSame(1, $calls);
			$plugin->removeAttachments();
			$plugin->addAttachment(static function() use (&$calls) : void{
				if(++$calls === 2){ throw new \RuntimeException("attachment failed"); }
			});
			try{ $plugin->info("throwing attachment"); self::fail("Attachment failure must propagate"); }catch(\RuntimeException $e){
				self::assertSame("attachment failed", $e->getMessage());
			}
			$plugin->info("after attachment failure");
			self::assertSame(3, $calls);
		}finally{ $logger->shutdownLogWriterThread(); }
		$content = Filesystem::fileGetContents($file);
		self::assertSame(1, substr_count($content, "original message"));
		self::assertSame(1, substr_count($content, "from plugin attachment"));
		self::assertStringContainsString("after attachment failure", $content);
	}

	public function testStandardLoggerOverridesRemainCompatible() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
class CustomSourceLogger extends \quark\log\SourceLogger{
 public function log($level, $message){ parent::log($level, $message); }
 public function logException(\Throwable $e, $trace = null){ parent::logException($e, $trace); }
}
class CustomPluginLogger extends \quark\plugin\PluginLogger{
 public int $messages = 0;
 public int $exceptions = 0;
 public function log($level, $message){ ++$this->messages; parent::log($level, "transformed " . $message); }
 public function logException(\Throwable $e, $trace = null){ ++$this->exceptions; parent::logException($e, $trace); }
}
$logger = new \quark\utils\MainLogger($argv[2], false, "Test", new DateTimeZone("UTC"), configuration: ["console-level" => "emergency"]);
$plugin = new CustomPluginLogger($logger, "Plugin");
$source = new CustomSourceLogger($plugin, "Source");
$source->info("custom subclass message");
$source->logException(new RuntimeException("custom subclass exception"));
$logger->shutdownLogWriterThread();
if($plugin->messages !== 1 || $plugin->exceptions !== 1){ exit(2); }
PHP;
		$file = $this->root . "/subclass.log";
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $file]);
		self::assertSame(0, $exit, $output . $errors);
		$content = Filesystem::fileGetContents($file);
		self::assertStringContainsString("Plugin / Source", $content);
		self::assertStringContainsString("transformed custom subclass message", $content);
		self::assertStringContainsString("custom subclass exception", $content);
	}

	public function testNestedSourcesPreserveStructuredMessagesAndExceptions() : void{
		$file = $this->root . "/nested.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"), configuration: ["console-level" => "emergency", "json" => true]);
		$plugin = new PluginLogger($logger, "Plugin");
		$world = new SourceLogger($plugin, "World");
		$chunk = new SourceLogger($world, "Chunk");
		$attached = [];
		$plugin->addAttachment(static function($level, $message) use (&$attached) : void{ $attached[] = [$level, $message]; });
		try{
			$chunk->info("chunk loaded");
			$world->setPrefix("Renamed world");
			$chunk->logException(new \RuntimeException("chunk failed"));
		}finally{ $logger->shutdownLogWriterThread(); }
		/** @var list<array{source: string, message: string, exceptions: list<array{message: string}>}> $events */
		$events = array_map(static fn(string $line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), explode("\n", trim(Filesystem::fileGetContents($file . ".jsonl")), 32));
		self::assertSame("Plugin / World / Chunk", $events[0]["source"]);
		self::assertSame("chunk loaded", $events[0]["message"]);
		self::assertSame("Plugin / Renamed world / Chunk", $events[1]["source"]);
		self::assertSame("chunk failed", $events[1]["exceptions"][0]["message"]);
		self::assertSame([["info", "chunk loaded"]], $attached);
	}

	public function testDisabledProcOpenRetainsEveryAcceptedEvent() : void{
		foreach([false, true] as $fileEnabled){
			$file = $this->root . "/disabled-" . ($fileEnabled ? "file" : "console") . ".log";
			$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$logger = new \quark\utils\MainLogger($argv[3] === "1" ? $argv[2] : null, false, "Test", new DateTimeZone("UTC"));
$logger->info("routine event survives");
$logger->output("command response survives");
$logger->error("error event survives");
$logger->syncFlushBuffer();
$logger->shutdownLogWriterThread();
PHP;
			[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-ddisable_functions=proc_open", "-derror_log=" . $this->root . "/php-errors.log", "-r", $code, dirname(__DIR__, 2), $file, $fileEnabled ? "1" : "0"]);
			self::assertSame(0, $exit, $output . $errors);
			$content = $fileEnabled ? Filesystem::fileGetContents($file) : $errors;
			foreach(["routine event survives", "command response survives", "error event survives"] as $message){
				self::assertStringContainsString($message, $content);
			}
			$fallback = $fileEnabled ? Filesystem::fileGetContents($file . ".emergency") : $errors;
			self::assertStringContainsString("command response survives", $fallback);
			self::assertStringContainsString("error event survives", $fallback);
			self::assertStringContainsString("console sink failed", $fileEnabled ? Filesystem::fileGetContents($file . ".emergency") : $errors);
		}
	}

	public function testConsoleTransportDeliversOrderedOutputAndClosesHelper() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
stream_set_blocking(STDOUT, false);
$sink = new \quark\log\ConsoleSink(new \quark\log\TextFormatter("UTC", true));
$socket = new ReflectionProperty($sink, "socket");
$process = new ReflectionProperty($sink, "process");
if(stream_get_meta_data($socket->getValue($sink))["blocked"]){ exit(2); }
for($i = 0; $i < 1000; ++$i){
 $event = \quark\log\Event::create("info", "event $i " . str_repeat("x", 1024), "Transport");
 $event["time"] = 1704067200.0;
 $sink->write($event);
 if($i % 16 === 0){ $sink->flush(); }
}
$sink->close();
if($process->getValue($sink) !== null || $socket->getValue($sink) !== null){ exit(3); }
$sink->close();
PHP;
		$process = proc_open([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2)], [0 => ["pipe", "r"], 1 => PHP_OS_FAMILY === "Windows" ? ["socket"] : ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
		self::assertIsResource($process);
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		$output = "";
		try{
			usleep(250000);
			$deadline = hrtime(true) + 10000000000;
			$status = proc_get_status($process);
			while($status["running"] && hrtime(true) < $deadline){
				$output .= stream_get_contents($pipes[1]);
				usleep(1000);
				$status = proc_get_status($process);
			}
			self::assertFalse($status["running"], "Console helper did not shut down");
			$output .= stream_get_contents($pipes[1]);
			self::assertSame(0, $status["exitcode"], stream_get_contents($pipes[2]));
			$expected = "";
			$formatter = new TextFormatter("UTC", true);
			for($i = 0; $i < 1000; ++$i){
				$event = Event::create("info", "event $i " . str_repeat("x", 1024), "Transport");
				$event["time"] = 1704067200.0;
				$expected .= $formatter->format($event);
			}
			self::assertSame($expected, $output);
		}finally{
			if(proc_get_status($process)["running"]){ proc_terminate($process); }
			fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
		}
	}

	public function testConsoleRecoveryRetainsCommandsAndUnacknowledgedOutput() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$sink = new \quark\log\ConsoleSink(new \quark\log\TextFormatter("UTC", true));
$process = new ReflectionProperty($sink, "process");
$pending = new ReflectionProperty($sink, "pending");
$sink->write(\quark\log\Event::create("info", "command before failure", category: "command"));
$deadline = hrtime(true) + 1000000000;
do{ $sink->flush(); usleep(1000); }while($pending->getValue($sink) !== "" && hrtime(true) < $deadline);
if($pending->getValue($sink) !== ""){ exit(2); }
$helper = $process->getValue($sink);
if(PHP_OS_FAMILY !== "Windows"){
 proc_terminate($helper, 19);
 usleep(10000);
 $sink->write(\quark\log\Event::create("info", "unacknowledged output survives", category: "output"));
 $sink->flush();
}
proc_terminate($helper, 9);
$deadline = hrtime(true) + 1000000000;
while(proc_get_status($helper)["running"] && hrtime(true) < $deadline){ usleep(1000); }
if(proc_get_status($helper)["running"]){ exit(3); }
$sink->write(\quark\log\Event::create("info", "command during failure", category: "command"));
$sink->flush();
$sink->write(\quark\log\Event::create("info", "Pulse output after recovery", category: "output"));
$deadline = hrtime(true) + 1000000000;
do{ $sink->flush(); usleep(1000); }while($pending->getValue($sink) !== "" && hrtime(true) < $deadline);
if($pending->getValue($sink) !== ""){ exit(4); }
$helper = $process->getValue($sink);
proc_terminate($helper, 9);
$deadline = hrtime(true) + 1000000000;
while(proc_get_status($helper)["running"] && hrtime(true) < $deadline){ usleep(1000); }
if(proc_get_status($helper)["running"]){ exit(5); }
$sink->write(\quark\log\Event::create("info", "command during stderr fallback", category: "command"));
$sink->flush();
$sink->write(\quark\log\Event::create("info", "Pulse output after stderr fallback", category: "output"));
$sink->close();
if($process->getValue($sink) !== null){ exit(6); }
PHP;
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2)]);
		self::assertSame(0, $exit, $output . $errors);
		foreach(["command before failure", "command during failure", "Pulse output after recovery"] as $message){
			self::assertSame(1, substr_count($output, $message));
		}
		if(PHP_OS_FAMILY !== "Windows"){ self::assertSame(1, substr_count($output, "unacknowledged output survives")); }
		self::assertSame(1, substr_count($errors, "command during stderr fallback"));
		self::assertSame(1, substr_count($errors, "Pulse output after stderr fallback"));
	}

	public function testConsoleRecoveryStopsAfterStderrFallback() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$sink = new \quark\log\ConsoleSink(new \quark\log\TextFormatter("UTC", true), $argv[2]);
$process = new ReflectionProperty($sink, "process");
try{
 for($i = 0; $i < 3; ++$i){
  $helper = $process->getValue($sink);
  proc_terminate($helper, 9);
  $deadline = hrtime(true) + 1000000000;
  while(proc_get_status($helper)["running"] && hrtime(true) < $deadline){ usleep(1000); }
  if(proc_get_status($helper)["running"]){ exit(2); }
  $sink->write(\quark\log\Event::create("info", "recovery $i", category: "command"));
  $sink->flush();
 }
 exit(3);
}catch(RuntimeException $e){
 if($e->getMessage() !== "Console output process disconnected"){ throw $e; }
}finally{ unset($sink); }
PHP;
		$file = $this->root . "/exhausted-console.emergency";
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $file]);
		self::assertSame(0, $exit, $output . $errors);
		self::assertStringContainsString("recovery 2", Filesystem::fileGetContents($file));
	}

	public function testHealthyConsoleOnlyErrorsAreNotDuplicated() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$logger = new \quark\utils\MainLogger(null, false, "Test", new DateTimeZone("UTC"));
$logger->error("healthy console error");
$logger->shutdownLogWriterThread();
PHP;
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2)]);
		self::assertSame(0, $exit, $output . $errors);
		self::assertSame(1, substr_count($output . $errors, "healthy console error"));
		self::assertSame("", $errors);
	}

	public function testStderrTransportClosesWithoutLeakingDescriptors() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$before = PHP_OS_FAMILY === "Linux" ? count(glob("/proc/self/fd/*")) : null;
for($i = 0; $i < 10; ++$i){
 $sink = new \quark\log\ConsoleSink(new \quark\log\TextFormatter("UTC", true), stderr: true);
 $sink->write(\quark\log\Event::create("info", "stderr fallback $i"));
 $sink->close();
 unset($sink);
}
if($before !== null && count(glob("/proc/self/fd/*")) !== $before){ exit(2); }
PHP;
		[$exit, $output, $errors] = ProcessTestHelper::run([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2)]);
		self::assertSame(0, $exit, $output . $errors);
		self::assertSame("", $output);
		self::assertSame(10, substr_count($errors, "stderr fallback"));
	}

	public function testBrokenStdoutFallsBackToStderrWithFileLoggingDisabled() : void{
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$logger = new \quark\utils\MainLogger(null, false, "Test", new DateTimeZone("UTC"));
$logger->output("detect broken stdout");
$logger->syncFlushBuffer();
usleep(100000);
$logger->output("command response after broken stdout");
$logger->output("Pulse output after broken stdout");
$logger->error("critical error after broken stdout");
$logger->shutdownLogWriterThread();
PHP;
		$process = proc_open([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2)], [0 => ["pipe", "r"], 1 => ["socket"], 2 => ["pipe", "w"]], $pipes);
		self::assertIsResource($process);
		fclose($pipes[0]);
		fclose($pipes[1]);
		try{
			$deadline = hrtime(true) + 3000000000;
			while(proc_get_status($process)["running"] && hrtime(true) < $deadline){ usleep(1000); }
			self::assertFalse(proc_get_status($process)["running"], "Broken stdout prevented shutdown");
			$errors = stream_get_contents($pipes[2]);
			foreach(["command response after broken stdout", "Pulse output after broken stdout", "critical error after broken stdout"] as $message){
				self::assertStringContainsString($message, $errors);
			}
		}finally{
			if(proc_get_status($process)["running"]){ proc_terminate($process, 9); }
			fclose($pipes[2]); proc_close($process);
		}
	}

	public function testIdleQueueWaitsAfterFlushAcknowledgement() : void{
		$queue = new EventQueue();
		$waiter = new LoggingFlushWaiter($queue);
		$waiter->start(Thread::INHERIT_NONE);
		$deadline = hrtime(true) + 1000000000;
		while($queue->flushRequest()[0] === 0 && hrtime(true) < $deadline){ usleep(1000); }
		try{ $queue->acknowledge(1); }finally{ $waiter->join(); }
		self::assertTrue($waiter->done, $waiter->failure);
		$start = hrtime(true);
		self::assertSame([], $queue->take(50000));
		self::assertGreaterThanOrEqual(40000000, hrtime(true) - $start);
	}

	public function testJsonRotationKeepsCompleteRecords() : void{
		$file = $this->root . "/structured.jsonl";
		$archive = $this->root . "/archive";
		$sink = new FileSink($file, $archive, 1024, 2, new JsonFormatter("UTC"));
		for($i = 0; $i < 20; ++$i){ $sink->write(Event::create("info", "event $i " . str_repeat("x", 200))); }
		$sink->close();
		$files = glob($archive . "/structured.*.jsonl");
		self::assertIsArray($files);
		self::assertCount(2, $files);
		$files[] = $file;
		foreach($files as $path){
			foreach(explode("\n", trim(Filesystem::fileGetContents($path)), 64) as $line){
				self::assertIsArray(json_decode($line, true, 32, JSON_THROW_ON_ERROR));
			}
		}
	}

	public function testMultilineAmplificationIsBounded() : void{
		$event = Event::create("error", str_repeat("\n", Event::MAX_MESSAGE_BYTES));
		$formatted = (new TextFormatter("UTC", true))->format($event);
		self::assertSame(1025, substr_count($formatted, "\n"));
		self::assertStringContainsString("additional lines truncated", $formatted);
		self::assertLessThan(65536, strlen($formatted));
	}

	public function testConfiguredSinkThresholdsFilterDebugUnlessForced() : void{
		$file = $this->root . "/threshold.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"), configuration: ["console-level" => "error", "file-level" => "info"]);
		try{
			$logger->debug("filtered debug");
			$logger->debug("explicitly forced debug", true);
		}finally{ $logger->shutdownLogWriterThread(); }
		$content = Filesystem::fileGetContents($file);
		self::assertStringNotContainsString("filtered debug", $content);
		self::assertStringContainsString("explicitly forced debug", $content);
	}

	public function testFileDebugThresholdEnablesDebugBeforeTransport() : void{
		$file = $this->root . "/debug.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"), configuration: ["console-level" => "error", "file-level" => "debug"]);
		try{
			$logger->debug("file debug diagnostics");
		}finally{ $logger->shutdownLogWriterThread(); }
		self::assertStringContainsString("file debug diagnostics", Filesystem::fileGetContents($file));
	}

	public function testConcurrentFlushRequestsCannotMoveAnActiveWatermark() : void{
		$queue = new EventQueue();
		$queue->push("first", false);
		$first = new LoggingFlushWaiter($queue);
		$second = new LoggingFlushWaiter($queue);
		$ticket = new \ReflectionProperty(EventQueue::class, "flushTicket");
		$first->start(Thread::INHERIT_NONE);
		$deadline = hrtime(true) + 1000000000;
		while($ticket->getValue($queue) !== 1 && hrtime(true) < $deadline){ usleep(1000); }
		self::assertSame([1, 1], $queue->flushRequest());
		$queue->push("second", false);
		$second->start(Thread::INHERIT_NONE);
		try{
			$deadline = hrtime(true) + 1000000000;
			while($ticket->getValue($queue) !== 2 && hrtime(true) < $deadline){ usleep(1000); }
			self::assertSame(2, $ticket->getValue($queue));
			self::assertSame([1, 1], $queue->flushRequest());
			$queue->acknowledge(1);
			$first->join();
			self::assertTrue($first->done, $first->failure);
			self::assertFalse($second->done);
			self::assertSame([2, 2], $queue->flushRequest());
			$queue->acknowledge(2);
		}finally{
			if(!$first->isJoined()){ $first->join(); }
			$second->join();
		}
		self::assertTrue($second->done, $second->failure);
	}

}
