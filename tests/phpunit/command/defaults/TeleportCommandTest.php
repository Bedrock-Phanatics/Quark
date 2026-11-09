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

use quark\entity\Location;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\world\World;
use function array_keys;

final class TeleportCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, Location> */
	private array $teleports = [];

	private function at(string $name) : Location{
		return $this->teleports[$name] ?? throw new \LogicException("$name was not teleported");
	}

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, float $x, float $y, float $z, float $yaw = 90.0, float $pitch = 10.0, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$world = self::createStub(World::class);
		$world->method("isLoaded")->willReturn(true);
		$location = new Location($x, $y, $z, $world, $yaw, $pitch);
		$player->method("getLocation")->willReturn($location);
		$player->method("teleport")->willReturnCallback(function(\quark\math\Vector3 $pos, ?float $yaw = null, ?float $pitch = null) use ($name) : bool{
			$this->teleports[$name] = $pos instanceof Location ? $pos : new Location($pos->x, $pos->y, $pos->z, null, $yaw ?? 0.0, $pitch ?? 0.0);
			return true;
		});
		return $player;
	}

	public function testPlayerAndCoordinateOverloads() : void{
		$steve = $this->spawn("Steve", 10, 64, 10);
		$this->spawn("Alex", 1, 70, 2, 45.0, 5.0);

		self::assertSame(["Teleported Steve to Alex"], $this->dispatch("tp Alex", $steve));
		self::assertSame([1.0, 70.0, 2.0], [$this->at("Steve")->x, $this->at("Steve")->y, $this->at("Steve")->z]);

		$this->teleports = [];
		self::assertSame(["Teleported Steve to 5, 66, 7"], $this->dispatch("tp 5 ~2 ~-3", $steve));
		self::assertSame([5.0, 66.0, 7.0, 90.0, 10.0], [$this->at("Steve")->x, $this->at("Steve")->y, $this->at("Steve")->z, $this->at("Steve")->yaw, $this->at("Steve")->pitch]);

		$this->dispatch("tp 0 64 0 ~10 200", $steve);
		self::assertSame([100.0, 90.0], [$this->at("Steve")->yaw, $this->at("Steve")->pitch]);

		$console = $this->console();
		$this->dispatch("tp Alex 3 65 4 0 0", $console);
		self::assertSame([3.0, 65.0, 4.0, 0.0, 0.0], [$this->at("Alex")->x, $this->at("Alex")->y, $this->at("Alex")->z, $this->at("Alex")->yaw, $this->at("Alex")->pitch]);
	}

	public function testRejectsMalformedAndOutOfRangeInput() : void{
		$steve = $this->spawn("Steve", 0, 64, 0);
		$this->teleports = [];

		foreach(["tp 1 2", "tp 1 2 3 4", "tp 1 2 3 4 5 6", "tp 1 nan 3", "tp 1 1e999 3", "tp ~ ~ ~x", "tp Nobody", "tp"] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->teleports, $line);
		}
		self::assertStringContainsString("too big", $this->dispatch("tp 0 9999 0", $steve)[0]);
		self::assertStringContainsString("too small", $this->dispatch("tp -30000001 64 0", $steve)[0]);
		self::assertSame([], $this->teleports);
	}

	public function testConsoleCannotTeleportItself() : void{
		$this->spawn("Alex", 0, 64, 0);
		$console = $this->console();
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("tp Alex", $console));
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("tp 1 2 3", $console));
	}

	public function testSelfPermissionDoesNotAllowTeleportingOthers() : void{
		$this->spawn("Alex", 0, 64, 0);
		$steve = $this->spawn("Steve", 0, 64, 0, permissions: [DefaultPermissionNames::COMMAND_TELEPORT_SELF]);
		$this->dispatch("tp Alex Steve", $steve);
		self::assertSame([], $this->teleports);
		$this->dispatch("tp Steve 1 2 3", $steve);
		self::assertSame(["Steve"], array_keys($this->teleports));
	}
}
