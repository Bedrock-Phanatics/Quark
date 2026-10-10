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
use quark\pulse\internal\PulseNetworkWatchdog;
use quark\pulse\internal\PulseNetworkWork;
use quark\pulse\internal\PulseRecorder;
use quark\scheduler\AsyncPool;
use quark\thread\ThreadManager;
use quark\thread\ThreadSafeClassLoader;
use quark\utils\MainLogger;
use quark\utils\MainLoggerThread;
use function array_reverse;
use function count;
use function explode;
use function file_get_contents;
use function hrtime;
use function json_decode;
use function ord;
use function str_contains;
use function strlen;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;
use const JSON_THROW_ON_ERROR;

final class PulseNetworkWatchdogTest extends TestCase{
	private function stage(string $record) : string{
		return PulseNetworkWork::STAGES[ord($record[-1])];
	}

	public function testNestedWorkIsBoundedAndRestoresItsParents() : void{
		$file = sys_get_temp_dir() . "/" . uniqid("pulse-work-", true);
		$watchdog = new PulseNetworkWatchdog(new MainLoggerThread($file, null), 1);
		$work = new PulseNetworkWork((int) hrtime(true), $watchdog);
		try{
			$tokens = [];
			for($i = 0; $i < PulseNetworkWork::MAX_DEPTH; ++$i){
				$tokens[] = $work->enter($i + 1, 1000, "in_game", 193, 2, PulseNetworkWork::HANDLE);
			}
			$parent = $watchdog->record;
			self::assertLessThan(100, strlen($parent));
			self::assertSame(0, $work->enter(1, 1000, "in_game", 193, 2, PulseNetworkWork::HANDLE));
			self::assertSame(0, $work->enter(1, 1000, "in_game", 193, 2, PulseNetworkWork::HANDLE));
			$overflow = $watchdog->record;
			self::assertSame("nested_limit", $this->stage($overflow));
			$work->stage(32, PulseNetworkWork::DECODE);
			$work->leave(0);
			self::assertSame($overflow, $watchdog->record);
			$work->leave(0);
			self::assertSame($parent, $watchdog->record);
			foreach(array_reverse($tokens) as $token){ $work->leave($token); }
			self::assertSame("", $watchdog->record);
			$token = $work->enter(1, null, "login", 193, null, PulseNetworkWork::DECODE);
			$work->stop();
			$work->stage($token, PulseNetworkWork::HANDLE);
			$work->leave($token);
			self::assertSame(0, $work->enter(1, null, "login", 193, null, PulseNetworkWork::DECODE));
			self::assertSame("", $watchdog->record);
		}finally{ unlink($file); }
	}

	/** @return list<array<string, mixed>> */
	private function diagnostics(MainLoggerThread $writer, string $file) : array{
		$writer->syncFlushBuffer();
		$contents = file_get_contents($file);
		self::assertIsString($contents);
		$result = [];
		foreach(explode("\n", trim($contents), 64) as $line){
			$parts = explode("[Pulse/NOTICE]: ", $line, 2);
			if(count($parts) !== 2){ continue; }
			$data = json_decode($parts[1], true, 16, JSON_THROW_ON_ERROR);
			self::assertIsArray($data);
			/** @var array<string, mixed> $data */
			$result[] = $data;
		}
		return $result;
	}

	/** @return list<array<string, mixed>> */
	private function waitForReports(PulseNetworkWatchdog $watchdog, MainLoggerThread $writer, string $file, int $count) : array{
		$watchdog->notify();
		$deadline = (int) hrtime(true) + 2000000000;
		do{
			usleep(1000);
			$data = $this->diagnostics($writer, $file);
		}while(count($data) < $count && hrtime(true) < $deadline);
		self::assertCount($count, $data);
		return $data;
	}

	public function testWatchdogReportsWhileMainThreadHoldsTheLoggerLock() : void{
		$file = sys_get_temp_dir() . "/" . uniqid("pulse-stall-", true);
		$logger = new MainLogger($file, false, "Pulse test", new \DateTimeZone("UTC"));
		$writer = $logger->getLogWriterThread();
		self::assertNotNull($writer);
		$threads = ThreadManager::getInstance()->getAll();
		$watchdog = new PulseNetworkWatchdog($writer, 7, 1);
		$watchdog->setClassLoaders([new ThreadSafeClassLoader()]);
		$started = (int) hrtime(true);
		$work = new PulseNetworkWork($started, $watchdog);
		try{
			self::assertTrue($watchdog->start());
			$token = $work->enter(3, -1, "login", 193, 4, PulseNetworkWork::DECODE);
			$logger->synchronized(function() use ($watchdog, $writer, $file, $started) : void{
				usleep(20000);
				$data = $this->waitForReports($watchdog, $writer, $file, 1)[0];
				self::assertSame("network.stall_observed", $data["reason"]);
				self::assertSame("observe", $data["action"]);
				self::assertSame([7, $started, 3, -1, 193, 4, "login", "decode"], [$data["capture"], $data["capture_started_ns"], $data["session"], $data["protocol"], $data["packet"], $data["tick"], $data["phase"], $data["stage"]]);
			});
			$work->stage($token, PulseNetworkWork::HANDLE);
			$watchdog->notify();
			usleep(20000);
			self::assertCount(1, $this->diagnostics($writer, $file));
			$work->leave($token);
			self::assertSame("", $watchdog->record);
		}finally{
			$work->stop();
			$watchdog->quit();
			$logger->shutdownLogWriterThread();
			unlink($file);
		}
		self::assertSame($threads, ThreadManager::getInstance()->getAll());
	}

	public function testReportLimitAndUnknownMetadataRemainBounded() : void{
		$file = sys_get_temp_dir() . "/" . uniqid("pulse-stall-limit-", true);
		$logger = new MainLogger($file, false, "Pulse test", new \DateTimeZone("UTC"));
		$writer = $logger->getLogWriterThread();
		self::assertNotNull($writer);
		$watchdog = new PulseNetworkWatchdog($writer, 1, 1);
		$watchdog->setClassLoaders([new ThreadSafeClassLoader()]);
		$work = new PulseNetworkWork((int) hrtime(true), $watchdog);
		try{
			self::assertTrue($watchdog->start());
			$data = [];
			for($i = 1; $i <= PulseNetworkWatchdog::MAX_REPORTS; ++$i){
				$token = $work->enter(0, null, "unknown", null, null, PulseNetworkWork::DECOMPRESS);
				$data = $this->waitForReports($watchdog, $writer, $file, $i);
				$work->leave($token);
			}
			self::assertCount(32, $data);
			self::assertNull($data[0]["session"]);
			self::assertNull($data[0]["protocol"]);
			self::assertNull($data[0]["packet"]);
			self::assertNull($data[0]["tick"]);
			self::assertTrue($data[31]["report_limit_reached"]);
			$work->enter(1, 1000, "in_game", 193, null, PulseNetworkWork::HANDLE);
			$watchdog->notify();
			usleep(20000);
			self::assertCount(32, $this->diagnostics($writer, $file));
			$contents = file_get_contents($file);
			self::assertIsString($contents);
			self::assertLessThan(20000, strlen($contents));
			self::assertFalse(str_contains($contents, "payload"));
		}finally{
			$work->stop();
			$watchdog->quit();
			$logger->shutdownLogWriterThread();
			unlink($file);
		}
	}

	public function testRecorderRestartsAndCleansUpItsWatchdog() : void{
		Pulse::reset();
		$file = sys_get_temp_dir() . "/" . uniqid("pulse-lifecycle-", true);
		$logger = new MainLogger($file, false, "Pulse test", new \DateTimeZone("UTC"));
		$threads = ThreadManager::getInstance()->getAll();
		$pool = $this->createMock(AsyncPool::class);
		$recorder = new PulseRecorder($pool, $logger->getLogWriterThread(), new ThreadSafeClassLoader());
		try{
			for($i = 0; $i < 10; ++$i){
				$recorder->start();
				$old = Pulse::getNetworkTelemetry()?->work;
				self::assertNotNull($old);
				$token = $old->enter(1, 1000, "login", 193, null, PulseNetworkWork::DECODE);
				$recorder->reset();
				$new = Pulse::getNetworkTelemetry()?->work;
				self::assertNotNull($new);
				self::assertNotSame($old, $new);
				$old->leave($token);
				self::assertSame(0, $old->enter(1, 1000, "login", 193, null, PulseNetworkWork::HANDLE));
				self::assertCount(count($threads) + 1, ThreadManager::getInstance()->getAll());
				$recorder->stop();
				$recorder->stop();
				self::assertSame($threads, ThreadManager::getInstance()->getAll());
			}
			$recorder->start(1);
			Pulse::checkDuration();
			$recorder->checkDuration();
			self::assertFalse($recorder->isRecording());
			self::assertSame($threads, ThreadManager::getInstance()->getAll());
		}finally{
			$recorder->stop();
			Pulse::reset();
			$logger->shutdownLogWriterThread();
			unlink($file);
		}
	}

	public function testFailedWorkerStartReleasesTheWatchdog() : void{
		Pulse::reset();
		$file = sys_get_temp_dir() . "/" . uniqid("pulse-start-failure-", true);
		$logger = new MainLogger($file, false, "Pulse test", new \DateTimeZone("UTC"));
		$threads = ThreadManager::getInstance()->getAll();
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("getRunningWorkers")->willReturn([0]);
		$pool->method("submitTaskToWorker")->willThrowException(new \RuntimeException("worker submission failed"));
		$recorder = new PulseRecorder($pool, $logger->getLogWriterThread(), new ThreadSafeClassLoader());
		try{
			try{ $recorder->start(); self::fail("Submission failure ignored"); }catch(\RuntimeException $e){ self::assertSame("worker submission failed", $e->getMessage()); }
			self::assertFalse($recorder->isRecording());
			self::assertSame(0, $recorder->getPendingOperations());
			self::assertSame($threads, ThreadManager::getInstance()->getAll());
		}finally{
			Pulse::reset();
			$logger->shutdownLogWriterThread();
			unlink($file);
		}
	}
}
