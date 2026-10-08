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

use quark\pulse\Pulse;
use quark\pulse\PulseReport;
use quark\timings\TimingsHandler;
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

$legacy = new TimingsHandler("benchmark.legacy");
$legacyChild = new TimingsHandler("benchmark.legacy.child");
$legacySingle = static function(int $n) use ($legacy) : void{
	for($i = 0; $i < $n; ++$i){
		$legacy->startTiming();
		$legacy->stopTiming();
	}
};
$legacyNested = static function(int $n) use ($legacy, $legacyChild) : void{
	for($i = 0; $i < $n; ++$i){
		$legacy->startTiming();
		$legacyChild->startTiming();
		$legacyChild->stopTiming();
		$legacy->stopTiming();
	}
};

printf("PHP %s; %s; OPcache CLI=%s; JIT=%s; median (min-max), 9 alternating rounds\n", PHP_VERSION, PHP_OS_FAMILY, ini_get("opcache.enable_cli"), ini_get("opcache.jit"));
benchmarkPulse([
	"empty loop" => static function(int $n) : void{
		for($i = 0; $i < $n; ++$i){}
	},
	"Pulse disabled pair" => $single,
	"Timings disabled pair" => $legacySingle
], 200000);
$session = Pulse::start();
TimingsHandler::setEnabled(true);
benchmarkPulse([
	"Pulse repeated pair" => $single,
	"Timings repeated pair" => $legacySingle,
	"Pulse nested (2 pairs)" => $nested,
	"Timings nested (2 pairs)" => $legacyNested
], 200000);
TimingsHandler::setEnabled(false);

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
echo "Worker benchmarks require the integration batch and pmmpthread.\n";
