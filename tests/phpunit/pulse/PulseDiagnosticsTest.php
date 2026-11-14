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
use pocketmine\snooze\SleeperHandler;
use quark\FilesystemPaths;
use quark\pulse\internal\PulseContext;
use quark\pulse\internal\PulseNetwork;
use quark\pulse\internal\PulseRecorder;
use quark\pulse\internal\PulseWorkers;
use quark\pulse\internal\PulseZones;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\Server;
use quark\thread\ThreadSafeClassLoader;
use quark\utils\MainLogger;
use function extension_loaded;
use function glob;
use function hrtime;
use function json_encode;
use function microtime;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;
use const JSON_THROW_ON_ERROR;

final class PulseDiagnosticsTest extends TestCase{
	protected function tearDown() : void{ Pulse::reset(); }

	public function testPluginAttributionAvoidsNestedDoubleCounting() : void{
		$context = new PulseContext();
		$a = $context->zone("plugin.events.A.e", "A v1")->getId();
		$task = $context->zone("plugin.tasks.A.t", "A v1")->getId();
		$b = $context->zone("plugin.events.B.e", "B v1")->getId();
		$server = $context->zone("world.w.blocks.set")->getId();
		$session = new PulseSession($context, "main");
		$t = (int) hrtime(true);
		$context->beginTick($t);
		$outer = $context->begin($a, $t);
		$scope = $context->begin($server, $t + 10);
		$context->end($server, $scope, $t + 30);
		$scope = $context->begin($task, $t + 40);
		$context->end($task, $scope, $t + 60);
		$scope = $context->begin($b, $t + 70);
		$context->end($b, $scope, $t + 90);
		$context->end($a, $outer, $t + 100);
		$scope = $context->begin($task, $t + 100);
		$context->end($task, $scope, $t + 110);
		$context->endTick($t + 200);
		$context->stop($t + 200);

		$plugins = $session->getPlugins(10);
		self::assertCount(2, $plugins);
		self::assertSame(["name" => "A v1", "calls" => 2, "total_ns" => 110, "self_ns" => 70, "max_ns" => 100, "tick_share" => 0.55], $plugins[0]);
		self::assertSame(["name" => "B v1", "calls" => 1, "total_ns" => 20, "self_ns" => 20, "max_ns" => 20, "tick_share" => 0.1], $plugins[1]);
		self::assertCount(1, $session->getPlugins(1));
		self::assertSame(["plugins", 90], $session->getCategories()[0]);
	}

	public function testPluginOwnersAreNamesAndSurviveRestarts() : void{
		self::assertSame("plugin.lifecycle.A v1.enable", PulseZones::getPluginLifecycleZone("A v1", "enable")->getName());
		$context = new PulseContext();
		$zone = $context->zone("plugin.commands.A.cmd", "A v1");
		self::assertSame($zone, $context->zone("plugin.commands.A.cmd"));
		try{
			$context->zone("plugin.bad", "\xff");
			self::fail("Invalid owner accepted");
		}catch(\InvalidArgumentException){}
		self::assertSame("plugin.events.long", PulseZones::dynamic("plugin.events.long", str_repeat("x", 300))->getName());
		for($i = 0; $i < 3; ++$i){
			$context->start("main", $i * 10);
			$context->stop($i * 10 + 5);
			self::assertSame([[0, "A v1"]], $context->capture()["zone_owners"] ?? null);
			self::assertCount(0, $context->capture()["nodes"]);
		}
	}

	public function testSpikeBreakdownSeparatesUnattributedTime() : void{
		$context = new PulseContext();
		$tick = $context->zone("server.tick")->getId();
		$entities = $context->zone("world.my.world.entities")->getId();
		$plugin = $context->zone("plugin.events.A.e", "A v1")->getId();
		$redstone = $context->zone("redstone.tick")->getId();
		$session = new PulseSession($context, "main", spikeThresholdNs: 50);
		$t = (int) hrtime(true);
		$context->beginTick($t);
		$root = $context->begin($tick, $t);
		foreach([[$entities, 100, 400], [$plugin, 400, 600], [$redstone, 600, 700]] as [$zone, $from, $to]){
			$scope = $context->begin($zone, $t + $from);
			$context->end($zone, $scope, $t + $to);
		}
		$context->end($tick, $root, $t + 900);
		$context->endTick($t + 1000);
		$context->beginTick($t + 1000);
		$context->endTick($t + 1010);
		$context->stop($t + 1010);

		$spikes = $session->getSpikes();
		self::assertCount(1, $spikes);
		self::assertSame([["entities", 300], ["plugins", 200], ["redstone", 100]], $spikes[0]["categories"]);
		self::assertSame(400, $spikes[0]["unattributed_ns"]);
		$breakdown = $session->getSpikeBreakdown();
		self::assertSame(1, $breakdown["spikes"]);
		self::assertSame(1000, $breakdown["duration_ns"]);
		self::assertSame(400, $breakdown["unattributed_ns"]);
		foreach([
			"world.a.b.chunk.save" => "chunk storage", "world.a.chunk.populate" => "chunk generation", "world.a.tick" => "world",
			"network.compress.session" => "compression", "network.encode" => "network", "block.hoppers" => "hoppers",
			"block_entity.tick.Chest" => "tiles", "runtime.gc" => "gc", "plugin.tasks.Unknown.x" => "scheduler", "custom" => "other"
		] as $zone => $category){
			self::assertSame($category, PulseSession::category($zone), $zone);
		}
	}

	public function testWorkerTelemetryIgnoresStaleTasksAndBoundsWorkers() : void{
		$workers = new PulseWorkers(100);
		$workers->submitted(2);
		$workers->submitted(5);
		$workers->submitted(1);
		$workers->completed(0, 50, 150, 250, 1000, 300);
		$workers->completed(0, 100, 150, 250, 1000, 300);
		$workers->completed(1, 120, 120, 100, 2000, 90);
		$workers->completed(PulseWorkers::MAX_WORKERS, 100, 110, 120, 1, 130);
		$workers->completed(0, 100, 150, 250, 500, 300);
		$capture = $workers->capture();
		self::assertSame(3, $capture["submitted"]);
		self::assertSame(3, $capture["completed"]);
		self::assertSame(5, $capture["queue_peak"]);
		self::assertSame([50 + 0 + 10, 50], [$capture["wait_total_ns"], $capture["wait_max_ns"]]);
		self::assertSame([100 + 0 + 10, 100], [$capture["run_total_ns"], $capture["run_max_ns"]]);
		self::assertSame([50 + 0 + 10, 50], [$capture["delay_total_ns"], $capture["delay_max_ns"]]);
		self::assertSame([[0, 1, 100, 1000, 1000], [1, 1, 0, 2000, 2000]], $capture["workers"]);
	}

	public function testAsyncPoolReportsQueueRunAndPickupTimes() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){ self::markTestSkipped("Requires pmmpthread and igbinary"); }
		$logger = new MainLogger(null, false, "Pulse diagnostics test", new \DateTimeZone("UTC"));
		$pool = new AsyncPool(1, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0);
		$recorder = new PulseRecorder($pool);
		try{
			$idle = new PulseDiagnosticsSleepTask();
			$pool->submitTask($idle);
			$this->drain($pool);
			self::assertNull($idle->getPulseTimes());
			$recorder->start();
			$this->drain($pool);
			for($i = 0; $i < 3; ++$i){ $pool->submitTaskToWorker(new PulseDiagnosticsSleepTask(), 0); }
			$this->drain($pool);
			$session = $recorder->getSession();
			self::assertNotNull($session);
			$async = $session->getCapture()["async"] ?? null;
			self::assertNotNull($async);
			self::assertSame(3, $async["submitted"]);
			self::assertSame(3, $async["completed"]);
			self::assertGreaterThanOrEqual(2, $async["queue_peak"]);
			self::assertGreaterThanOrEqual(6000000, $async["run_total_ns"]);
			self::assertGreaterThan(0, $async["wait_max_ns"]);
			self::assertCount(1, $async["workers"]);
			self::assertSame(3, $async["workers"][0][1]);
			self::assertGreaterThan(0, $async["workers"][0][3]);
			$pool->submitTaskToWorker(new PulseDiagnosticsSleepTask(), 0);
			$recorder->stop();
			$this->drain($pool);
			self::assertSame(4, $session->getCapture()["async"]["submitted"] ?? null);
			self::assertSame(3, $session->getCapture()["async"]["completed"] ?? null);
			PulseReport::decode($session->getReport()->encode());
		}finally{
			$recorder->stop();
			$pool->shutdown();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
		}
	}

	private function drain(AsyncPool $pool) : void{
		$deadline = microtime(true) + 10;
		while($pool->collectTasks()){
			self::assertLessThan($deadline, microtime(true), "Async pool timed out");
			usleep(1000);
		}
	}

	public function testTransportCountersUseSessionDeltasAndPeaks() : void{
		$network = new PulseNetwork(0);
		$network->transportTotals("bedwire", ["bytes_sent" => 100, "sessions" => 2, "command_queue_bytes" => 10]);
		$network->transportTotals("bedwire", ["bytes_sent" => 160, "sessions" => 5, "command_queue_bytes" => 4]);
		$network->transportBytes("raklib", 10, 20);
		$network->transportBytes("raklib", 15, -1);
		$network->stop();
		$network->transportBytes("raklib", 1000, 1000);
		self::assertSame([
			["name" => "raklib", "counters" => [["bytes_sent", 25], ["bytes_received", 20]]],
			["name" => "bedwire", "counters" => [["bytes_sent", 60], ["sessions_peak", 5], ["command_queue_peak_bytes", 10]]]
		], $network->capture(1)["transports"] ?? null);
	}

	public function testVersionFourFieldsRoundTripAndRejectMalformedData() : void{
		$context = new PulseContext();
		$context->zone("plugin.events.A.e", "A v1");
		$context->zone("other");
		$context->start("main", 0);
		$context->network()->transportTotals("bedwire", ["bytes_sent" => 1]);
		$context->stop(10);
		$report = PulseReport::create([$context->capture()]);
		$data = $report->getData();
		self::assertSame($data, PulseReport::decode($report->encode(true))->getData());
		$thread = $data["threads"][0];
		self::assertCount(8, $thread["resources"]["samples"][0] ?? []);
		foreach([
			["zone_owners", [[1, "A"], [0, "B"]]],
			["zone_owners", [[2, "A"]]],
			["zone_owners", [[0, ""]]],
			["zone_owners", [[0, "A", 1]]],
			["async", null, "submitted", 0, "completed", 1],
			["async", null, "workers", [[0, 1, 0, 0, 0]]],
			["async", null, "run_max_ns", 1],
			["network", null, "transports", [["name" => "udp", "counters" => []]]],
			["network", null, "transports", [["name" => "bedwire", "counters" => [["Bad", 1]]]]],
			["network", null, "transports", [["name" => "bedwire", "counters" => [["a", 1], ["a", 2]]]]],
			["network", null, "transports", [["name" => "bedwire", "counters" => [["a", -1]]]]],
			["resources", null, "samples", [[0, 0, null, null, 1, 1, 5, null]]],
			["resources", null, "samples", [[0, 0, null, null, 1, 1, 6, 5]]],
			["resources", null, "samples", [[0, 0, null, null, 1, 1]]]
		] as $case){
			$invalid = $data;
			if($case[1] === null){
				$invalid["threads"][0][$case[0]][$case[2]] = $case[3];
				if(isset($case[4])){ $invalid["threads"][0][$case[0]][$case[4]] = $case[5]; }
			}else{
				$invalid["threads"][0][$case[0]] = $case[1];
			}
			try{
				PulseReport::decode(json_encode($invalid, JSON_THROW_ON_ERROR));
				self::fail("Malformed Pulse v4 data accepted: " . json_encode($case, JSON_THROW_ON_ERROR));
			}catch(\InvalidArgumentException){
				$this->addToAssertionCount(1);
			}
		}
		$missing = $data;
		unset($missing["threads"][0]["async"]);
		$missing["threads"][0]["extra"] = null;
		try{ PulseReport::decode(json_encode($missing, JSON_THROW_ON_ERROR)); self::fail("Missing async data accepted"); }catch(\InvalidArgumentException){}
	}

	public function testAutomaticCaptureRecordsOnceAndSavesOnlyExpiredSessions() : void{
		$directory = sys_get_temp_dir() . "/" . uniqid("quark-pulse-auto-", true);
		$server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(["getQuarkVersion", "getFilesystemPaths"])->getMock();
		$server->method("getQuarkVersion")->willReturn("test");
		$server->method("getFilesystemPaths")->willReturn(new FilesystemPaths($directory, $directory . "/plugins"));
		$pool = self::createStub(AsyncPool::class);
		$pool->method("getSize")->willReturn(1);
		$pool->method("getRunningWorkers")->willReturn([]);
		$recorder = new PulseRecorder($pool);
		$logger = new MainLogger(null, false, "Pulse auto test", new \DateTimeZone("UTC"));
		foreach(["pulse" => $recorder, "logger" => $logger, "pulseAutoCaptureNs" => 100_000_000] as $property => $value){
			(new \ReflectionProperty(Server::class, $property))->setValue($server, $value);
		}
		$check = new \ReflectionMethod(Server::class, "checkPulseAutoCapture");
		try{
			$check->invoke($server, 100_000_000);
			self::assertNull($recorder->getSession());
			$check->invoke($server, 150_000_000);
			$auto = $recorder->getSession();
			self::assertNotNull($auto);
			self::assertTrue($auto->isRecording());
			self::assertSame(100_000_000, $auto->getStats()["spike_threshold_ns"]);
			$check->invoke($server, 150_000_000);
			self::assertSame($auto, $recorder->getSession());

			// A manual stop before expiry is saved by the command, not here.
			$recorder->stop();
			$check->invoke($server, 1);
			self::assertSame([], self::files($directory . "/diagnostics/profiles/*.qpulse"));
			$check->invoke($server, 150_000_000);
			self::assertSame($auto, $recorder->getSession(), "Cooldown ignored");

			(new \ReflectionProperty(Server::class, "pulseAutoCaptureReady"))->setValue($server, 0);
			$check->invoke($server, 150_000_000);
			$second = $recorder->getSession();
			self::assertNotSame($auto, $second);
			$context = (new \ReflectionProperty(Pulse::class, "context"))->getValue();
			self::assertInstanceOf(PulseContext::class, $context);
			(new \ReflectionProperty(PulseContext::class, "duration"))->setValue($context, 1);
			Pulse::checkDuration();
			$recorder->checkDuration();
			$check->invoke($server, 1);
			self::assertCount(1, self::files($directory . "/diagnostics/profiles/*.qpulse"));

			$recorder->reset();
			$recorder->start();
			(new \ReflectionProperty(Server::class, "pulseAutoCaptureReady"))->setValue($server, 0);
			$manual = $recorder->getSession();
			$recorder->stop();
			$check->invoke($server, 150_000_000);
			self::assertSame($manual, $recorder->getSession(), "Automatic capture replaced a manual session");
		}finally{
			$recorder->stop();
			foreach(self::files($directory . "/diagnostics/profiles/*") as $file){ unlink($file); }
			foreach([$directory . "/diagnostics/profiles", $directory . "/diagnostics", $directory] as $path){ @rmdir($path); }
			$logger->shutdownLogWriterThread();
		}
	}

	/** @return list<string> */
	private static function files(string $pattern) : array{
		$files = glob($pattern);
		return $files === false ? [] : $files;
	}

	public function testDisabledProfilerRecordsNothing() : void{
		$context = new PulseContext();
		$zone = $context->zone("idle", "A v1");
		self::assertSame(0, $zone->start());
		self::assertNull($context->workers());
		self::assertSame(42, $zone->time(fn() => 42));
		self::assertNull(Pulse::getWorkerTelemetry());
		self::assertNull(Pulse::getNetworkTelemetry());
		$context->start("worker#0", 0);
		self::assertNull($context->workers());
		self::assertNull($context->capture()["async"] ?? null);
		$context->stop(1);
		self::assertCount(0, $context->capture()["nodes"]);
	}
}

final class PulseDiagnosticsSleepTask extends AsyncTask{
	public function onRun() : void{
		usleep(3000);
	}
}
