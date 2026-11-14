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
use quark\pulse\internal\PulseResources;
use function array_column;
use function array_sum;
use function function_exists;
use function json_encode;
use function max;
use function memory_get_usage;
use const JSON_THROW_ON_ERROR;

final class PulseResourcesTest extends TestCase{
	public function testShortStopSamplesKeepMemoryWithoutReportingCpuSpikes() : void{
		$resources = new PulseResources(0);
		$resources->sample(PulseResources::INTERVAL_NS);
		$before = $resources->stats();
		$resources->sample(PulseResources::INTERVAL_NS + 1, true);
		$last = $resources->capture()["samples"][2];
		self::assertSame(1, $last[1]);
		self::assertNull($last[2]);
		self::assertNull($last[3]);
		$stats = $resources->stats();
		self::assertNull($stats["cpu_percent"]);
		self::assertSame($before["cpu_avg_percent"], $stats["cpu_avg_percent"]);
		self::assertSame($before["cpu_peak_percent"], $stats["cpu_peak_percent"]);
		self::assertSame($last[4], $stats["memory_bytes"]);
		$short = new PulseResources(0);
		$short->sample(PulseResources::INTERVAL_NS - 1, true);
		self::assertNull($short->stats()["cpu_percent"]);
		self::assertNull($short->stats()["cpu_peak_percent"]);
	}

	public function testCpuPercentagesIncludeMultipleCoresAndWeightUnequalIntervals() : void{
		$resources = new PulseResources(0);
		(new \ReflectionProperty(PulseResources::class, "samples"))->setValue($resources, [
			[0, 0, null, null, 10, 100],
			[3000000000, 3000000000, 2000000000, 1000000000, 30, 100],
			[4000000000, 1000000000, 1000000000, 1000000000, 20, 100]
		]);
		(new \ReflectionProperty(PulseResources::class, "cursor"))->setValue($resources, 3);
		$stats = $resources->stats();
		self::assertSame(200.0, $stats["cpu_percent"]);
		self::assertSame(125.0, $stats["cpu_avg_percent"]);
		self::assertSame(200.0, $stats["cpu_peak_percent"]);
		self::assertSame(30, $stats["memory_peak_bytes"]);
		self::assertSame(20, $stats["memory_bytes"]);
	}

	public function testResourcesSampleAtMostOncePerSecondAndFlushOnStop() : void{
		$context = new PulseContext();
		$context->start("main", 100);
		$initial = $context->capture()["resources"] ?? null;
		self::assertNotNull($initial);
		self::assertCount(1, $initial["samples"]);
		$stats = $context->stats()["resources"];
		self::assertNotNull($stats);
		self::assertNull($stats["cpu_percent"]);
		foreach([200, 999999999, 1000000100, 1000000200] as $now){
			$context->beginTick($now);
			$context->endTick($now);
		}
		$resources = $context->capture()["resources"] ?? null;
		self::assertNotNull($resources);
		self::assertSame([0, 1000000000], array_column($resources["samples"], 0));
		$context->stop(1500000100);
		$capture = $context->capture();
		$resources = $capture["resources"] ?? null;
		self::assertNotNull($resources);
		self::assertSame([0, 1000000000, 1500000000], array_column($resources["samples"], 0));
		self::assertSame([0, 1000000000, 500000000], array_column($resources["samples"], 1));
		$stats = $context->stats()["resources"];
		self::assertNotNull($stats);
		self::assertSame($resources["samples"][2][4], $stats["memory_bytes"]);
		self::assertSame(max(array_column($resources["samples"], 4)), $stats["memory_peak_bytes"]);
		if(function_exists("getrusage")){
			$cpu = array_sum(array_column($resources["samples"], 2)) + array_sum(array_column($resources["samples"], 3));
			self::assertEqualsWithDelta($cpu / 1000000000 * 100, $stats["cpu_avg_percent"], 0.000001);
		}else{
			self::assertNull($stats["cpu_avg_percent"]);
		}
		self::assertNull($stats["cpu_percent"]);
		self::assertSame($capture, PulseReport::decode(PulseReport::create([$capture])->encode(true))->getData()["threads"][0]);
		$context->start("worker#0", 2000000000);
		self::assertNull($context->capture()["resources"] ?? null);
		$context->stop(2000000000);
		$context->start("main", 3000000000);
		self::assertCount(1, $context->capture()["resources"]["samples"] ?? []);
		self::assertSame(0, $context->capture()["resources"]["samples_dropped"] ?? null);
	}

	public function testResourceHistoryWrapsWithoutRetainingMoreMemory() : void{
		$resources = new PulseResources(0);
		for($i = 1; $i < PulseResources::MAX_SAMPLES; ++$i){ $resources->sample($i * PulseResources::INTERVAL_NS); }
		$before = memory_get_usage();
		for($i = PulseResources::MAX_SAMPLES; $i < PulseResources::MAX_SAMPLES + 100; ++$i){ $resources->sample($i * PulseResources::INTERVAL_NS); }
		self::assertSame($before, memory_get_usage());
		$capture = $resources->capture();
		self::assertCount(PulseResources::MAX_SAMPLES, $capture["samples"]);
		self::assertSame(100, $capture["samples_dropped"]);
		self::assertSame(100 * PulseResources::INTERVAL_NS, $capture["samples"][0][0]);
		self::assertSame((PulseResources::MAX_SAMPLES + 99) * PulseResources::INTERVAL_NS, $capture["samples"][PulseResources::MAX_SAMPLES - 1][0]);
		$context = new PulseContext();
		$context->start("main", 0);
		$context->stop((PulseResources::MAX_SAMPLES + 99) * PulseResources::INTERVAL_NS);
		$thread = $context->capture();
		$thread["resources"] = $capture;
		self::assertSame($thread, PulseReport::decode(PulseReport::create([$thread])->encode())->getData()["threads"][0]);
	}

	public function testRecentPercentilesAndSessionSlowTicksSurviveWrapAndReset() : void{
		$context = new PulseContext(maxTicks: 4);
		$context->start("main", 0);
		self::assertSame(0, $context->stats()["tick_p99_ns"]);
		for($i = 0; $i < 5; ++$i){
			$context->beginTick($i * 100000000);
			$context->endTick($i * 100000000 + [90000000, 10000000, 20000000, 50000000, 80000000][$i]);
		}
		$stats = $context->stats();
		self::assertSame(4, $stats["tick_sample_count"]);
		self::assertSame(20000000, $stats["tick_p50_ns"]);
		self::assertSame(80000000, $stats["tick_p95_ns"]);
		self::assertSame(80000000, $stats["tick_p99_ns"]);
		self::assertSame(2, $stats["slow_tick_count"]);
		$context->stop(500000000);
		self::assertSame(2, $context->capture()["slow_tick_count"] ?? null);
		$context->start("main", 600000000);
		self::assertSame(0, $context->stats()["slow_tick_count"]);
		self::assertSame(0, $context->stats()["tick_p99_ns"]);
	}

	public function testOlderReportVersionsRemainReadable() : void{
		$context = new PulseContext();
		$context->start("main", 0);
		$context->stop(0);
		$data = PulseReport::create([$context->capture()])->getData();
		unset($data["threads"][0]["resources"], $data["threads"][0]["slow_tick_count"]);
		foreach([2, 1] as $version){
			$data["version"] = $version;
			if($version === 1){ unset($data["threads"][0]["network"]); }
			self::assertSame($data, PulseReport::decode(json_encode($data, JSON_THROW_ON_ERROR))->getData());
		}
	}

	public function testMalformedResourcesAndSlowTickCountsAreRejected() : void{
		$context = new PulseContext();
		$context->start("main", 0);
		$context->stop(100);
		$data = PulseReport::create([$context->capture()])->getData();
		foreach([
			["samples", []],
			["samples", [[1, 0, null, null, 1, 2]]],
			["samples", [[0, 0, null, 0, 1, 2]]],
			["samples", [[0, 0, -1, 0, 1, 2]]],
			["samples", [[0, 0, 0, 0, 3, 2]]],
			["samples", [[0, 0, null, null, 1, 2], [101, 101, 0, 0, 1, 2]]],
			["samples", [[0, 0, null, null, 1, 2], [100, 99, 0, 0, 1, 2]]],
			["samples_dropped", -1], ["samples_dropped", 1]
		] as [$key, $value]){
			$invalid = $data;
			$invalid["threads"][0]["resources"][$key] = $value;
			try{ PulseReport::decode(json_encode($invalid, JSON_THROW_ON_ERROR)); self::fail("Malformed resource data accepted"); }catch(\InvalidArgumentException){}
		}
		$data["threads"][0]["slow_tick_count"] = 1;
		$this->expectException(\InvalidArgumentException::class);
		PulseReport::decode(json_encode($data, JSON_THROW_ON_ERROR));
	}
}
