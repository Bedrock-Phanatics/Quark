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
use function str_repeat;
use function str_replace;
use function substr;

final class PulseReportTest extends TestCase{
	private function report() : PulseReport{
		$context = new PulseContext();
		$id = $context->zone("zone")->getId();
		$context->start("main", 100, spikeThresholdNs: 5);
		$context->beginTick(100);
		$scope = $context->begin($id, 100);
		$context->end($id, $scope, 120);
		$context->endTick(120);
		$context->stop(120);
		return PulseReport::create([$context->capture()], ["plugins" => [["name" => "SkyWars", "version" => "1.0"]], "worlds" => ["Lobby"]]);
	}

	public function testRoundTripPreservesVersionMetadataAndMeasurements() : void{
		$report = $this->report();
		$data = PulseReport::decode($report->encode())->getData();
		self::assertSame($report->getData(), $data);
		self::assertSame(1, $data["version"]);
		self::assertSame("ns", $data["time_unit"]);
		self::assertSame([["name" => "SkyWars", "version" => "1.0"]], $data["metadata"]["plugins"]);
		self::assertSame(["Lobby"], $data["metadata"]["worlds"]);
	}

	public function testEmptyAndIndependentThreadCapturesAreSupported() : void{
		$main = new PulseContext();
		$worker = new PulseContext();
		$main->start("main", 100);
		$main->beginTick(100);
		$main->endTick(100);
		$main->stop(100);
		$zone = $worker->zone("task")->getId();
		$worker->start("worker#1", 200);
		$scope = $worker->begin($zone, 200);
		$worker->end($zone, $scope, 210);
		$worker->stop(210);
		$threads = [$main->capture(), $worker->capture()];
		self::assertSame($threads, PulseReport::decode(PulseReport::create($threads)->encode())->getData()["threads"]);
		self::assertSame([], PulseReport::decode(PulseReport::create([])->encode())->getData()["threads"]);
	}

	public function testLiveCaptureAllowsAnUnfinishedParentAndTick() : void{
		$context = new PulseContext();
		$id = $context->zone("recursive")->getId();
		$context->start("main", 100);
		$context->beginTick(100);
		$context->begin($id, 100);
		$child = $context->begin($id, 110);
		$context->end($id, $child, 120);
		$capture = $context->capture();
		self::assertSame($capture, PulseReport::decode(PulseReport::create([$capture])->encode())->getData()["threads"][0]);
	}

	public function testMalformedRowsReferencesAndVersionsAreRejected() : void{
		$json = $this->report()->encode();
		foreach([
			['"version":1', '"version":2'],
			['"version":1', '"version":"1"'],
			['"time_unit":"ns"', '"time_unit":"ms"'],
			['"recording":false', '"recording":"false"'],
			['"ended_ns":120', '"ended_ns":99'],
			['"tick_count":1', '"tick_count":-1'],
			['"tick_capacity":1200', '"tick_capacity":0'],
			['"max_spikes":32', '"max_spikes":129'],
			['"tick_total_ns":20', '"tick_total_ns":19'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[2,0,0,1,20,20,20]]'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[1,1,0,1,20,20,20]]'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[1,0,1,1,20,20,20]]'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[1,0,0,0,20,20,20]]'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[1,0,0,1,20,21,20]]'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[1,0,0,1,20,20,21]]'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[1,0,0,1,20,20]]'],
			['"nodes":[[1,0,0,1,20,20,20]]', '"nodes":[[1,0,0,true,20,20,20]]'],
			['"active_ticks":[1]', '"active_ticks":[]'],
			['"active_ticks":[1]', '"active_ticks":[2]'],
			['"ticks":[[1,0,20]]', '"ticks":[[2,0,20]]'],
			['"ticks":[[1,0,20]]', '"ticks":[[1,1,20]]'],
			['"spike_threshold_ns":5', '"spike_threshold_ns":20'],
			['"tick":[1,0,20]', '"tick":[1,0,19]'],
			['"nodes":[[1,1,20,20]]', '"nodes":[[2,1,20,20]]'],
			['"nodes":[[1,1,20,20]]', '"nodes":[[1,2,20,20]]'],
			['"nodes":[[1,1,20,20]]', '"nodes":[[1,1,20,21]]'],
			['"zones":["zone"]', '"zones":["zone","zone"]'],
			['"worlds":["Lobby"]', '"worlds":{}'],
			['"worlds":["Lobby"]', '"worlds":{"0":"Lobby"}'],
			['"active_ticks":[1]', '"active_ticks":{"0":1}'],
			['"format":"quark.pulse"', '"extra":[],"format":"quark.pulse"']
		] as [$from, $to]){
			$invalid = str_replace($from, $to, $json);
			self::assertNotSame($json, $invalid);
			try{
				PulseReport::decode($invalid);
				self::fail("Malformed Pulse report accepted: $to");
			}catch(\InvalidArgumentException $e){
				self::assertNotSame("", $e->getMessage());
			}
		}
	}

	public function testMalformedJsonAndNestingAreRejected() : void{
		foreach(["", "[]", "{}", "null", substr($this->report()->encode(), 0, -1), str_repeat("[", 17) . "0" . str_repeat("]", 17), '"' . "\xff" . '"'] as $json){
			try{
				PulseReport::decode($json);
				self::fail("Invalid JSON accepted");
			}catch(\InvalidArgumentException $e){
				self::assertNotSame("", $e->getMessage());
			}
		}
	}

	public function testSizeAndContainerBudgetsAreCheckedBeforeDecoding() : void{
		foreach([str_repeat(" ", PulseReport::MAX_BYTES + 1), "[" . str_repeat("[],", 300000) . "[]]"] as $json){
			try{
				PulseReport::decode($json);
				self::fail("Oversized report accepted");
			}catch(\LengthException $e){
				self::assertNotSame("", $e->getMessage());
			}
		}
	}

	public function testBudgetScanIgnoresPunctuationAndEscapesInsideStrings() : void{
		$context = new PulseContext();
		for($i = 0; $i < 2000; ++$i){
			$context->zone('zone\\"' . str_repeat("[", 240) . $i);
		}
		$context->start("main", 0);
		$context->stop(0);
		$report = PulseReport::create([$context->capture()]);
		self::assertSame($report->getData(), PulseReport::decode($report->encode())->getData());
	}

	public function testDuplicateThreadNamesAreRejected() : void{
		$thread = $this->report()->getData()["threads"][0];
		$this->expectException(\InvalidArgumentException::class);
		PulseReport::create([$thread, $thread]);
	}
}
