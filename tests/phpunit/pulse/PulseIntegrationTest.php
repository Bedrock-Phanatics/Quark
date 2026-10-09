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

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use quark\command\CommandSender;
use quark\command\defaults\PulseCommand;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\lang\Translatable;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\permission\PermissionManager;
use quark\promise\Promise;
use quark\promise\PromiseResolver;
use quark\pulse\internal\PulseRecorder;
use quark\pulse\internal\PulseZones;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\scheduler\PulseControlTask;
use quark\Server;
use function count;
use function extension_loaded;
use function file_get_contents;
use function glob;
use function hrtime;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class PulseIntegrationTest extends TestCase{
	protected function tearDown() : void{ Pulse::reset(); }

	private function recorder() : PulseRecorder{
		$pool = self::createStub(AsyncPool::class);
		$pool->method("getSize")->willReturn(2);
		$pool->method("getRunningWorkers")->willReturn([]);
		return new PulseRecorder($pool);
	}

	public function testPulsePermissionIsRegisteredForOperators() : void{
		DefaultPermissions::registerCorePermissions();
		$manager = PermissionManager::getInstance();
		self::assertNotNull($manager->getPermission(DefaultPermissionNames::COMMAND_PULSE));
		$operator = $manager->getPermission(DefaultPermissions::ROOT_OPERATOR);
		self::assertNotNull($operator);
		self::assertTrue($operator->getChildren()[DefaultPermissionNames::COMMAND_PULSE]);
	}

	public function testInternalNamesRespectZoneLimits() : void{
		self::assertSame("world.test.tick", PulseZones::dynamic("world.test.tick")->getName());
		$long = "world." . str_repeat("界", 100) . ".tick";
		self::assertSame(PulseZones::dynamic($long), PulseZones::dynamic($long));
		self::assertNotSame(PulseZones::dynamic($long), PulseZones::dynamic($long . "2"));
		self::assertStringStartsWith("dynamic.", PulseZones::dynamic("world.\xff.tick")->getName());
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testInternalZoneExhaustionKeepsServerWorkRecordable() : void{
		PulseZones::init();
		for($i = 0; $i < 4096; ++$i){
			try{ Pulse::zone("capacity.$i"); }catch(\LengthException){ break; }
		}
		$zone = PulseZones::dynamic("capacity.overflow");
		self::assertSame("pulse.zone_limit", $zone->getName());
		$session = Pulse::start();
		$scope = $zone->start();
		$zone->stop($scope);
		$session->stop();
		self::assertCount(4096, $session->getCapture()["zones"]);
		self::assertSame(1, $session->getCapture()["nodes"][0][3]);
		PulseReport::decode($session->getReport()->encode());
	}

	public function testCommandOptionsValidateUnitsLimitsAndDuplicates() : void{
		self::assertSame([0, 0, 32], PulseCommand::parseOptions([]));
		self::assertSame([60000000000, 50000000, 16], PulseCommand::parseOptions(["--duration", "60s", "--spikes", "50ms", "--max-spikes", "16"]));
		self::assertSame([1500000000, 0, 32], PulseCommand::parseOptions(["--duration", "1.5s"]));
		self::assertSame([60000000000, 50000000, 16], PulseCommand::parseOptions(["1m", "--spikes", "50ms", "--max-spikes", "16"]));
		self::assertSame([86400000000000, 60000000000, 128], PulseCommand::parseOptions(["--duration", "1440m", "--spikes", "1m", "--max-spikes", "128"]));
		foreach([
			["--duration"], ["--other", "1s"], ["--duration", "0s"], ["--duration", "-1s"],
			["--duration", "86401s"], ["--duration", "1e9s"], ["--duration", "1s\n"],
			["--spikes", "61s"], ["--spikes", "nanms"], ["--max-spikes", "0"],
			["--max-spikes", "129"], ["--max-spikes", "1.5"], ["--duration", "1s", "--duration", "2s"],
			["60s", "--duration", "1s"], ["60s", "extra"], ["0s"], [str_repeat("9", 100) . "s"]
		] as $args){
			try{
				PulseCommand::parseOptions($args);
				self::fail("Invalid Pulse options accepted");
			}catch(InvalidCommandSyntaxException){
				self::assertFalse(Pulse::isRecording());
			}
		}
	}

	public function testCommandWorkflowAndReportMessageOrder() : void{
		DefaultPermissions::registerCorePermissions();
		$recorder = $this->recorder();
		$server = self::createStub(Server::class);
		$server->method("getPulse")->willReturn($recorder);
		$server->method("getBroadcastChannelSubscribers")->willReturn([]);
		$saves = 0;
		$server->method("createPulseReport")->willReturnCallback(static function() use ($recorder, &$saves) : Promise{
			self::assertFalse($recorder->isRecording());
			++$saves;
			/** @var PromiseResolver<string> $result */
			$result = new PromiseResolver();
			$result->resolve("test.qpulse");
			return $result->getPromise();
		});
		/** @var \ArrayObject<int, string> $messages */
		$messages = new \ArrayObject();
		$sender = self::createStub(CommandSender::class);
		$sender->method("getServer")->willReturn($server);
		$sender->method("getName")->willReturn("test");
		$sender->method("sendMessage")->willReturnCallback(static function(Translatable|string $message) use ($messages) : void{
			self::assertIsString($message);
			$messages[] = $message;
		});
		$command = new PulseCommand();
		$command->execute($sender, "pulse", []);
		self::assertStringContainsString("Pulse is idle", $messages[0] ?? "");
		$messages->exchangeArray([]);
		$command->execute($sender, "pulse", ["help"]);
		self::assertCount(5, $messages);
		$command->execute($sender, "pulse", ["start", "1m", "--spikes", "50ms"]);
		$session = $recorder->getSession();
		self::assertNotNull($session);
		self::assertSame(60000000000, $session->getStats()["duration_ns"]);
		$zone = Pulse::zone("command.work\n\x1b§cBAD");
		$scope = $zone->start();
		$zone->stop($scope);
		$messages->exchangeArray([]);
		$command->execute($sender, "pulse", ["top", "1"]);
		self::assertCount(2, $messages);
		self::assertStringContainsString("command.work BAD", $messages[1] ?? "");
		self::assertStringNotContainsString("\n", $messages[1] ?? "");
		self::assertStringNotContainsString("\x1b", $messages[1] ?? "");
		$messages->exchangeArray([]);
		$command->execute($sender, "pulse", ["stop", "--report"]);
		self::assertFalse($session->isRecording());
		self::assertSame(1, $saves);
		self::assertStringStartsWith("Collecting Pulse report", $messages[count($messages) - 2] ?? "");
		self::assertSame("Pulse report saved to test.qpulse", $messages[count($messages) - 1]);
		foreach([["start", "60s", "--duration", "1s"], ["top", "11"], ["top", "-1"], ["stop", "other"], ["status", "extra"], ["unknown"]] as $args){
			$messages->exchangeArray([]);
			$command->execute($sender, "pulse", $args);
			self::assertStringStartsWith("Usage:", $messages[0] ?? "");
			self::assertSame($session, $recorder->getSession());
			self::assertFalse($session->isRecording());
		}
		$command->execute($sender, "pulse", ["reset"]);
		self::assertNull($recorder->getSession());
	}

	public function testTimedOutReportReleasesPayloadAndIgnoresLateWorkers() : void{
		$workers = [];
		/** @var \ArrayObject<int, AsyncTask> $tasks */
		$tasks = new \ArrayObject();
		$pool = self::createStub(AsyncPool::class);
		$pool->method("getSize")->willReturn(2);
		$pool->method("getRunningWorkers")->willReturnCallback(static function() use (&$workers) : array{ return $workers; });
		$pool->method("submitTaskToWorker")->willReturnCallback(static function(AsyncTask $task, int $worker) use ($tasks) : void{ $tasks[] = $task; });
		$recorder = new PulseRecorder($pool);
		$recorder->start();
		$workers = [0, 1];
		$failures = 0;
		$recorder->collect()->onCompletion(fn() => self::fail("Timed-out report resolved"), static function() use (&$failures) : void{ ++$failures; });
		self::assertSame(2, $recorder->getPendingOperations());
		(new \ReflectionProperty(PulseRecorder::class, "collectionDeadline"))->setValue($recorder, (int) hrtime(true) - 1);
		$recorder->checkDuration();
		self::assertSame(1, $failures);
		self::assertSame([], (new \ReflectionProperty(PulseRecorder::class, "captures"))->getValue($recorder));
		self::assertTrue($recorder->isRecording());
		foreach(["collect", "reset"] as $operation){
			try{
				if($operation === "collect"){ $recorder->collect(); }else{ $recorder->reset(); }
				self::fail("Pending workers must prevent more queued operations");
			}catch(\LogicException){}
		}
		foreach($tasks as $task){ $task->onCompletion(); }
		$tasks->exchangeArray([]);
		self::assertSame(0, $recorder->getPendingOperations());
		$report = null;
		$recorder->collect()->onCompletion(static function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Retry rejected"));
		foreach($tasks as $task){ $task->onCompletion(); }
		self::assertNotNull($report);
		self::assertCount(1, $report->getData()["threads"]);
		self::assertSame(0, $recorder->getPendingOperations());
		$workers = [];
		$recorder->reset();
	}

	public function testPartialReportSubmissionFailureDrainsBeforeRetry() : void{
		$workers = [];
		$task = null;
		$pool = self::createStub(AsyncPool::class);
		$pool->method("getSize")->willReturn(2);
		$pool->method("getRunningWorkers")->willReturnCallback(static function() use (&$workers) : array{ return $workers; });
		$pool->method("submitTaskToWorker")->willReturnCallback(static function(AsyncTask $value, int $worker) use (&$task) : void{
			if($worker === 1){ throw new \RuntimeException("submission failed"); }
			$task = $value;
		});
		$recorder = new PulseRecorder($pool);
		$recorder->start();
		$workers = [0, 1];
		try{ $recorder->collect(); self::fail("Submission must fail"); }catch(\RuntimeException){}
		self::assertSame(1, $recorder->getPendingOperations());
		self::assertInstanceOf(PulseControlTask::class, $task);
		$task->onCompletion();
		self::assertSame(0, $recorder->getPendingOperations());
		$workers = [];
		$report = null;
		$recorder->collect()->onCompletion(static function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Retry rejected"));
		self::assertNotNull($report);
	}

	public function testRecorderReportsStoppedSessionAndResetsCleanly() : void{
		$recorder = $this->recorder();
		$recorder->start();
		$session = $recorder->getSession();
		self::assertNotNull($session);
		$zone = Pulse::zone("integration.work");
		$scope = $zone->start();
		$zone->stop($scope);
		$recorder->stop();
		$report = null;
		$recorder->collect()->onCompletion(function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Report rejected"));
		self::assertNotNull($report);
		self::assertSame($session->getCapture(), $report->getData()["threads"][0]);
		$recorder->reset();
		self::assertNull($recorder->getSession());
		self::assertNull(Pulse::getSession());
		$recorder->start();
		self::assertTrue($recorder->isRecording());
		self::assertFalse($session->isRecording());
	}

	public function testRecorderResetRestartsActiveCaptureAndDurationExpires() : void{
		$recorder = $this->recorder();
		$recorder->start();
		$previous = $recorder->getSession();
		$recorder->reset();
		self::assertTrue($recorder->isRecording());
		self::assertNotSame($previous, $recorder->getSession());
		$recorder->stop();
		$recorder->start(durationNs: 1);
		Pulse::checkDuration();
		$recorder->checkDuration();
		self::assertFalse($recorder->isRecording());
	}

	public function testReportWithoutSessionIsRejected() : void{
		$this->expectException(\LogicException::class);
		$this->recorder()->collect();
	}

	public function testFailedWorkerSubmissionDoesNotLockTheRecorder() : void{
		if(!extension_loaded("pmmpthread")){
			self::markTestSkipped("Requires pmmpthread");
		}
		$workers = [];
		$pool = self::createStub(AsyncPool::class);
		$pool->method("getSize")->willReturn(1);
		$pool->method("getRunningWorkers")->willReturnCallback(static function() use (&$workers) : array{ return $workers; });
		$pool->method("submitTaskToWorker")->willThrowException(new \RuntimeException("submission failed"));
		$recorder = new PulseRecorder($pool);
		$recorder->start();
		foreach(["collect", "stop"] as $operation){
			$workers = [0];
			try{
				if($operation === "collect"){ $recorder->collect(); }else{ $recorder->stop(); }
				self::fail("Submission must fail");
			}catch(\RuntimeException $e){ self::assertSame("submission failed", $e->getMessage()); }
			$workers = [];
			$recorder->stop();
			$recorder->start();
			$report = null;
			$recorder->collect()->onCompletion(function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Report rejected"));
			self::assertNotNull($report);
		}
	}

	public function testClosureMeasurementClosesOnException() : void{
		$session = Pulse::start();
		$zone = Pulse::zone("integration.exception");
		$caught = null;
		try{
			$zone->time(static function() : void{ throw new \RuntimeException("test"); });
		}catch(\RuntimeException $e){ $caught = $e; }
		self::assertSame("test", $caught->getMessage());
		self::assertSame(42, $zone->time(static fn() => 42));
		$session->stop();
		self::assertSame(0, $session->getCapture()["unbalanced_scopes"]);
	}

	public function testReportFilesAreUniqueAndRoundTrip() : void{
		$directory = sys_get_temp_dir() . "/" . uniqid("quark-pulse-test-", true);
		$report = PulseReport::create([]);
		try{
			$first = $report->write($directory);
			$second = $report->write($directory);
			self::assertNotSame($first, $second);
			self::assertStringEndsWith(".qpulse", $first);
			$json = file_get_contents($first);
			self::assertIsString($json);
			self::assertStringStartsWith("\x1f\x8b", $json);
			self::assertSame($report->getData(), PulseReport::decode($json)->getData());
			$this->expectException(\RuntimeException::class);
			$report->write($first . "/invalid");
		}finally{
			$files = glob($directory . "/*");
			if($files !== false){ foreach($files as $file){ unlink($file); } }
			rmdir($directory);
		}
	}

	public function testServerReportWorksBeforeManagersAreInitialized() : void{
		$directory = sys_get_temp_dir() . "/" . uniqid("quark-pulse-startup-", true);
		$server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(["getQuarkVersion", "getDataPath"])->getMock();
		$server->method("getQuarkVersion")->willReturn("test");
		$server->method("getDataPath")->willReturn($directory);
		$recorder = $this->recorder();
		$recorder->start();
		$recorder->stop();
		(new \ReflectionProperty(Server::class, "pulse"))->setValue($server, $recorder);
		$file = null;
		try{
			$server->createPulseReport()->onCompletion(function(string $value) use (&$file) : void{ $file = $value; }, fn() => self::fail("Startup report rejected"));
			self::assertNotNull($file);
			$json = file_get_contents($file);
			self::assertIsString($json);
			$metadata = PulseReport::decode($json)->getData()["metadata"];
			self::assertSame([], $metadata["plugins"]);
			self::assertSame([], $metadata["worlds"]);
		}finally{
			if($file !== null){ unlink($file); }
			rmdir($directory . "/pulse");
			rmdir($directory);
		}
	}
}
