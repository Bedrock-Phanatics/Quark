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
use quark\pulse\internal\PulseRecorder;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\Server;
use quark\thread\ThreadSafeClassLoader;
use quark\TimeTrackingSleeperHandler;
use quark\utils\MainLogger;
use function extension_loaded;
use function file_get_contents;
use function glob;
use function is_dir;
use function microtime;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

final class PulseWorkerTest extends TestCase{
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

	private function drain(AsyncPool $pool) : void{
		$deadline = microtime(true) + 10;
		while($pool->collectTasks()){
			self::assertLessThan($deadline, microtime(true), "Pulse worker control timed out");
			usleep(1000);
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
			}
		}finally{
			$recorder->stop();
			$pool->shutdown();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
		}
	}
}
