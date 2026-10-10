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
use function array_fill;
use function hrtime;
use function memory_get_usage;
use function str_repeat;

final class PulseTest extends TestCase{
	protected function tearDown() : void{
		Pulse::stop();
	}

	public function testRegistrationReusesNumericIds() : void{
		$context = new PulseContext();
		$first = $context->zone("world.tick");
		self::assertSame($first, $context->zone("world.tick"));
		self::assertSame(0, $first->getId());
		self::assertSame(1, $context->zone("world.entities")->getId());
		self::assertSame("world.tick", $first->getName());
	}

	public function testNestedTotalSelfAndRepeatedCalls() : void{
		$context = new PulseContext();
		$parent = $context->zone("parent")->getId();
		$child = $context->zone("child")->getId();
		$context->start("main", 100);
		$p = $context->begin($parent, 100);
		$c = $context->begin($child, 110);
		$context->end($child, $c, 130);
		$c = $context->begin($child, 140);
		$context->end($child, $c, 170);
		$context->end($parent, $p, 200);
		$context->stop(200);
		self::assertSame([
			[1, $parent, 0, 1, 100, 50, 100],
			[2, $child, 1, 2, 50, 50, 30]
		], $context->capture()["nodes"]);
	}

	public function testSameZoneUnderDifferentParentsAndRecursion() : void{
		$context = new PulseContext();
		$a = $context->zone("a")->getId();
		$b = $context->zone("b")->getId();
		$context->start("main", 0);
		$root = $context->begin($a, 0);
		$nested = $context->begin($a, 10);
		$context->end($a, $nested, 20);
		$context->end($a, $root, 30);
		$root = $context->begin($b, 40);
		$nested = $context->begin($a, 50);
		$context->end($a, $nested, 60);
		$context->end($b, $root, 70);
		self::assertSame([
			[1, $a, 0, 1, 30, 20, 30],
			[2, $a, 1, 1, 10, 10, 10],
			[3, $b, 0, 1, 30, 20, 30],
			[4, $a, 3, 1, 10, 10, 10]
		], $context->capture()["nodes"]);
	}

	public function testOutOfOrderStopsRecoverWithoutDoubleCounting() : void{
		$context = new PulseContext();
		$a = $context->zone("a")->getId();
		$b = $context->zone("b")->getId();
		$context->start("main", 0);
		$outer = $context->begin($a, 0);
		$inner = $context->begin($b, 10);
		$context->end($a, $outer, 30);
		$context->end($b, $inner, 40);
		self::assertSame(2, $context->capture()["unbalanced_scopes"]);
		self::assertSame([[1, $a, 0, 1, 30, 10, 30], [2, $b, 1, 1, 20, 20, 20]], $context->capture()["nodes"]);
	}

	public function testWrongZoneTokenDoesNotCloseAValidScope() : void{
		$context = new PulseContext();
		$a = $context->zone("a")->getId();
		$b = $context->zone("b")->getId();
		$context->start("main", 0);
		$scope = $context->begin($a, 0);
		$context->end($b, $scope, 10);
		$context->end($a, $scope, 30);
		self::assertSame([[1, $a, 0, 1, 30, 30, 30]], $context->capture()["nodes"]);
		self::assertSame(1, $context->capture()["unbalanced_scopes"]);
	}

	public function testStopClosesUnbalancedScopesAndOldTokensAreIgnored() : void{
		$context = new PulseContext();
		$zone = $context->zone("zone")->getId();
		$context->start("main", 100);
		$old = $context->begin($zone, 100);
		$context->stop(120);
		self::assertSame(1, $context->capture()["unbalanced_scopes"]);
		self::assertSame(120, $context->capture()["ended_ns"]);
		$context->start("main", 200);
		$current = $context->begin($zone, 200);
		$context->end($zone, $old, 210);
		$context->end($zone, $current, 230);
		self::assertSame([[1, $zone, 0, 1, 30, 30, 30]], $context->capture()["nodes"]);
		self::assertSame(0, $context->capture()["unbalanced_scopes"]);
	}

	public function testDisabledAndFinallyPaths() : void{
		$zone = Pulse::zone("test.exception");
		$disabled = $zone->start();
		self::assertSame(0, $disabled);
		$session = Pulse::start();
		$zone->stop($disabled);
		$scope = $zone->start();
		try{
			try{
				throw new \RuntimeException("expected");
			}finally{
				$zone->stop($scope);
			}
		}catch(\RuntimeException $e){
			self::assertSame("expected", $e->getMessage());
		}
		$session->stop();
		self::assertFalse(Pulse::isRecording());
		self::assertFalse($session->isRecording());
		self::assertSame(0, $session->getCapture()["unbalanced_scopes"]);
		$row = array_column($session->getCapture()["nodes"], null, 1)[$zone->getId()];
		self::assertSame(1, $row[3]);
		self::assertGreaterThanOrEqual(0, $row[4]);
	}

	public function testDisabledClosurePreservesResultsExceptionsAndSessionChanges() : void{
		$context = new PulseContext();
		$zone = $context->zone("closure");
		$result = new \stdClass();
		$calls = 0;
		self::assertSame($result, $zone->time(static function() use ($result, &$calls) : object{
			++$calls;
			return $result;
		}));
		self::assertSame(1, $calls);
		$error = new \RuntimeException("expected");
		$caught = null;
		try{
			$zone->time(static function() use ($error) : void{ throw $error; });
		}catch(\RuntimeException $value){
			$caught = $value;
		}
		self::assertSame($error, $caught);
		$zone->time(static function() use ($context) : void{ $context->start("main", 0); });
		self::assertTrue($context->recording);
		self::assertSame([], $context->capture()["nodes"]);
		$context->stop(1);
	}

	public function testClosureStillRunsWhenCaptureLimitsAreReached() : void{
		$context = new PulseContext(maxNodes: 1, maxDepth: 1);
		$outer = $context->zone("outer");
		$limited = $context->zone("limited");
		$context->start("main", 0);
		$scope = $outer->start();
		self::assertSame(42, $limited->time(static fn() => 42));
		$error = new \RuntimeException("expected");
		$caught = null;
		try{
			$limited->time(static function() use ($error) : void{ throw $error; });
		}catch(\RuntimeException $value){
			$caught = $value;
		}
		self::assertSame($error, $caught);
		$outer->stop($scope);
		$ran = false;
		$limited->time(static function() use (&$ran) : void{ $ran = true; });
		self::assertTrue($ran);
		$context->stop((int) hrtime(true));
		$capture = $context->capture();
		self::assertCount(1, $capture["nodes"]);
		self::assertSame(1, $capture["nodes"][0][3]);
		self::assertSame(3, $capture["dropped_scopes"]);
		self::assertSame(0, $capture["unbalanced_scopes"]);
	}

	public function testStoppedSessionCaptureRemainsStable() : void{
		$zone = Pulse::zone("test.session");
		$session = Pulse::start();
		$scope = $zone->start();
		$zone->stop($scope);
		Pulse::stop();
		$capture = $session->getCapture();
		$stats = $session->getStats();
		$top = $session->getTopZones();
		$next = Pulse::start("worker-test");
		$scope = $zone->start();
		$zone->stop($scope);
		$session->stop();
		self::assertTrue($next->isRecording());
		self::assertSame($capture, $session->getCapture());
		self::assertSame($stats, $session->getStats());
		self::assertSame($top, $session->getTopZones());
		self::assertSame("worker-test", $next->getCapture()["thread"]);
	}

	public function testResetPreservesZoneHandlesAndFrozenSessions() : void{
		$zone = Pulse::zone("reset.cached");
		$session = Pulse::start();
		$old = $zone->start();
		Pulse::reset();
		$capture = $session->getCapture();
		$stats = $session->getStats();
		$top = $session->getTopZones();
		self::assertNull(Pulse::getSession());
		self::assertSame($zone, Pulse::zone("reset.cached"));
		$next = Pulse::start();
		$scope = $zone->start();
		$zone->stop($old);
		$zone->stop($scope);
		$next->stop();
		self::assertCount(1, $next->getCapture()["nodes"]);
		self::assertSame(1, $next->getCapture()["nodes"][0][3]);
		self::assertSame(0, $next->getCapture()["unbalanced_scopes"]);
		self::assertSame($capture, $session->getCapture());
		self::assertSame($stats, $session->getStats());
		self::assertSame($top, $session->getTopZones());
	}

	public function testStartWhileRecordingDoesNotLoseData() : void{
		$session = Pulse::start();
		try{
			Pulse::start();
			self::fail("An active session must not be overwritten");
		}catch(\LogicException){
			self::assertSame($session, Pulse::getSession());
			self::assertTrue($session->isRecording());
		}
	}

	public function testDepthAndNodeLimitsAreBounded() : void{
		$context = new PulseContext(maxNodes: 1, maxDepth: 1);
		$a = $context->zone("a")->getId();
		$b = $context->zone("b")->getId();
		$context->start("main", 0);
		$scope = $context->begin($a, 0);
		self::assertSame(0, $context->begin($b, 10));
		$context->end($b, 0, 20);
		$context->end($a, $scope, 30);
		self::assertSame(0, $context->begin($b, 40));
		self::assertSame(2, $context->capture()["dropped_scopes"]);
		self::assertSame([[1, $a, 0, 1, 30, 30, 30]], $context->capture()["nodes"]);
	}

	public function testRegistrationLimitAndNameValidation() : void{
		$context = new PulseContext(maxZones: 1);
		$zone = $context->zone("a");
		self::assertSame($zone, $context->zone("a"));
		foreach(["", str_repeat("a", 257), "\xff"] as $invalid){
			try{
				$context->zone($invalid);
				self::fail("Invalid zone name accepted");
			}catch(\InvalidArgumentException){
				self::assertCount(1, $context->capture()["zones"]);
			}
		}
		$this->expectException(\LengthException::class);
		$context->zone("b");
	}

	public function testContextsAreIndependent() : void{
		$main = new PulseContext();
		$worker = new PulseContext();
		$main->zone("main.zone");
		$id = $worker->zone("worker.zone")->getId();
		$worker->start("worker", 100);
		$scope = $worker->begin($id, 100);
		$worker->end($id, $scope, 120);
		self::assertFalse($main->recording);
		self::assertSame([], $main->capture()["nodes"]);
		self::assertSame(["worker.zone"], $worker->capture()["zones"]);
	}

	public function testInvalidLimitsAndThreadNamesDoNotStartRecording() : void{
		foreach([[0, 1, 1], [4097, 1, 1], [1, 0, 1], [1, 16385, 1], [1, 1, 0], [1, 1, 257]] as [$zones, $nodes, $depth]){
			try{
				new PulseContext($zones, $nodes, $depth);
				self::fail("Invalid capture limits accepted");
			}catch(\InvalidArgumentException){
				self::assertFalse(Pulse::isRecording());
			}
		}
		$context = new PulseContext();
		foreach(["", str_repeat("a", 257), "\xff"] as $invalid){
			try{
				$context->start($invalid, 0);
				self::fail("Invalid thread name accepted");
			}catch(\InvalidArgumentException){
				self::assertFalse($context->recording);
			}
		}
		self::assertSame(str_repeat("a", 256), $context->zone(str_repeat("a", 256))->getName());
	}

	public function testCaptureMutationDoesNotChangeRecordedData() : void{
		$zone = Pulse::zone("test.capture");
		$session = Pulse::start();
		$scope = $zone->start();
		$zone->stop($scope);
		$session->stop();
		$expected = $session->getCapture();
		$capture = $session->getCapture();
		$capture["zones"][$zone->getId()] = "changed";
		$capture["nodes"][0][3] = -1;
		self::assertNotSame($capture, $session->getCapture());
		self::assertSame($expected, $session->getCapture());
	}

	public function testWarmPathAndRepeatedSessionsDoNotGrowStorage() : void{
		$context = new PulseContext();
		$zone = $context->zone("repeated")->getId();
		$context->start("main", 0);
		$scope = $context->begin($zone, 0);
		$context->end($zone, $scope, 1);
		$before = memory_get_usage();
		for($i = 0; $i < 10000; ++$i){
			$scope = $context->begin($zone, $i);
			$context->end($zone, $scope, $i + 1);
		}
		self::assertSame($before, memory_get_usage());
		self::assertSame(10001, $context->capture()["nodes"][0][3]);
		$context->stop(10001);
		$before = memory_get_usage();
		for($i = 0; $i < 100; ++$i){
			$context->start("main", $i);
			$scope = $context->begin($zone, $i);
			$context->end($zone, $scope, $i + 1);
			$context->stop($i + 1);
		}
		self::assertSame($before, memory_get_usage());
		self::assertCount(1, $context->capture()["nodes"]);
	}

	public function testFullDepthRecursiveFramesCanBeReused() : void{
		$context = new PulseContext();
		$zone = $context->zone("recursive")->getId();
		$scopes = array_fill(0, 256, 0);
		$context->start("main", 0);
		for($round = 0; $round < 2; ++$round){
			for($depth = 0; $depth < 256; ++$depth){
				$scopes[$depth] = $context->begin($zone, $depth);
			}
			self::assertSame(0, $context->begin($zone, 256));
			for($depth = 255; $depth >= 0; --$depth){
				$context->end($zone, $scopes[$depth], 512 - $depth);
			}
		}
		$rows = $context->capture()["nodes"];
		self::assertCount(256, $rows);
		self::assertSame([1, $zone, 0, 2, 1024, 4, 512], $rows[0]);
		self::assertSame([256, $zone, 255, 2, 4, 4, 2], $rows[255]);
		self::assertSame(2, $context->capture()["dropped_scopes"]);
		self::assertSame(0, $context->capture()["unbalanced_scopes"]);
	}

	public function testAccountingClampsNegativeElapsedAndSelfTime() : void{
		$context = new PulseContext();
		$zone = $context->zone("clamped")->getId();
		$context->start("main", 0);
		$scope = $context->begin($zone, 10);
		$context->end($zone, $scope, 5);
		$outer = $context->begin($zone, 100);
		$inner = $context->begin($zone, 110);
		$context->end($zone, $inner, 150);
		$context->end($zone, $outer, 120);
		self::assertSame([[1, $zone, 0, 2, 20, 0, 20], [2, $zone, 1, 1, 40, 40, 40]], $context->capture()["nodes"]);
	}

	public function testCompletedTokenCannotCloseAReusedNode() : void{
		$context = new PulseContext();
		$zone = $context->zone("reused")->getId();
		$context->start("main", 0);
		$old = $context->begin($zone, 0);
		$context->end($zone, $old, 20);
		$current = $context->begin($zone, 30);
		$context->end($zone, $old, 40);
		$context->end($zone, $current, 60);
		self::assertSame([[1, $zone, 0, 2, 50, 50, 30]], $context->capture()["nodes"]);
		self::assertSame(1, $context->capture()["unbalanced_scopes"]);
	}

	public function testStatsAndTopCombineParentPathsWithoutCountingChildrenTwice() : void{
		$context = new PulseContext();
		$a = $context->zone("a")->getId();
		$b = $context->zone("b")->getId();
		$context->zone("unused");
		$session = new PulseSession($context, "main", spikeThresholdNs: 1, maxSpikes: 1);
		$now = (int) hrtime(true);
		for($tick = 0; $tick < 2; ++$tick){
			$start = $now + $tick * 100;
			$context->beginTick($start);
			$root = $context->begin($a, $start);
			$inner = $context->begin($a, $start + 10);
			$context->end($a, $inner, $start + 30);
			$context->end($a, $root, $start + 40);
			$root = $context->begin($b, $start + 40);
			$inner = $context->begin($a, $start + 50);
			$context->end($a, $inner, $start + 60);
			$context->end($b, $root, $start + 90);
			$context->endTick($start + 90);
		}
		self::assertSame([
			["name" => "a", "calls" => 6, "self_ns" => 100, "max_ns" => 40],
			["name" => "b", "calls" => 2, "self_ns" => 80, "max_ns" => 50]
		], $session->getTopZones());
		self::assertCount(1, $session->getTopZones(1));
		$stats = $session->getStats();
		self::assertTrue($stats["recording"]);
		self::assertSame(2, $stats["tick_count"]);
		self::assertSame(180, $stats["tick_total_ns"]);
		self::assertSame(90, $stats["tick_max_ns"]);
		self::assertSame(1, $stats["retained_spikes"]);
		self::assertSame(1, $stats["spikes_dropped"]);
		for($i = 0; $i < 10; ++$i){ $session->getStats(); $session->getTopZones(); }
		$before = memory_get_usage();
		for($i = 0; $i < 1000; ++$i){ $session->getStats(); $session->getTopZones(); }
		self::assertSame($before, memory_get_usage());
		$session->stop();
		$stats = $session->getStats();
		self::assertFalse($stats["recording"]);
		self::assertSame($stats, $session->getStats());
		foreach([0, 51] as $limit){
			try{ $session->getTopZones($limit); self::fail("Invalid top limit accepted"); }catch(\InvalidArgumentException){}
		}
	}
}
