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
use quark\world\Dimension;
use quark\world\DimensionRegistry;
use quark\world\Weather;
use quark\world\World;
use quark\world\WorldManager;

final class WeatherCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var list<array{string, Weather, int|null}> */
	private array $changes = [];
	/** @phpstan-var array<string, Weather> */
	private array $weather = [];
	private bool $accept = true;
	private World $overworld;
	private World $nether;
	private World $end;

	protected function setUp() : void{
		parent::setUp();
		$this->overworld = $this->world("overworld", false);
		$this->nether = $this->world("nether", true);
		$this->end = $this->world("end", false, DimensionRegistry::getInstance()->get(Dimension::THE_END) ?? self::fail());
		$manager = self::createStub(WorldManager::class);
		$manager->method("getWorlds")->willReturn([$this->overworld, $this->nether, $this->end]);
		$manager->method("getDefaultWorld")->willReturn($this->overworld);
		$this->server->method("getWorldManager")->willReturn($manager);
	}

	private function world(string $name, bool $cycle, ?Dimension $dimension = null) : World&Stub{
		$this->weather[$name] = Weather::CLEAR;
		$world = self::createStub(World::class);
		$world->method("getDimension")->willReturn($dimension ?? DimensionRegistry::getInstance()->getDefault());
		$world->method("getFolderName")->willReturn($name);
		$world->method("getWeather")->willReturnCallback(fn() : Weather => $this->weather[$name]);
		$world->method("getWeatherDuration")->willReturn(1234);
		$world->method("isWeatherCycleEnabled")->willReturn($cycle);
		$world->method("setWeather")->willReturnCallback(function(Weather $weather, ?int $duration = null) use ($name) : bool{
			$this->changes[] = [$name, $weather, $duration];
			if($this->accept){
				$this->weather[$name] = $weather;
			}
			return $this->accept;
		});
		return $world;
	}

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$player->method("getWorld")->willReturn($this->nether);
		return $player;
	}

	public function testConsoleChangesEveryWorld() : void{
		$console = $this->console();
		self::assertSame(["Changed the weather to rain"], $this->dispatch("weather rain", $console));
		self::assertSame([["overworld", Weather::RAIN, null], ["nether", Weather::RAIN, null]], $this->changes);
	}

	public function testPlayerChangesOnlyTheirWorld() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Changed the weather to thunder"], $this->dispatch("weather THUNDER 600", $steve));
		self::assertSame([["nether", Weather::THUNDER, 600]], $this->changes);
		$this->dispatch("weather clear 1000000", $steve);
		$this->dispatch("weather clear 1", $steve);
		self::assertSame([["nether", Weather::THUNDER, 600], ["nether", Weather::CLEAR, 1000000], ["nether", Weather::CLEAR, 1]], $this->changes);
	}

	public function testDimensionWithoutWeather() : void{
		$player = $this->player("Steve", true);
		$player->method("getWorld")->willReturn($this->end);
		self::assertSame(["There is no weather in this dimension"], $this->dispatch("weather rain", $player));
		self::assertSame([], $this->changes);
	}

	public function testReportsCancelledChanges() : void{
		$this->accept = false;
		self::assertSame(["The weather change was cancelled"], $this->dispatch("weather rain", $this->console()));
	}

	public function testQuery() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["The weather in nether is clear for 1234 more ticks"], $this->dispatch("weather query", $steve));
		self::assertSame(["The weather in overworld is clear until it is changed"], $this->dispatch("weather QUERY", $this->console()));
		self::assertSame([], $this->changes);
	}

	public function testRejectsInvalidInputWithoutChangingTheWeather() : void{
		$steve = $this->spawn("Steve");

		foreach([
			"weather", "weather sunny", "weather rain 0", "weather rain -1", "weather rain 1000001", "weather rain abc", "weather rain 1.5", "weather rain 99999999999999999999",
			"weather rain 600 extra", "weather query rain", "weather query 5", "weather 5", "weather rain 1e3", "weather clear 0x10"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->changes, $line);
		}
		self::assertStringContainsString("Invalid value for <weather>", $this->dispatch("weather sunny", $steve)[0]);
	}

	public function testRequiresPermission() : void{
		$steve = $this->spawn("Steve", false);
		self::assertSame(["You don't have permission to use the command: weather"], $this->dispatch("weather query", $steve));
		$this->dispatch("weather rain", $steve);
		self::assertSame([], $this->changes);

		$allowed = $this->spawn("Alex", [DefaultPermissionNames::COMMAND_WEATHER]);
		$this->dispatch("weather rain", $allowed);
		self::assertCount(1, $this->changes);
	}
}
