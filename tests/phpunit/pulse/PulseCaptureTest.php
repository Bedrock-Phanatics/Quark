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

namespace quark\pulse;

use PHPUnit\Framework\TestCase;
use quark\pulse\internal\PulseContext;
use function array_column;
use function count;
use function memory_get_usage;

final class PulseCaptureTest extends TestCase{
	protected function tearDown() : void{ Pulse::stop(); }

	public function testTickHistoryWrapsWithoutLosingSessionTotals() : void{
		$context = new PulseContext(maxTicks: 2);
		$zone = $context->zone("tick")->getId();
		$context->start("main", 100);
		foreach([[100, 120], [130, 140], [200, 230]] as [$begin, $end]){
			$context->beginTick($begin);
			$scope = $context->begin($zone, $begin);
			$context->end($zone, $scope, $end);
			$context->endTick($end);
		}
		$context->stop(230);
		$capture = $context->capture();
		self::assertSame([[2, 30, 10], [3, 100, 30]], $capture["ticks"]);
		self::assertSame(3, $capture["tick_count"]);
		self::assertSame(60, $capture["tick_total_ns"]);
		self::assertSame(30, $capture["tick_max_ns"]);
		self::assertSame([3], $capture["active_ticks"]);
		self::assertSame([], $capture["spikes"]);
	}

	public function testSpikeUsesTickDeltasAndCountsActiveTicksOnce() : void{
		$context = new PulseContext(maxTicks: 2);
		$parent = $context->zone("parent")->getId();
		$child = $context->zone("child")->getId();
		$context->start("main", 100, spikeThresholdNs: 50);
		$context->beginTick(100);
		$p = $context->begin($parent, 100);
		$c = $context->begin($child, 110);
		$context->end($child, $c, 120);
		$c = $context->begin($child, 130);
		$context->end($child, $c, 150);
		$context->end($parent, $p, 180);
		$context->endTick(180);
		$context->beginTick(200);
		$p = $context->begin($parent, 200);
		$c = $context->begin($child, 210);
		$context->end($child, $c, 230);
		$context->end($parent, $p, 240);
		$context->endTick(240);
		$context->beginTick(300);
		$context->endTick(350);
		$context->stop(350);
		$capture = $context->capture();
		self::assertSame([2, 2], $capture["active_ticks"]);
		self::assertSame([[
			"tick" => [1, 0, 80],
			"nodes" => [[2, 2, 30, 30], [1, 1, 80, 50]]
		]], $capture["spikes"]);
		self::assertSame($capture, PulseReport::decode(PulseReport::create([$capture])->encode())->getData()["threads"][0]);
	}

	public function testSpikeCountLimitRetainsNewestTicks() : void{
		$context = new PulseContext();
		$context->start("main", 0, spikeThresholdNs: 1, maxSpikes: 2);
		for($i = 0; $i < 5; ++$i){
			$context->beginTick($i * 10);
			$context->endTick($i * 10 + 5);
		}
		self::assertSame([[4, 30, 5], [5, 40, 5]], array_column($context->capture()["spikes"], "tick"));
		self::assertSame(3, $context->capture()["spikes_dropped"]);
	}

	public function testRestartReusesStorageWithoutLeakingPreviousCapture() : void{
		$context = new PulseContext(maxTicks: 2);
		$id = $context->zone("reused")->getId();
		$context->start("main", 0, spikeThresholdNs: 1);
		for($i = 0; $i < 3; ++$i){
			$context->beginTick($i * 10);
			$scope = $context->begin($id, $i * 10);
			$context->end($id, $scope, $i * 10 + 5);
			$context->endTick($i * 10 + 5);
		}
		$context->stop(30);
		$previous = $context->capture();
		$context->start("main", 100, spikeThresholdNs: 1);
		self::assertSame([], $context->capture()["ticks"]);
		self::assertSame([], $context->capture()["spikes"]);
		$context->beginTick(100);
		$scope = $context->begin($id, 100);
		$context->end($id, $scope, 110);
		$context->endTick(110);
		$context->stop(110);
		$capture = $context->capture();
		self::assertSame([[1, 0, 10]], $capture["ticks"]);
		self::assertSame([1], $capture["active_ticks"]);
		self::assertSame([[1, 1, 10, 10]], $capture["spikes"][0]["nodes"]);
		self::assertSame(3, $previous["tick_count"]);
		self::assertSame($capture, PulseReport::decode(PulseReport::create([$capture])->encode())->getData()["threads"][0]);
	}

	public function testSpikeRowBudgetAlsoBoundsDenseCaptures() : void{
		$context = new PulseContext();
		$parents = [];
		$children = [];
		for($i = 0; $i < 3; ++$i){
			$parents[] = $context->zone("parent.$i")->getId();
		}
		for($i = 0; $i < 4093; ++$i){
			$children[] = $context->zone("child.$i")->getId();
		}
		$context->start("main", 0, spikeThresholdNs: 1, maxSpikes: 128);
		$now = 0;
		for($tick = 0; $tick < 6; ++$tick){
			$context->beginTick($now++);
			foreach($parents as $parent){
				$p = $context->begin($parent, $now++);
				foreach($children as $child){
					$c = $context->begin($child, $now++);
					$context->end($child, $c, $now++);
				}
				$context->end($parent, $p, $now++);
			}
			$context->endTick($now++);
		}
		$capture = $context->capture();
		$total = 0;
		foreach($capture["spikes"] as $spike){
			$total += count($spike["nodes"]);
		}
		self::assertSame(61410, $total);
		self::assertLessThanOrEqual(PulseContext::MAX_SPIKE_ROWS, $total);
		self::assertCount(5, $capture["spikes"]);
		self::assertSame(1, $capture["spikes_dropped"]);
	}

	public function testTickBoundariesRecoverUnfinishedScopes() : void{
		$context = new PulseContext();
		$zone = $context->zone("unfinished")->getId();
		$context->start("main", 100, spikeThresholdNs: 1);
		$context->beginTick(100);
		$context->begin($zone, 110);
		$context->beginTick(140);
		$scope = $context->begin($zone, 150);
		$context->endTick(200);
		$context->endTick(220);
		$context->end($zone, $scope, 220);
		$context->stop(220);
		$capture = $context->capture();
		self::assertSame([[1, 0, 40], [2, 40, 60]], $capture["ticks"]);
		self::assertSame(3, $capture["unbalanced_scopes"]);
		self::assertSame(2, $capture["unbalanced_ticks"]);
		self::assertSame([[1, 1, 30, 30]], $capture["spikes"][0]["nodes"]);
		self::assertSame([[1, 1, 50, 50]], $capture["spikes"][1]["nodes"]);
	}

	public function testStoppingDuringATickCapturesIt() : void{
		$context = new PulseContext();
		$id = $context->zone("partial")->getId();
		$context->start("main", 100, spikeThresholdNs: 1);
		$context->beginTick(100);
		$context->begin($id, 110);
		$context->stop(150);
		$context->stop(200);
		self::assertSame([[1, 0, 50]], $context->capture()["ticks"]);
		self::assertSame(150, $context->capture()["ended_ns"]);
		self::assertSame([[1, 1, 40, 40]], $context->capture()["spikes"][0]["nodes"]);
	}

	public function testDurationExpiresAtBoundariesAndWorkerCheck() : void{
		$context = new PulseContext();
		$context->start("main", 100, durationNs: 50);
		$context->beginTick(100);
		$context->endTick(140);
		self::assertTrue($context->recording);
		$context->beginTick(150);
		self::assertFalse($context->recording);
		self::assertSame(1, $context->capture()["tick_count"]);
		$context->start("worker", 200, durationNs: 50);
		$context->checkDuration(249);
		self::assertTrue($context->recording);
		$context->checkDuration(250);
		self::assertFalse($context->recording);
	}

	public function testExpiredPublicSessionRemainsFrozenAcrossRestart() : void{
		$session = Pulse::start(durationNs: 1);
		Pulse::checkDuration();
		self::assertFalse($session->isRecording());
		self::assertFalse(Pulse::isRecording());
		$capture = $session->getCapture();
		Pulse::start();
		Pulse::beginTick();
		Pulse::endTick();
		self::assertSame($capture, $session->getCapture());
		self::assertSame($capture, $session->getReport()->getData()["threads"][0]);
	}

	public function testResetReleasesCaptureBuffersAndRestoresTheNodeBudget() : void{
		$context = new PulseContext(maxNodes: 1, maxTicks: 2);
		$a = $context->zone("a")->getId();
		$b = $context->zone("b")->getId();
		$context->start("main", 0, spikeThresholdNs: 1);
		$context->beginTick(0);
		$old = $context->begin($a, 0);
		try{
			$context->reset();
			self::fail("Reset must not discard an active capture");
		}catch(\LogicException){ self::assertTrue($context->recording); }
		$context->end($a, $old, 10);
		$context->endTick(10);
		$context->stop(10);
		$previous = $context->capture();
		$context->reset();
		$cleared = $context->capture();
		foreach(["nodes", "active_ticks", "ticks", "spikes"] as $key){ self::assertSame([], $cleared[$key]); }
		self::assertSame(0, $cleared["tick_count"]);
		self::assertFalse($context->recording);
		self::assertSame($cleared, PulseReport::decode(PulseReport::create([$cleared])->encode())->getData()["threads"][0]);
		$context->start("main", 20);
		$current = $context->begin($b, 20);
		self::assertGreaterThan($old, $current);
		$context->end($a, $old, 25);
		$context->end($b, $current, 30);
		$context->stop(30);
		self::assertSame([[1, $b, 0, 1, 10, 10, 10]], $context->capture()["nodes"]);
		self::assertSame(0, $context->capture()["dropped_scopes"]);
		self::assertSame(0, $context->capture()["unbalanced_scopes"]);
		self::assertSame([[1, $a, 0, 1, 10, 10, 10]], $previous["nodes"]);
		self::assertCount(1, $previous["spikes"]);
	}

	public function testTickAndTouchedStorageStopsGrowingAfterWarmup() : void{
		$context = new PulseContext(maxTicks: 4);
		$zone = $context->zone("warm")->getId();
		$context->start("main", 0, spikeThresholdNs: 1000);
		for($i = 0; $i < 4; ++$i){
			$context->beginTick($i * 10);
			$scope = $context->begin($zone, $i * 10);
			$context->end($zone, $scope, $i * 10 + 5);
			$context->endTick($i * 10 + 5);
		}
		$before = memory_get_usage();
		for($i = 4; $i < 1004; ++$i){
			$context->beginTick($i * 10);
			$scope = $context->begin($zone, $i * 10);
			$context->end($zone, $scope, $i * 10 + 5);
			$context->endTick($i * 10 + 5);
		}
		self::assertSame($before, memory_get_usage());
		self::assertCount(4, $context->capture()["ticks"]);
		self::assertSame([1004], $context->capture()["active_ticks"]);
	}

	public function testSessionOptionsAndTickLimitsAreValidatedBeforeStarting() : void{
		foreach([0, 4097] as $capacity){
			try{
				new PulseContext(maxTicks: $capacity);
				self::fail("Invalid tick capacity accepted");
			}catch(\InvalidArgumentException){
				self::assertFalse(Pulse::isRecording());
			}
		}
		foreach([[-1, 0, 1], [86400000000001, 0, 1], [0, -1, 1], [0, 60000000001, 1], [0, 0, 0], [0, 0, 129]] as [$duration, $threshold, $spikes]){
			$context = new PulseContext();
			try{
				$context->start("main", 0, $duration, $threshold, $spikes);
				self::fail("Invalid session options accepted");
			}catch(\InvalidArgumentException){
				self::assertFalse($context->recording);
			}
		}
	}
}
