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
use quark\pulse\internal\PulseCapture;
use quark\pulse\internal\PulseRecorder;
use quark\pulse\internal\PulseZones;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\scheduler\PulseControlTask;
use quark\Server;
use function array_fill;
use function count;
use function extension_loaded;
use function file_get_contents;
use function glob;
use function hrtime;
use function rmdir;
use function str_pad;
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

	public function testOversizedCollectionsReleaseTransfersAndIgnoreLateWorkers() : void{
		$session = Pulse::start();
		$zone = Pulse::zone("collection.bounds");
		$scope = $zone->start();
		$zone->stop($scope);
		$session->stop();
		$base = $session->getCapture();
		$node = $base["nodes"][0] ?? null;
		self::assertNotNull($node);
		$denseRows = $base;
		$denseRows["nodes"] = array_fill(0, 16384, $node);
		$denseBytes = $base;
		$denseBytes["zones"] = [];
		for($i = 0; $i < 4096; ++$i){ $denseBytes["zones"][] = str_pad((string) $i, 256, "x"); }
		foreach([[9, $denseRows], [34, $denseBytes]] as [$workerCount, $capture]){
			$workers = [];
			/** @var \ArrayObject<int, AsyncTask> $tasks */
			$tasks = new \ArrayObject();
			$pool = self::createStub(AsyncPool::class);
			$pool->method("getSize")->willReturn($workerCount);
			$pool->method("getRunningWorkers")->willReturnCallback(static function() use (&$workers) : array{ return $workers; });
			$pool->method("submitTaskToWorker")->willReturnCallback(static function(AsyncTask $task) use ($tasks) : void{ $tasks[] = $task; });
			$recorder = new PulseRecorder($pool);
			$recorder->start();
			for($i = 0; $i < $workerCount; ++$i){ $workers[] = $i; }
			$failures = 0;
			$recorder->collectTransfers()->onCompletion(fn() => self::fail("Oversized collection resolved"), static function() use (&$failures) : void{ ++$failures; });
			$transfer = new PulseCapture($capture);
			$late = 0;
			foreach($tasks as $task){
				if($failures !== 0){ ++$late; }
				$task->setResult($transfer);
				$task->onCompletion();
				if($failures !== 0){
					self::assertSame([], (new \ReflectionProperty(PulseRecorder::class, "captures"))->getValue($recorder));
					self::assertSame(0, (new \ReflectionProperty(PulseRecorder::class, "collectionBytes"))->getValue($recorder));
					self::assertSame(0, (new \ReflectionProperty(PulseRecorder::class, "collectionRows"))->getValue($recorder));
				}
			}
			self::assertSame(1, $failures);
			self::assertGreaterThan(0, $late);
			self::assertSame(0, $recorder->getPendingOperations());
			$workers = [];
			$recorder->stop();
		}
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

	public function testStoppedResetWaitsForWorkersBeforeRestarting() : void{
		$workers = [];
		$task = null;
		$pool = self::createStub(AsyncPool::class);
		$pool->method("getSize")->willReturn(1);
		$pool->method("getRunningWorkers")->willReturnCallback(static function() use (&$workers) : array{ return $workers; });
		$pool->method("submitTaskToWorker")->willReturnCallback(static function(AsyncTask $value) use (&$task) : void{ $task = $value; });
		$recorder = new PulseRecorder($pool);
		$recorder->start();
		$recorder->stop();
		$workers = [0];
		$recorder->reset();
		self::assertNull($recorder->getSession());
		self::assertSame(1, $recorder->getPendingOperations());
		$server = self::createStub(Server::class);
		$server->method("getPulse")->willReturn($recorder);
		$sender = self::createMock(CommandSender::class);
		$sender->method("getServer")->willReturn($server);
		$sender->expects(self::once())->method("sendMessage")->with("Pulse is waiting for 1 worker operations");
		(new PulseCommand())->execute($sender, "pulse", ["status"]);
		try{ $recorder->start(); self::fail("Restart must wait for worker reset"); }catch(\LogicException){}
		self::assertInstanceOf(PulseControlTask::class, $task);
		$task->onCompletion();
		self::assertSame(0, $recorder->getPendingOperations());
		$workers = [];
		$recorder->start();
		self::assertTrue($recorder->isRecording());
		$recorder->stop();
	}

	public function testValidatedCollectionRejectsMetadataAndAllowsRetry() : void{
		$recorder = $this->recorder();
		$recorder->start();
		$recorder->stop();
		$failures = 0;
		$recorder->collect(["worlds" => [123]])->onCompletion(fn() => self::fail("Invalid metadata accepted"), static function() use (&$failures) : void{ ++$failures; });
		self::assertSame(1, $failures);
		$report = null;
		$recorder->collect(["worlds" => ["world"]])->onCompletion(static function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Retry rejected"));
		self::assertNotNull($report);
		self::assertSame(["world"], $report->getData()["metadata"]["worlds"]);
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

	public function testFailedStartsStopTheMainSessionAndAllowRecovery() : void{
		foreach(["start", "reset"] as $operation){
			foreach([0, 1, 2] as $failedWorker){
				$state = new class{ public bool $rejectStart = false; };
				/** @var \ArrayObject<int, AsyncTask> $tasks */
				$tasks = new \ArrayObject();
				$pool = self::createStub(AsyncPool::class);
				$pool->method("getSize")->willReturn(3);
				$pool->method("getRunningWorkers")->willReturn([0, 1, 2]);
				$pool->method("submitTaskToWorker")->willReturnCallback(static function(AsyncTask $task, int $worker) use ($state, $failedWorker, $tasks) : void{
					$start = (new \ReflectionProperty(PulseControlTask::class, "operation"))->getValue($task) === PulseControlTask::START;
					if($start && $state->rejectStart && $worker === $failedWorker){ throw new \RuntimeException("start rejected"); }
					$tasks[] = $task;
				});
				$recorder = new PulseRecorder($pool);
				if($operation === "reset"){
					$recorder->start();
					foreach($tasks as $task){ $task->onCompletion(); }
					$tasks->exchangeArray([]);
				}
				$state->rejectStart = true;
				$caught = null;
				try{
					if($operation === "start"){ $recorder->start(); }else{ $recorder->reset(); }
				}catch(\RuntimeException $error){ $caught = $error; }
				self::assertNotNull($caught);
				self::assertSame("start rejected", $caught->getMessage());
				self::assertFalse($recorder->isRecording());
				self::assertFalse(Pulse::isRecording());
				self::assertSame($failedWorker + ($operation === "reset" ? 3 : 0), $recorder->getPendingOperations());
				$recorder->checkDuration();
				self::assertSame($failedWorker + ($operation === "reset" ? 6 : 3), $recorder->getPendingOperations());
				foreach($tasks as $task){ $task->onCompletion(); }
				$tasks->exchangeArray([]);
				self::assertSame(0, $recorder->getPendingOperations());
				$state->rejectStart = false;
				$recorder->start();
				self::assertTrue($recorder->isRecording());
				$recorder->stop();
				foreach($tasks as $task){ $task->onCompletion(); }
				self::assertSame(0, $recorder->getPendingOperations());
			}
		}
	}

	public function testFailedStopRetriesDoNotDuplicateWorkerCommands() : void{
		foreach([0, 1, 2] as $failedWorker){
			$workers = [];
			$state = new class{
				public bool $fail = true;
				/** @var list<int> */
				public array $submitted = [];
				/** @var list<AsyncTask> */
				public array $tasks = [];
				public function complete() : void{
					foreach($this->tasks as $task){ $task->onCompletion(); }
					$this->tasks = [];
				}
			};
			$pool = self::createStub(AsyncPool::class);
			$pool->method("getSize")->willReturn(3);
			$pool->method("getRunningWorkers")->willReturnCallback(static function() use (&$workers) : array{ return $workers; });
			$pool->method("submitTaskToWorker")->willReturnCallback(static function(AsyncTask $task, int $worker) use ($state, $failedWorker) : void{
				if($state->fail && $worker === $failedWorker){ throw new \RuntimeException("submission failed"); }
				$state->submitted[] = $worker;
				$state->tasks[] = $task;
			});
			$recorder = new PulseRecorder($pool);
			$recorder->start();
			$workers = [0, 1, 2];
			for($attempt = 0; $attempt < 2; ++$attempt){
				$caught = null;
				try{ $recorder->stop(); }catch(\RuntimeException $error){ $caught = $error; }
				self::assertNotNull($caught);
				self::assertSame($failedWorker, $recorder->getPendingOperations());
				self::assertCount($failedWorker, $state->tasks);
			}
			$state->fail = false;
			$recorder->checkDuration();
			$recorder->stop();
			self::assertSame([0, 1, 2], $state->submitted);
			self::assertSame(3, $recorder->getPendingOperations());
			$state->complete();
			self::assertSame(0, $recorder->getPendingOperations());
			$workers = [];
			$recorder->start();
			$workers = [0, 1, 2];
			$recorder->stop();
			self::assertSame([0, 1, 2, 0, 1, 2], $state->submitted);
			$state->complete();
		}
	}

	public function testReportQueuesMissingStopsBeforeCollectingWorkers() : void{
		$workers = [];
		$state = new class{ public bool $fail = true; };
		$submitted = [];
		$tasks = [];
		$pool = self::createStub(AsyncPool::class);
		$pool->method("getSize")->willReturn(3);
		$pool->method("getRunningWorkers")->willReturnCallback(static function() use (&$workers) : array{ return $workers; });
		$pool->method("submitTaskToWorker")->willReturnCallback(static function(AsyncTask $task, int $worker) use ($state, &$submitted, &$tasks) : void{
			if($state->fail && $worker === 1){ throw new \RuntimeException("submission failed"); }
			$operation = (new \ReflectionProperty(PulseControlTask::class, "operation"))->getValue($task);
			$submitted[] = [$worker, $operation];
			$tasks[] = $task;
		});
		$recorder = new PulseRecorder($pool);
		$recorder->start();
		$workers = [0, 1, 2];
		try{ $recorder->stop(); self::fail("Stop submission must fail"); }catch(\RuntimeException){}
		$state->fail = false;
		$captures = null;
		$recorder->collectCaptures()->onCompletion(static function(array $value) use (&$captures) : void{ $captures = $value; }, fn() => self::fail("Report rejected"));
		self::assertSame([
			[0, PulseControlTask::STOP], [1, PulseControlTask::STOP], [2, PulseControlTask::STOP],
			[0, PulseControlTask::COLLECT], [1, PulseControlTask::COLLECT], [2, PulseControlTask::COLLECT]
		], $submitted);
		foreach($tasks as $task){ $task->onCompletion(); }
		self::assertNotNull($captures);
		self::assertFalse($captures[0]["recording"]);
		self::assertSame(0, $recorder->getPendingOperations());
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
