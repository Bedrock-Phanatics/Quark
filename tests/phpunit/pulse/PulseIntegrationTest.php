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
use quark\command\defaults\PulseCommand;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\permission\PermissionManager;
use quark\pulse\internal\PulseRecorder;
use quark\pulse\internal\PulseZones;
use quark\scheduler\AsyncPool;
use function file_get_contents;
use function glob;
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

	public function testCommandOptionsValidateUnitsLimitsAndDuplicates() : void{
		self::assertSame([0, 0, 32], PulseCommand::parseOptions([]));
		self::assertSame([60000000000, 50000000, 16], PulseCommand::parseOptions(["--duration", "60s", "--spikes", "50ms", "--max-spikes", "16"]));
		self::assertSame([1500000000, 0, 32], PulseCommand::parseOptions(["--duration", "1.5s"]));
		self::assertSame([86400000000000, 60000000000, 128], PulseCommand::parseOptions(["--duration", "1440m", "--spikes", "1m", "--max-spikes", "128"]));
		foreach([
			["--duration"], ["--other", "1s"], ["--duration", "0s"], ["--duration", "-1s"],
			["--duration", "86401s"], ["--duration", "1e9s"], ["--duration", "1s\n"],
			["--spikes", "61s"], ["--spikes", "nanms"], ["--max-spikes", "0"],
			["--max-spikes", "129"], ["--max-spikes", "1.5"], ["--duration", "1s", "--duration", "2s"]
		] as $args){
			try{
				PulseCommand::parseOptions($args);
				self::fail("Invalid Pulse options accepted");
			}catch(InvalidCommandSyntaxException){
				self::assertFalse(Pulse::isRecording());
			}
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
			self::assertSame($report->getData(), PulseReport::decode($json)->getData());
			$this->expectException(\RuntimeException::class);
			$report->write($first . "/invalid");
		}finally{
			$files = glob($directory . "/*");
			if($files !== false){ foreach($files as $file){ unlink($file); } }
			rmdir($directory);
		}
	}
}
