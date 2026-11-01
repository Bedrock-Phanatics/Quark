<?php

declare(strict_types=1);

$options = getopt("", ["root:", "count:", "bytes:", "producers:", "batch:", "json", "micro"]);
$root = $options["root"] ?? dirname(__DIR__, 2);
if(!is_string($root)){ throw new InvalidArgumentException("Benchmark root must be a path"); }
require $root . "/vendor/autoload.php";

if(isset($options["micro"])){
	$events = [];
	foreach(["Player" => "Steve connected", "World" => "Spawn loaded 24 chunks", "Economy" => "Saved balances", "Network" => "Retransmitted 3 packets"] as $source => $message){
		$events[] = quark\log\Event::create("info", $message, $source, context: ["tick" => 1234]);
	}
	$iterations = 20000;
	$report = [];
	foreach(["plain" => new quark\log\TextFormatter("UTC", true), "ansi" => new quark\log\TextFormatter("UTC", true, true), "json" => new quark\log\JsonFormatter("UTC")] as $name => $formatter){
		$start = hrtime(true);
		for($i = 0; $i < $iterations; ++$i){ $formatter->format($events[$i % 4]); }
		$report[$name . "_format_ns"] = (hrtime(true) - $start) / $iterations;
	}
	$file = tempnam(sys_get_temp_dir(), "quark-sink-bench-");
	if($file === false){ throw new RuntimeException("Cannot create sink benchmark file"); }
	$sink = new quark\log\FileSink($file, null, 33554432, 0, new quark\log\TextFormatter("UTC"));
	$start = hrtime(true);
	for($i = 0; $i < $iterations; ++$i){ $sink->write($events[$i % 4]); }
	$flush = hrtime(true);
	$sink->flush();
	$report["file_flush_ms"] = (hrtime(true) - $flush) / 1e6;
	$report["file_events_per_s"] = $iterations / ((hrtime(true) - $start) / 1e9);
	$sink->close();
	$report["heap_peak"] = memory_get_peak_usage(true);
	$report["file_bytes"] = filesize($file);
	unlink($file);
	fwrite(STDERR, json_encode($report, JSON_THROW_ON_ERROR) . "\n");
	exit(0);
}

final class LoggingBenchmarkProducer extends pmmp\thread\Thread{
	public string $result = "";
	public function __construct(private quark\utils\MainLogger $logger, private string $root, private int $count, private int $bytes, private int $producer, private int $batch){}
	public function run() : void{
		require_once $this->root . "/vendor/autoload.php";
		$samples = [];
		$total = 0;
		$messages = ["Player Steve connected from 127.0.0.1", "World spawn loaded 24 chunks", "Plugin Economy saved balances", "Network retransmitted 3 packets", "Storage completed checkpoint"];
		$padding = str_repeat("x", max(0, $this->bytes - 64));
		$start = hrtime(true);
		for($i = 0; $i < $this->count; ++$i){
			$message = $messages[$i % count($messages)] . " producer=$this->producer sequence=$i " . $padding;
			$before = hrtime(true);
			$kind = $i % 100;
			if($kind < 5){ $this->logger->debug($message); }
			elseif($kind < 7){ $this->logger->error($message); }
			elseif($kind < 17){ $this->logger->warning($message); }
			else{ $this->logger->info($message); }
			$latency = hrtime(true) - $before;
			$total += $latency;
			if(($i & 15) === 0){ $samples[] = $latency; }
			if($this->batch > 0 && ($i + 1) % $this->batch === 0){ $this->logger->syncFlushBuffer(); }
		}
		sort($samples);
		$this->result = json_encode(["mean_us" => $total / $this->count / 1000, "p50_us" => $samples[(int) (count($samples) * 0.5)] / 1000, "p95_us" => $samples[(int) (count($samples) * 0.95)] / 1000, "p99_us" => $samples[min(count($samples) - 1, (int) (count($samples) * 0.99))] / 1000, "elapsed_ms" => (hrtime(true) - $start) / 1e6, "heap_peak" => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
	}
}

$count = (int) ($options["count"] ?? 10000);
$bytes = (int) ($options["bytes"] ?? 256);
$producers = (int) ($options["producers"] ?? 1);
$batch = (int) ($options["batch"] ?? 500);
if($count < 100 || $count > 1000000 || $bytes < 32 || $bytes > 8192 || $producers < 1 || $producers > 16){ throw new InvalidArgumentException("Invalid benchmark bounds"); }
$file = tempnam(sys_get_temp_dir(), "quark-log-bench-");
if($file === false){ throw new RuntimeException("Cannot create benchmark log file"); }
$start = hrtime(true);
$logger = isset($options["json"])
 ? new quark\utils\MainLogger($file, false, "Server", new DateTimeZone("UTC"), configuration: ["json" => true])
 : new quark\utils\MainLogger($file, false, "Server", new DateTimeZone("UTC"));
$startupMs = (hrtime(true) - $start) / 1e6;
$logger->info("Benchmark sink warm-up");
$logger->syncFlushBuffer();
usleep(100000);
$workers = [];
$results = [];
$start = hrtime(true);
try{
	for($i = 0; $i < $producers; ++$i){
		$worker = new LoggingBenchmarkProducer($logger, $root, $count, $bytes, $i, $batch);
		$workers[] = $worker;
		if($producers === 1){ $worker->run(); }else{ $worker->start(pmmp\thread\Thread::INHERIT_NONE); }
	}
	foreach($workers as $worker){
		if($producers > 1){ $worker->join(); }
		$results[] = json_decode($worker->result, true, 16, JSON_THROW_ON_ERROR);
	}
	$enqueueMs = (hrtime(true) - $start) / 1e6;
	$flushStart = hrtime(true);
	$logger->syncFlushBuffer();
	$flushMs = (hrtime(true) - $flushStart) / 1e6;
	$elapsed = (hrtime(true) - $start) / 1e9;
	$writer = $logger->getLogWriterThread();
	$stats = $writer instanceof quark\log\LogDispatcher ? $writer->getQueue()->stats() : null;
}finally{ $logger->shutdownLogWriterThread(); }
$content = file_get_contents($file);
if($content === false){ throw new RuntimeException("Cannot read benchmark log"); }
$lines = preg_match_all("/producer=[0-9]+ sequence=[0-9]+/", $content);
if($lines === false){ throw new RuntimeException("Cannot count persisted benchmark events"); }
$expected = ($count - intdiv($count, 100) * 5 - min($count % 100, 5)) * $producers;
$report = ["root" => $root, "php" => PHP_VERSION, "count_per_producer" => $count, "message_bytes" => $bytes, "producers" => $producers, "flush_every" => $batch, "startup_ms" => $startupMs, "producer_phase_ms" => $enqueueMs, "flush_ms" => $flushMs, "persisted_per_s" => $lines / $elapsed, "persisted_events" => $lines, "expected_events" => $expected, "bytes_written" => strlen($content), "queue" => $stats, "workers" => $results];
fwrite(STDERR, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
foreach([$file, $file . ".jsonl", $file . ".emergency"] as $path){ if(is_file($path)){ unlink($path); } }
if($lines !== $expected || $stats !== null && ($stats["dropped"] !== 0 || $stats["fallback"] !== 0 || $stats["console_dropped"] !== 0)){ exit(1); }
