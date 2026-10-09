<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

use pocketmine\snooze\SleeperHandler;
use quark\pulse\internal\PulseRecorder;
use quark\pulse\Pulse;
use quark\pulse\PulseReport;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\thread\ThreadSafeClassLoader;
use quark\utils\MainLogger;
use quark\utils\Utils;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @param array<string, Closure(int) : void> $workloads
 */
function benchmarkPulse(array $workloads, int $iterations) : void{
	$samples = [];
	$growth = [];
	foreach(Utils::stringifyKeys($workloads) as $name => $work){
		$work(min(10000, $iterations));
		$samples[$name] = array_fill(0, 9, 0.0);
	}
	for($round = 0; $round < 9; ++$round){
		foreach(Utils::stringifyKeys($round % 2 === 0 ? $workloads : array_reverse($workloads, true)) as $name => $work){
			$before = memory_get_usage();
			$started = hrtime(true);
			$work($iterations);
			$elapsed = (hrtime(true) - $started) / $iterations;
			$growth[$name] = memory_get_usage() - $before;
			$samples[$name][$round] = $elapsed;
		}
	}
	foreach(Utils::stringifyKeys($samples) as $name => $values){
		sort($values, SORT_NUMERIC);
		printf("%-26s %8.1f ns/op (%8.1f-%8.1f) retained: %d B; %d iterations\n", $name, $values[4], $values[0], $values[8], $growth[$name], $iterations);
	}
}

$zone = Pulse::zone("benchmark.zone");
$child = Pulse::zone("benchmark.child");
$single = static function(int $n) use ($zone) : void{
	for($i = 0; $i < $n; ++$i){
		$scope = $zone->start();
		$zone->stop($scope);
	}
};
$nested = static function(int $n) use ($zone, $child) : void{
	for($i = 0; $i < $n; ++$i){
		$outer = $zone->start();
		$inner = $child->start();
		$child->stop($inner);
		$zone->stop($outer);
	}
};

printf("PHP %s; %s; OPcache CLI=%s; JIT=%s; median (min-max), 9 alternating rounds\n", PHP_VERSION, PHP_OS_FAMILY, ini_get("opcache.enable_cli"), ini_get("opcache.jit"));
benchmarkPulse([
	"empty loop" => static function(int $n) : void{
		for($i = 0; $i < $n; ++$i){}
	},
	"Pulse disabled pair" => $single
], 200000);
$session = Pulse::start();
benchmarkPulse([
	"Pulse repeated pair" => $single,
	"Pulse nested (2 pairs)" => $nested
], 200000);

$zones = [];
for($i = 0; $i < 256; ++$i){
	$zones[] = Pulse::zone("benchmark.zone.$i");
}
benchmarkPulse(["Pulse rotating 256 zones" => static function(int $n) use ($zones) : void{
	for($i = 0; $i < $n; ++$i){
		$zone = $zones[$i & 255];
		$scope = $zone->start();
		$zone->stop($scope);
	}
}], 200000);
benchmarkPulse(["Pulse capture (258 nodes)" => static function(int $n) use ($session) : void{
	for($i = 0; $i < $n; ++$i){
		$session->getCapture();
	}
}], 1000);
Pulse::stop();

$tick = static function(int $n) use ($single) : void{
	for($i = 0; $i < $n; ++$i){
		Pulse::beginTick();
		$single(8);
		Pulse::endTick();
	}
};
Pulse::start();
benchmarkPulse(["Pulse tick (8 pairs)" => $tick], 20000);
Pulse::stop();
Pulse::start(spikeThresholdNs: 60000000000);
benchmarkPulse(["Spike mode below threshold" => $tick], 20000);
Pulse::stop();
$session = Pulse::start(spikeThresholdNs: 1);
benchmarkPulse(["Retained spike (8 pairs)" => $tick], 20000);
Pulse::stop();

$capture = $session->getCapture();
$report = $session->getReport();
$json = $report->encode();
printf("Report: %d bytes, %d nodes, %d ticks, %d spikes\n", strlen($json), count($capture["nodes"]), count($capture["ticks"]), count($capture["spikes"]));
benchmarkPulse([
	"Pulse report creation" => static function(int $n) use ($capture) : void{
		for($i = 0; $i < $n; ++$i){
			PulseReport::create([$capture]);
		}
	},
	"Pulse report encoding" => static function(int $n) use ($report) : void{
		for($i = 0; $i < $n; ++$i){
			$report->encode();
		}
	},
	"Pulse report decoding" => static function(int $n) use ($json) : void{
		for($i = 0; $i < $n; ++$i){
			PulseReport::decode($json);
		}
	}
], 100);
if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){
	echo "Two-worker benchmarks skipped: requires pmmpthread and igbinary.\n";
	return;
}

function waitForPulseWorkers(AsyncPool $pool) : void{
	$deadline = microtime(true) + 30;
	while($pool->collectTasks()){
		if(microtime(true) >= $deadline){ throw new RuntimeException("Worker benchmark timed out"); }
		usleep(1000);
	}
}

define('quark\\COMPOSER_AUTOLOADER_PATH', __DIR__ . '/../vendor/autoload.php');
$logger = new MainLogger(null, false, "Pulse benchmark", new DateTimeZone("UTC"));
$pool = new AsyncPool(2, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0);
$recorder = new PulseRecorder($pool);
Pulse::reset();
try{
	foreach([false, true] as $recording){
		if($recording){ $recorder->start(); }
		for($worker = 0; $worker < 2; ++$worker){
			$pool->submitTaskToWorker(new class($worker, $recording) extends AsyncTask{
				public function __construct(private int $worker, private bool $recording){}

				public function onRun() : void{
					$zone = Pulse::zone("benchmark.worker");
					$samples = [];
					$retained = 0;
					for($round = -1; $round < 9; ++$round){
						$before = memory_get_usage();
						$start = hrtime(true);
						for($i = 0; $i < 200000; ++$i){ $scope = $zone->start(); $zone->stop($scope); }
						$elapsed = (hrtime(true) - $start) / 200000;
						$retained = memory_get_usage() - $before;
						if($round >= 0){ $samples[] = $elapsed; }
					}
					sort($samples, SORT_NUMERIC);
					$this->setResult([$samples[4], $samples[0], $samples[8], $retained]);
				}

				public function onCompletion() : void{
					/** @var array{float, float, float, int} $result */
					$result = $this->getResult();
					printf("Worker #%d %s pair: %.1f ns/op (%.1f-%.1f), retained: %d B\n", $this->worker, $this->recording ? "active" : "disabled", ...$result);
				}
			}, $worker);
		}
		waitForPulseWorkers($pool);
	}
	$recorder->stop();
	$recorder->collect()->onCompletion(static function(PulseReport $report) : void{
		$threads = $report->getData()["threads"];
		benchmarkPulse(["Three-thread report" => static function(int $n) use ($threads) : void{
			for($i = 0; $i < $n; ++$i){ PulseReport::create($threads); }
		}], 100);
	}, static function() : void{ throw new RuntimeException("Worker report rejected"); });
	waitForPulseWorkers($pool);
}finally{
	$recorder->stop();
	$pool->shutdown();
	Pulse::reset();
	$logger->shutdownLogWriterThread();
}
