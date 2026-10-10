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
use quark\pulse\internal\PulseCapture;
use quark\pulse\internal\PulseContext;
use quark\pulse\internal\PulseRecorder;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\scheduler\PulseControlTask;
use quark\scheduler\PulseReportWriteTask;
use quark\Server;
use quark\thread\ThreadSafeClassLoader;
use quark\TimeTrackingSleeperHandler;
use quark\utils\MainLogger;
use function array_fill;
use function array_slice;
use function count;
use function extension_loaded;
use function file_get_contents;
use function glob;
use function hrtime;
use function is_dir;
use function microtime;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

final class PulseWorkerTest extends TestCase{
	public function testExportWorkerRejectsInvalidDataAndReleasesItsTransfer() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){ self::markTestSkipped("Requires pmmpthread and igbinary"); }
		Pulse::reset();
		$logger = new MainLogger(null, false, "Pulse validation test", new \DateTimeZone("UTC"));
		$pool = new AsyncPool(1, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0);
		$directory = sys_get_temp_dir() . "/" . uniqid("quark-pulse-validation-", true);
		try{
			$session = Pulse::start();
			$zone = Pulse::zone("export.validation");
			$scope = $zone->start();
			$zone->stop($scope);
			$session->stop();
			$capture = $session->getCapture();
			$node = $capture["nodes"][0] ?? null;
			self::assertNotNull($node);
			$invalid = $capture;
			$invalidNode = $node;
			$invalidNode[3] = -1;
			$invalid["nodes"] = [$invalidNode];
			$transfer = new PulseCapture($capture);
			$decoded = $transfer->decode();
			$decoded["thread"] = "changed";
			self::assertSame($capture, $transfer->decode());
			self::assertGreaterThan(0, $transfer->getRowCount());
			self::assertGreaterThan(0, $transfer->getByteSize());
			/** @var \ArrayObject<int, array{?string, ?string}> $results */
			$results = new \ArrayObject();
			foreach([[[new PulseCapture($invalid)], []], [[$transfer, $transfer], []], [[$transfer], ["platform" => "\xff"]]] as [$captures, $metadata]){
				$task = new PulseReportWriteTask($captures, $metadata, $directory, static function(?string $file, ?string $error) use ($results) : void{ $results[] = [$file, $error]; });
				$pool->submitTask($task);
				$this->drain($pool);
				self::assertNull((new \ReflectionProperty(PulseReportWriteTask::class, "captures"))->getValue($task));
				self::assertSame("", (new \ReflectionProperty(PulseReportWriteTask::class, "metadata"))->getValue($task));
			}
			self::assertCount(3, $results);
			foreach($results as [$file, $error]){ self::assertNull($file); self::assertIsString($error); }
			self::assertFalse(is_dir($directory));
			$dense = $capture;
			$dense["nodes"] = array_fill(0, 16384, $node);
			foreach([[array_fill(0, 129, $transfer), []], [array_fill(0, 9, new PulseCapture($dense)), []], [[$transfer], ["platform" => str_repeat("x", PulseCapture::MAX_TRANSFER_BYTES + 1)]]] as [$captures, $metadata]){
				try{
					new PulseReportWriteTask($captures, $metadata, $directory, static function() : void{ self::fail("Oversized transfer submitted"); });
					self::fail("Oversized transfer accepted");
				}catch(\LengthException){}
			}
			$task = new PulseReportWriteTask([$transfer], [], $directory, static function(?string $file, ?string $error) use ($results) : void{ $results[] = [$file, $error]; });
			$pool->submitTask($task);
			$this->drain($pool);
			self::assertCount(4, $results);
			$success = $results[3];
			self::assertNotNull($success);
			self::assertIsString($success[0]);
			self::assertNull($success[1]);
			$contents = file_get_contents($success[0]);
			self::assertIsString($contents);
			self::assertSame($capture, PulseReport::decode($contents)->getData()["threads"][0]);
			self::assertNull((new \ReflectionProperty(PulseReportWriteTask::class, "captures"))->getValue($task));
			self::assertSame("", (new \ReflectionProperty(PulseReportWriteTask::class, "metadata"))->getValue($task));
		}finally{
			$pool->shutdown();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
			$files = glob($directory . "/*");
			if($files !== false){ foreach($files as $file){ unlink($file); } }
			if(is_dir($directory)){ rmdir($directory); }
		}
	}

	public function testDedicatedExportWorkerBoundsRequestsAndRecoversFromWriteFailure() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){ self::markTestSkipped("Requires pmmpthread and igbinary"); }
		Pulse::reset();
		$logger = new MainLogger(null, false, "Pulse export test", new \DateTimeZone("UTC"));
		$directory = sys_get_temp_dir() . "/" . uniqid("quark-pulse-export-", true);
		$path = $directory;
		$pool = new AsyncPool(2, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0);
		$recorder = new PulseRecorder($pool);
		$server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(["getQuarkVersion", "getDataPath"])->getMock();
		$server->method("getQuarkVersion")->willReturn("test");
		$server->method("getDataPath")->willReturnCallback(static function() use (&$path) : string{ return $path; });
		foreach(["pulse" => $recorder, "asyncPool" => $pool, "logger" => $logger, "autoloader" => new ThreadSafeClassLoader(), "tickSleeper" => new TimeTrackingSleeperHandler(Pulse::zone("export.notifier"))] as $property => $value){
			(new \ReflectionProperty(Server::class, $property))->setValue($server, $value);
		}
		$exportPool = null;
		try{
			for($worker = 0; $worker < 2; ++$worker){
				$pool->submitTaskToWorker(new class extends AsyncTask{
					public function onRun() : void{}
				}, $worker);
			}
			$this->drain($pool);
			$recorder->start();
			$zone = Pulse::zone("export.test");
			$scope = $zone->start();
			$zone->stop($scope);
			$recorder->stop();
			/** @var \ArrayObject<int, string> $completedFiles */
			$completedFiles = new \ArrayObject();
			$promise = $server->createPulseReport();
			$promise->onCompletion(static function(string $value) use ($completedFiles) : void{ $completedFiles[] = $value; }, fn() => self::fail("Export rejected"));
			self::assertCount(0, $completedFiles);
			self::assertFalse($promise->isResolved());
			try{ $server->createPulseReport(); self::fail("Overlapping export accepted"); }catch(\LogicException){}
			$this->drain($pool);
			$candidatePool = (new \ReflectionProperty(Server::class, "pulseReportPool"))->getValue($server);
			self::assertInstanceOf(AsyncPool::class, $candidatePool);
			$exportPool = $candidatePool;
			self::assertSame(1, $exportPool->getSize());
			$this->drain($exportPool);
			self::assertCount(1, $completedFiles);
			$file = $completedFiles[0];
			self::assertIsString($file);
			$contents = file_get_contents($file);
			self::assertIsString($contents);
			self::assertStringStartsWith("\x1f\x8b", $contents);
			$session = $recorder->getSession();
			self::assertNotNull($session);
			$threads = PulseReport::decode($contents)->getData()["threads"];
			self::assertCount(3, $threads);
			self::assertSame($session->getCapture(), $threads[0]);
			$path = $file;
			$failures = 0;
			$server->createPulseReport()->onCompletion(fn() => self::fail("Write failure resolved"), static function() use (&$failures) : void{ ++$failures; });
			$this->drain($pool);
			$this->drain($exportPool);
			self::assertSame(1, $failures);
			$path = $directory;
			$failedPool = self::createStub(AsyncPool::class);
			$failedPool->method("submitTask")->willThrowException(new \RuntimeException("submission failed"));
			(new \ReflectionProperty(Server::class, "pulseReportPool"))->setValue($server, $failedPool);
			$server->createPulseReport()->onCompletion(fn() => self::fail("Submission failure resolved"), static function() use (&$failures) : void{ ++$failures; });
			$this->drain($pool);
			self::assertSame(2, $failures);
			self::assertNull((new \ReflectionProperty(Server::class, "pulseReport"))->getValue($server));
			(new \ReflectionProperty(Server::class, "pulseReportPool"))->setValue($server, $exportPool);
			$completed = 0;
			$server->createPulseReport()->onCompletion(function() use ($server, &$completed, $completedFiles) : void{
				++$completed;
				$server->createPulseReport()->onCompletion(static function(string $file) use (&$completed, $completedFiles) : void{ ++$completed; $completedFiles[] = $file; }, fn() => self::fail("Chained export rejected"));
			}, fn() => self::fail("Retry rejected"));
			(new \ReflectionMethod(Server::class, "drainPulseReports"))->invoke($server);
			self::assertCount(2, $pool->getRunningWorkers());
			$exportPool->shutdown();
			self::assertSame(2, $completed);
			$finalFile = $completedFiles[1];
			self::assertIsString($finalFile);
			$finalContents = file_get_contents($finalFile);
			self::assertIsString($finalContents);
			self::assertCount(3, PulseReport::decode($finalContents)->getData()["threads"]);
			self::assertSame([], $exportPool->getRunningWorkers());
			self::assertNull((new \ReflectionProperty(Server::class, "pulseReport"))->getValue($server));
		}finally{
			$exportPool?->shutdown();
			$pool->shutdown();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
			$files = glob($directory . "/pulse/*");
			if($files !== false){ foreach($files as $file){ unlink($file); } }
			if(is_dir($directory . "/pulse")){ rmdir($directory . "/pulse"); }
			if(is_dir($directory)){ rmdir($directory); }
		}
	}

	public function testShutdownReportDrainTimesOutStalledWorkersAndRecovers() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){ self::markTestSkipped("Requires pmmpthread and igbinary"); }
		Pulse::reset();
		$logger = new MainLogger(null, false, "Pulse timeout test", new \DateTimeZone("UTC"));
		$directory = sys_get_temp_dir() . "/" . uniqid("quark-pulse-timeout-", true);
		$pool = new AsyncPool(2, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0);
		$recorder = new PulseRecorder($pool);
		/** @var \pmmp\thread\ThreadSafeArray<string, bool> $gate */
		$gate = new \pmmp\thread\ThreadSafeArray();
		$gate["release"] = false;
		$server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(["getQuarkVersion", "getDataPath"])->getMock();
		$server->method("getQuarkVersion")->willReturn("test");
		$server->method("getDataPath")->willReturn($directory);
		foreach(["pulse" => $recorder, "asyncPool" => $pool, "logger" => $logger, "autoloader" => new ThreadSafeClassLoader(), "tickSleeper" => new TimeTrackingSleeperHandler(Pulse::zone("timeout.notifier"))] as $property => $value){
			(new \ReflectionProperty(Server::class, $property))->setValue($server, $value);
		}
		try{
			$recorder->start();
			for($worker = 0; $worker < 2; ++$worker){
				$pool->submitTaskToWorker(new class extends AsyncTask{ public function onRun() : void{} }, $worker);
			}
			$this->drain($pool);
			$pool->submitTaskToWorker(new class($gate) extends AsyncTask{
				/** @param \pmmp\thread\ThreadSafeArray<string, bool> $gate */
				public function __construct(private \pmmp\thread\ThreadSafeArray $gate){}
				public function onRun() : void{
					$this->gate["entered"] = true;
					$deadline = microtime(true) + 10;
					while($this->gate["release"] !== true){
						if(microtime(true) >= $deadline){ throw new \RuntimeException("Test worker release timed out"); }
						usleep(1000);
					}
				}
			}, 0);
			$deadline = microtime(true) + 2;
			while(($gate["entered"] ?? false) !== true){
				self::assertLessThan($deadline, microtime(true), "Test worker failed to enter the stall");
				usleep(1000);
			}
			$recorder->stop();
			$failures = 0;
			$server->createPulseReport()->onCompletion(fn() => self::fail("Stalled report resolved"), static function() use (&$failures) : void{ self::assertSame(0, $failures); ++$failures; });
			(new \ReflectionProperty(PulseRecorder::class, "collectionDeadline"))->setValue($recorder, (int) hrtime(true) - 1);
			(new \ReflectionMethod(Server::class, "drainPulseReports"))->invoke($server);
			self::assertSame(1, $failures);
			self::assertNull((new \ReflectionProperty(Server::class, "pulseReport"))->getValue($server));
			self::assertNull((new \ReflectionProperty(Server::class, "pulseReportPool"))->getValue($server));
			self::assertSame([], (new \ReflectionProperty(PulseRecorder::class, "captures"))->getValue($recorder));
			self::assertSame(0, (new \ReflectionProperty(PulseRecorder::class, "collectionBytes"))->getValue($recorder));
			$deadline = microtime(true) + 2;
			while($pool->collectTasksFromWorker(1)){
				self::assertLessThan($deadline, microtime(true), "Unblocked worker failed to finish");
				usleep(1000);
			}
			self::assertSame(2, $recorder->getPendingOperations());
			self::assertSame([0 => 3, 1 => 0], $pool->getTaskQueueSizes());
			for($attempt = 0; $attempt < 100; ++$attempt){
				foreach(["start", "reset", "report"] as $operation){
					try{
						if($operation === "start"){ $recorder->start(); }elseif($operation === "reset"){ $recorder->reset(); }else{ $server->createPulseReport(); }
						self::fail("Pending worker must prevent more queued operations");
					}catch(\LogicException){}
				}
				$recorder->stop();
			}
			self::assertSame([0 => 3, 1 => 0], $pool->getTaskQueueSizes());
			self::assertSame(2, $recorder->getPendingOperations());
			$gate["release"] = true;
			$this->drain($pool);
			self::assertSame(0, $recorder->getPendingOperations());
			self::assertSame([0 => 0, 1 => 0], $pool->getTaskQueueSizes());
			$file = null;
			$server->createPulseReport()->onCompletion(static function(string $value) use (&$file) : void{ $file = $value; }, fn() => self::fail("Recovered report rejected"));
			(new \ReflectionMethod(Server::class, "drainPulseReports"))->invoke($server);
			self::assertIsString($file);
			$contents = file_get_contents($file);
			self::assertIsString($contents);
			$threads = PulseReport::decode($contents)->getData()["threads"];
			self::assertCount(3, $threads);
			foreach($threads as $thread){ self::assertFalse($thread["recording"]); }
			self::assertSame(0, $recorder->getPendingOperations());
		}finally{
			$gate["release"] = true;
			$exportPool = (new \ReflectionProperty(Server::class, "pulseReportPool"))->getValue($server);
			if($exportPool instanceof AsyncPool){ $exportPool->shutdown(); }
			$pool->shutdown();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
			$files = glob($directory . "/pulse/*");
			if($files !== false){ foreach($files as $file){ unlink($file); } }
			if(is_dir($directory . "/pulse")){ rmdir($directory . "/pulse"); }
			if(is_dir($directory)){ rmdir($directory); }
		}
		self::assertSame([], $pool->getRunningWorkers());
		self::assertSame([], (new \ReflectionProperty(AsyncTask::class, "threadLocalStorage"))->getValue());
	}

	private function drain(AsyncPool $pool) : void{
		$deadline = microtime(true) + 10;
		while($pool->collectTasks()){
			self::assertLessThan($deadline, microtime(true), "Pulse worker control timed out");
			usleep(1000);
		}
	}

	public function testFailedStartsStopWorkersAndAllowFreshCaptures() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){ self::markTestSkipped("Requires pmmpthread and igbinary"); }
		foreach(["start", "reset", "join"] as $operation){
			Pulse::reset();
			$logger = new MainLogger(null, false, "Pulse start retry test", new \DateTimeZone("UTC"));
			$pool = new class(3, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0) extends AsyncPool{
				public bool $rejectStart = false;
				public function submitTaskToWorker(AsyncTask $task, int $worker) : void{
					$start = $task instanceof PulseControlTask && (new \ReflectionProperty(PulseControlTask::class, "operation"))->getValue($task) === PulseControlTask::START;
					if($start && $this->rejectStart && $worker === 1){ throw new \RuntimeException("start rejected"); }
					parent::submitTaskToWorker($task, $worker);
				}
			};
			$recorder = new PulseRecorder($pool);
			try{
				for($worker = 0; $worker < ($operation === "join" ? 1 : 3); ++$worker){
					$pool->submitTaskToWorker(new class extends AsyncTask{ public function onRun() : void{} }, $worker);
				}
				$this->drain($pool);
				if($operation !== "start"){
					$recorder->start();
					$this->drain($pool);
				}
				$pool->rejectStart = true;
				$caught = null;
				try{
					if($operation === "join"){
						$pool->submitTaskToWorker(new class extends AsyncTask{ public function onRun() : void{} }, 1);
					}elseif($operation === "start"){ $recorder->start(); }else{ $recorder->reset(); }
				}catch(\RuntimeException $error){ $caught = $error; }
				self::assertNotNull($caught);
				self::assertSame("start rejected", $caught->getMessage());
				self::assertFalse($recorder->isRecording());
				self::assertFalse(Pulse::isRecording());
				$recorder->checkDuration();
				$this->drain($pool);
				self::assertSame(0, $recorder->getPendingOperations());
				$pool->rejectStart = false;
				for($worker = 0; $worker < 3; ++$worker){
					$pool->submitTaskToWorker(new class extends AsyncTask{
						public function onRun() : void{ $this->setResult(Pulse::isRecording()); }
						public function onCompletion() : void{ PulseWorkerTest::assertFalse($this->getResult()); }
					}, $worker);
				}
				$this->drain($pool);
				$recorder->start();
				for($worker = 0; $worker < 3; ++$worker){
					$pool->submitTaskToWorker(new class extends AsyncTask{
						public function onRun() : void{
							$zone = Pulse::zone("recovered.worker");
							$scope = $zone->start();
							$zone->stop($scope);
						}
					}, $worker);
				}
				$recorder->stop();
				$report = null;
				$recorder->collect()->onCompletion(static function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Report rejected"));
				$this->drain($pool);
				self::assertNotNull($report);
				self::assertCount(4, $report->getData()["threads"]);
				foreach($report->getData()["threads"] as $thread){ self::assertFalse($thread["recording"]); }
				foreach(array_slice($report->getData()["threads"], 1) as $thread){ self::assertSame(1, $thread["nodes"][0][3]); }
				self::assertSame(0, $recorder->getPendingOperations());
			}finally{
				$pool->rejectStart = false;
				$recorder->stop();
				$pool->shutdown();
				Pulse::reset();
				$logger->shutdownLogWriterThread();
			}
		}
	}

	public function testPartialStopFailureRecoversBeforeExportingRealWorkers() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){ self::markTestSkipped("Requires pmmpthread and igbinary"); }
		$logger = new MainLogger(null, false, "Pulse stop retry test", new \DateTimeZone("UTC"));
		$pool = new class(3, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0) extends AsyncPool{
			public bool $failStop = false;
			/** @var array<int, int> */
			public array $stops = [];

			public function submitTaskToWorker(AsyncTask $task, int $worker) : void{
				$stop = $task instanceof PulseControlTask && (new \ReflectionProperty(PulseControlTask::class, "operation"))->getValue($task) === PulseControlTask::STOP;
				if($stop && $this->failStop && $worker === 1){ throw new \RuntimeException("submission failed"); }
				parent::submitTaskToWorker($task, $worker);
				if($stop){ $this->stops[$worker] = ($this->stops[$worker] ?? 0) + 1; }
			}
		};
		$recorder = new PulseRecorder($pool);
		try{
			$recorder->start();
			for($worker = 0; $worker < 3; ++$worker){
				$pool->submitTaskToWorker(new class extends AsyncTask{
					public function onRun() : void{
						$zone = Pulse::zone("retry.worker");
						$scope = $zone->start();
						$zone->stop($scope);
					}
				}, $worker);
			}
			$this->drain($pool);
			$pool->failStop = true;
			for($attempt = 0; $attempt < 2; ++$attempt){
				$caught = null;
				try{ $recorder->stop(); }catch(\RuntimeException $error){ $caught = $error; }
				self::assertNotNull($caught);
			}
			$this->drain($pool);
			self::assertSame([0 => 1], $pool->stops);
			for($worker = 0; $worker < 3; ++$worker){
				$pool->submitTaskToWorker(new class($worker) extends AsyncTask{
					public function __construct(private int $worker){}
					public function onRun() : void{ $this->setResult(Pulse::isRecording()); }
					public function onCompletion() : void{ PulseWorkerTest::assertSame($this->worker !== 0, $this->getResult()); }
				}, $worker);
			}
			$this->drain($pool);
			$pool->failStop = false;
			$report = null;
			$recorder->collect()->onCompletion(static function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Report rejected"));
			$this->drain($pool);
			self::assertNotNull($report);
			self::assertCount(4, $report->getData()["threads"]);
			foreach($report->getData()["threads"] as $thread){ self::assertFalse($thread["recording"]); }
			self::assertSame($report->getData(), PulseReport::decode($report->encode(true))->getData());
			self::assertSame([0 => 1, 1 => 1, 2 => 1], $pool->stops);
			self::assertSame(0, $recorder->getPendingOperations());
			$recorder->start();
			$recorder->stop();
			$this->drain($pool);
			self::assertSame([0 => 2, 1 => 2, 2 => 2], $pool->stops);
		}finally{
			$pool->failStop = false;
			$recorder->stop();
			$pool->shutdown();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
		}
	}

	public function testWorkersRemainIndependentAcrossStopReportAndReset() : void{
		if(!extension_loaded("pmmpthread") || !extension_loaded("igbinary")){
			self::markTestSkipped("Requires pmmpthread and igbinary");
		}
		$logger = new MainLogger(null, false, "Pulse test", new \DateTimeZone("UTC"));
		$pool = new AsyncPool(2, 256, new ThreadSafeClassLoader(), $logger, new SleeperHandler(), 0);
		$recorder = new PulseRecorder($pool);
		try{
			for($round = 0; $round < 3; ++$round){
				$recorder->start(durationNs: $round === 2 ? 1 : 0);
				$this->drain($pool);
				if($round === 1){ $recorder->reset(); }
				for($worker = 0; $worker < 2; ++$worker){
					$pool->submitTaskToWorker(new class extends AsyncTask{
						public function onRun() : void{
							$zone = Pulse::zone("worker.test");
							for($i = 0; $i < 10; ++$i){ $scope = $zone->start(); $zone->stop($scope); }
						}
					}, $worker);
				}
				$this->drain($pool);
				if($round === 1){
					$live = null;
					$recorder->collect()->onCompletion(function(PulseReport $value) use (&$live) : void{ $live = $value; }, fn() => self::fail("Live report rejected"));
					try{ $recorder->collect(); self::fail("Parallel collection accepted"); }catch(\LogicException){}
					$this->drain($pool);
					self::assertNotNull($live);
					foreach($live->getData()["threads"] as $thread){ self::assertTrue($thread["recording"]); }
				}
				Pulse::checkDuration();
				$recorder->checkDuration();
				$recorder->stop();
				$report = null;
				$recorder->collect()->onCompletion(function(PulseReport $value) use (&$report) : void{ $report = $value; }, fn() => self::fail("Worker report rejected"));
				$this->drain($pool);
				self::assertNotNull($report);
				$data = PulseReport::decode($report->encode())->getData();
				self::assertCount($round === 2 ? 1 : 3, $data["threads"]);
				foreach($data["threads"] as $thread){
					self::assertFalse($thread["recording"]);
					self::assertSame(0, $thread["unbalanced_scopes"]);
					if($thread["thread"] === "main"){ continue; }
					$calls = 0;
					foreach($thread["nodes"] as $node){ if($thread["zones"][$node[1]] === "worker.test"){ $calls += $node[3]; } }
					self::assertSame(10, $calls);
				}
				$recorder->reset();
				$this->drain($pool);
				self::assertSame(0, $recorder->getPendingOperations());
				for($worker = 0; $worker < 2; ++$worker){
					$pool->submitTaskToWorker(new class extends AsyncTask{
						public function onRun() : void{
							/** @var PulseContext $context */
							$context = (new \ReflectionProperty(Pulse::class, "context"))->getValue();
							$capture = $context->capture();
							$this->setResult([Pulse::getSession() === null, count($capture["nodes"]), count($capture["ticks"]), count($capture["spikes"])]);
						}

						public function onCompletion() : void{
							PulseWorkerTest::assertSame([true, 0, 0, 0], $this->getResult());
						}
					}, $worker);
				}
				$this->drain($pool);
			}
		}finally{
			$recorder->stop();
			$pool->shutdown();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
		}
	}
}
