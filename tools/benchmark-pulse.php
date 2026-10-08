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
echo "Worker and .qpulse report benchmarks require the later integration/report batches.\n";
