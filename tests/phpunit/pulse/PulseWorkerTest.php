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
use quark\thread\ThreadSafeClassLoader;
use quark\utils\MainLogger;
use function extension_loaded;
use function microtime;
use function usleep;

final class PulseWorkerTest extends TestCase{
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
