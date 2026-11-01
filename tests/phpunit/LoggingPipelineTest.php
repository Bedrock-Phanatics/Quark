<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use pmmp\thread\Thread;
use quark\console\ConsoleCommandSender;
use quark\lang\Language;
use quark\log\Event;
use quark\log\EventQueue;
use quark\log\FileSink;
use quark\log\JsonFormatter;
use quark\log\LogDispatcher;
use quark\log\LoggingConfig;
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
use function fclose;
use function fopen;
use function getenv;
use function glob;
use function hrtime;
use function json_decode;
use function mb_strwidth;
use function mkdir;
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
use function usleep;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class ShortLogStream{
	public mixed $context;
	public static string $data = "";
	public static bool $fail = false;
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath) : bool{ return true; }
	public function stream_write(string $data) : int{
		if(self::$fail){ return 0; }
		$part = substr($data, 0, 3);
		self::$data .= $part;
		return strlen($part);
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
			$logger->setLogDebug(true);
			$logger->debug("enabled debug");
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
		foreach([["color" => "rainbow"], ["file-level" => "warn"], ["buffer-bytes" => 0], ["flush-ms" => 5001], ["json" => 1], ["max-archives" => -1], ["max-file-size" => 1], ["unknown" => true], ["file-level" => null]] as $values){
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

	public function testBlockedConsoleCannotStopFileDiagnostics() : void{
		$file = $this->root . "/blocked.log";
		$done = $this->root . "/done.json";
		$code = <<<'PHP'
require $argv[1] . "/vendor/autoload.php";
$logger = new \quark\utils\MainLogger($argv[2], false, "Test", new DateTimeZone("UTC"));
for($i = 0; $i < 4000; ++$i){
 $logger->info("event $i " . str_repeat("x", 2048));
 if($i % 100 === 0){ $logger->syncFlushBuffer(); }
}
$logger->error("critical diagnostic after blocked output");
$logger->syncFlushBuffer();
$logger->shutdownLogWriterThread();
file_put_contents($argv[3], json_encode($logger->getDispatcher()->getQueue()->stats(), JSON_THROW_ON_ERROR));
PHP;
		$process = proc_open([PHP_BINARY, "-dopcache.enable_cli=0", "-r", $code, dirname(__DIR__, 2), $file, $done], [0 => ["pipe", "r"], 1 => ["socket"], 2 => ["pipe", "w"]], $pipes);
		self::assertIsResource($process);
		fclose($pipes[0]);
		try{
			$deadline = hrtime(true) + 10000000000;
			while(proc_get_status($process)["running"] && hrtime(true) < $deadline){ usleep(10000); }
			self::assertFalse(proc_get_status($process)["running"], "Blocked output prevented shutdown");
			self::assertFileExists($done);
			self::assertStringContainsString("critical diagnostic after blocked output", Filesystem::fileGetContents($file));
			$stats = json_decode(Filesystem::fileGetContents($done), true, 16, JSON_THROW_ON_ERROR);
			self::assertIsArray($stats);
			self::assertGreaterThan(0, $stats["console_dropped"]);
			self::assertSame(0, $stats["dropped"]);
			self::assertStringContainsString("Console dropped", Filesystem::fileGetContents($file));
		}finally{
			if(proc_get_status($process)["running"]){ proc_terminate($process); }
			fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
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

	public function testConfiguredSinkThresholdsOverrideLegacyDebugFlag() : void{
		$file = $this->root . "/threshold.log";
		$logger = new MainLogger($file, false, "Test", new \DateTimeZone("UTC"), true, configuration: ["console-level" => "error", "file-level" => "info"]);
		try{
			$logger->debug("filtered debug");
			$logger->debug("explicitly forced debug", true);
		}finally{ $logger->shutdownLogWriterThread(); }
		$content = Filesystem::fileGetContents($file);
		self::assertStringNotContainsString("filtered debug", $content);
		self::assertStringContainsString("explicitly forced debug", $content);
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
