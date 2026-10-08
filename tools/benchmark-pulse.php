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

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @param Closure(int) : void $work
 */
function benchmarkPulse(string $name, int $iterations, Closure $work) : void{
	$work(1000);
	$samples = array_fill(0, 7, 0.0);
	$before = memory_get_usage();
	for($round = 0; $round < 7; ++$round){
		$started = hrtime(true);
		$work($iterations);
		$samples[$round] = (hrtime(true) - $started) / $iterations;
	}
	$growth = memory_get_usage() - $before;
	sort($samples, SORT_NUMERIC);
	printf("%-24s %9.1f ns/op  retained delta: %d B\n", $name, $samples[3], $growth);
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

printf("PHP %s; %s; JIT=%s; median of 7 rounds, 200000 iterations\n", PHP_VERSION, PHP_OS_FAMILY, ini_get("opcache.jit"));
benchmarkPulse("empty loop", 200000, static function(int $n) : void{
	for($i = 0; $i < $n; ++$i){}
});
benchmarkPulse("Pulse disabled pair", 200000, $single);
$session = Pulse::start();
benchmarkPulse("Pulse repeated pair", 200000, $single);
benchmarkPulse("Pulse nested (2 pairs)", 200000, $nested);
benchmarkPulse("Pulse capture (2 nodes)", 10000, static function(int $n) use ($session) : void{
	for($i = 0; $i < $n; ++$i){
		$session->getCapture();
	}
});
Pulse::stop();

$legacy = new TimingsHandler("benchmark.legacy");
$legacyChild = new TimingsHandler("benchmark.legacy.child");
$legacySingle = static function(int $n) use ($legacy) : void{
	for($i = 0; $i < $n; ++$i){
		$legacy->startTiming();
		$legacy->stopTiming();
	}
};
benchmarkPulse("Timings disabled pair", 200000, $legacySingle);
TimingsHandler::setEnabled(true);
benchmarkPulse("Timings repeated pair", 200000, $legacySingle);
benchmarkPulse("Timings nested (2 pairs)", 200000, static function(int $n) use ($legacy, $legacyChild) : void{
	for($i = 0; $i < $n; ++$i){
		$legacy->startTiming();
		$legacyChild->startTiming();
		$legacyChild->stopTiming();
		$legacy->stopTiming();
	}
});
TimingsHandler::setEnabled(false);
echo "Worker and .qpulse report benchmarks require the later integration/report batches.\n";
