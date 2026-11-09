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

namespace quark\command\defaults;

use PHPUnit\Framework\MockObject\Stub;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\world\World;
use quark\world\WorldManager;

final class TimeCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, int> */
	private array $times = [];
	/** @phpstan-var array<string, bool> */
	private array $stopped = [];
	/** @phpstan-var list<string> */
	private array $changes = [];

	protected function setUp() : void{
		parent::setUp();
		$overworld = $this->world("overworld", 100);
		$nether = $this->world("nether", 250);
		$manager = self::createStub(WorldManager::class);
		$manager->method("getWorlds")->willReturn([$overworld, $nether]);
		$manager->method("getDefaultWorld")->willReturn($overworld);
		$this->server->method("getWorldManager")->willReturn($manager);
	}

	private function world(string $name, int $time) : World&Stub{
		$this->times[$name] = $time;
		$this->stopped[$name] = false;
		$world = self::createStub(World::class);
		$world->method("getTime")->willReturnCallback(fn() : int => $this->times[$name]);
		$world->method("getTimeOfDay")->willReturnCallback(fn() : int => $this->times[$name] % World::TIME_FULL);
		$world->method("getFolderName")->willReturn($name);
		$world->method("setTime")->willReturnCallback(function(int $time) use ($name) : void{
			$this->times[$name] = $time;
			$this->changes[] = "$name time $time";
		});
		$world->method("stopTime")->willReturnCallback(function() use ($name) : void{
			$this->stopped[$name] = true;
			$this->changes[] = "$name stop";
		});
		$world->method("startTime")->willReturnCallback(function() use ($name) : void{
			$this->stopped[$name] = false;
			$this->changes[] = "$name start";
		});
		return $world;
	}

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$nether = self::createStub(World::class);
		$nether->method("getTime")->willReturn(48000 + 6000);
		$nether->method("getTimeOfDay")->willReturn(6000);
		$player->method("getWorld")->willReturn($nether);
		return $player;
	}

	public function testSetsTheTimeOfEveryWorld() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Set the time to 5000"], $this->dispatch("time set 5000", $steve));
		self::assertSame(["overworld time 5000", "nether time 5000"], $this->changes);
		$this->dispatch("time set 0", $steve);
		$this->dispatch("time set 2147483647", $steve);
		self::assertSame(2147483647, $this->times["overworld"]);
	}

	public function testSetsNamedTimes() : void{
		$steve = $this->spawn("Steve");
		foreach(["day" => 1000, "noon" => 6000, "sunset" => 12000, "night" => 13000, "midnight" => 18000, "sunrise" => 23000] as $word => $ticks){
			$this->dispatch("time set $word", $steve);
			self::assertSame($ticks, $this->times["overworld"], $word);
			self::assertSame($ticks, $this->times["nether"], $word);
		}
		$this->dispatch("time SET DAY", $steve);
		$this->dispatch("time SET Noon", $steve);
		self::assertSame(6000, $this->times["overworld"]);
	}

	public function testAddsToTheTimeOfEveryWorld() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Added 20 to the time"], $this->dispatch("time add 20", $steve));
		self::assertSame(120, $this->times["overworld"]);
		self::assertSame(270, $this->times["nether"]);
		$this->dispatch("time add 0", $steve);
		self::assertSame(120, $this->times["overworld"]);
	}

	public function testStartsAndStopsTime() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Stopped the time"], $this->dispatch("time stop", $steve));
		self::assertSame(["overworld stop", "nether stop"], $this->changes);
		self::assertSame(["Restarted the time"], $this->dispatch("time start", $steve));
		self::assertFalse($this->stopped["overworld"]);
	}

	public function testQueriesTheTimeOfTheSendersWorld() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Time is 54000"], $this->dispatch("time query", $steve));
		self::assertSame(["Time is 6000"], $this->dispatch("time query daytime", $steve));
		self::assertSame(["Time is 54000"], $this->dispatch("time query gametime", $steve));
		self::assertSame(["Time is 2"], $this->dispatch("time query day", $steve));
		self::assertSame(["Time is 6000"], $this->dispatch("time query DAYTIME", $steve));
		self::assertSame([], $this->changes);
	}

	public function testConsoleQueriesTheDefaultWorld() : void{
		self::assertSame(["Time is 100"], $this->dispatch("time query", $this->console()));
		self::assertSame(["Time is 100"], $this->dispatch("time query daytime", $this->console()));
		self::assertSame(["Time is 0"], $this->dispatch("time query day", $this->console()));
	}

	public function testRejectsInvalidInputWithoutChangingTime() : void{
		$steve = $this->spawn("Steve");

		foreach([
			"time", "time set", "time set abc", "time set -1", "time set 2147483648", "time set 99999999999999999999", "time set 1.5", "time set 1e3", "time set midday",
			"time set 1 2", "time set day extra", "time add", "time add -1", "time add abc", "time add 2147483648", "time add day", "time add 1 2", "time start now",
			"time stop now", "time query hours", "time query day extra", "time unknown", "time unknown 5", "time set  -0x10"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->changes, $line);
		}
	}

	public function testEachSubcommandHasItsOwnPermission() : void{
		$names = [
			"set" => ["time set 5", DefaultPermissionNames::COMMAND_TIME_SET],
			"setname" => ["time set noon", DefaultPermissionNames::COMMAND_TIME_SET],
			"add" => ["time add 5", DefaultPermissionNames::COMMAND_TIME_ADD],
			"start" => ["time start", DefaultPermissionNames::COMMAND_TIME_START],
			"stop" => ["time stop", DefaultPermissionNames::COMMAND_TIME_STOP]
		];
		foreach($names as $label => [$line, $permission]){
			$this->changes = [];
			$with = $this->spawn("With$label", [$permission]);
			$this->dispatch($line, $with);
			self::assertNotSame([], $this->changes, $label);

			foreach($names as [, $other]){
				if($other === $permission){
					continue;
				}
				$this->changes = [];
				$without = $this->spawn("Without$label", [$other]);
				$this->dispatch($line, $without);
				self::assertSame([], $this->changes, $label);
			}
		}

		$onlyQuery = $this->spawn("Query", [DefaultPermissionNames::COMMAND_TIME_QUERY]);
		$this->changes = [];
		$this->dispatch("time set 5", $onlyQuery);
		self::assertSame([], $this->changes);
		self::assertSame(["Time is 54000"], $this->dispatch("time query", $onlyQuery));

		$nothing = $this->spawn("Nothing", false);
		$this->dispatch("time query", $nothing);
		self::assertSame([], $this->changes);
	}
}
