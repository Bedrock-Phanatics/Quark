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

use quark\math\Vector3;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\world\Position;
use quark\world\World;
use quark\world\WorldManager;
use function array_keys;

final class SpawnCommandsTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, Position> */
	private array $spawns = [];
	/** @phpstan-var array<string, Vector3> */
	private array $worldSpawns = [];
	private World $overworld;
	private World $nether;

	protected function setUp() : void{
		parent::setUp();
		$this->overworld = $this->world("overworld");
		$this->nether = $this->world("nether");
		$manager = self::createStub(WorldManager::class);
		$manager->method("getDefaultWorld")->willReturn($this->overworld);
		$this->server->method("getWorldManager")->willReturn($manager);
	}

	private function forget() : void{
		$this->spawns = [];
		$this->worldSpawns = [];
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function spawned() : array{
		return array_keys($this->spawns);
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function worldSpawned() : array{
		return array_keys($this->worldSpawns);
	}

	private function world(string $name) : World{
		$world = self::createStub(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("setSpawnLocation")->willReturnCallback(function(Vector3 $pos) use ($name) : void{
			$this->worldSpawns[$name] = $pos;
		});
		return $world;
	}

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, World $world, float $x = 10.5, float $y = 64.25, float $z = -3.75, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$player->method("getPosition")->willReturn(new Position($x, $y, $z, $world));
		$player->method("getWorld")->willReturn($world);
		$player->method("setSpawn")->willReturnCallback(function(Vector3 $pos) use ($name) : void{
			self::assertInstanceOf(Position::class, $pos);
			$this->spawns[$name] = $pos;
		});
		return $player;
	}

	public function testSpawnpointHereUsesTheFlooredSenderPosition() : void{
		$steve = $this->spawn("Steve", $this->overworld);
		self::assertSame(["Set Steve's spawn point to (10, 64, -4)"], $this->dispatch("spawnpoint", $steve));
		self::assertEquals([10.0, 64.0, -4.0], [$this->spawns["Steve"]->x, $this->spawns["Steve"]->y, $this->spawns["Steve"]->z]);
		self::assertSame($this->overworld, $this->spawns["Steve"]->getWorld());
	}

	public function testSpawnpointHereForAnotherPlayer() : void{
		$steve = $this->spawn("Steve", $this->overworld);
		$this->spawn("Alex", $this->nether);
		self::assertSame(["Set Alex's spawn point to (10, 64, -4)"], $this->dispatch("spawnpoint Alex", $steve));
		self::assertEquals([10.0, 64.0, -4.0], [$this->spawns["Alex"]->x, $this->spawns["Alex"]->y, $this->spawns["Alex"]->z]);
		self::assertSame($this->overworld, $this->spawns["Alex"]->getWorld());

		$this->dispatch("spawnpoint @s", $steve);
		self::assertArrayHasKey("Steve", $this->spawns);
	}

	public function testSpawnpointAtAPosition() : void{
		$steve = $this->spawn("Steve", $this->overworld);
		$this->spawn("Alex", $this->nether, 0.0, 70.0, 0.0);
		self::assertSame(["Set Steve's spawn point to (1.5, 2, 3.25)"], $this->dispatch("spawnpoint 1.5 2 3.25", $steve));
		self::assertEquals([1.5, 2.0, 3.25], [$this->spawns["Steve"]->x, $this->spawns["Steve"]->y, $this->spawns["Steve"]->z]);

		$this->dispatch("spawnpoint Alex ~1 ~-2 ~", $steve);
		self::assertEquals([11.5, 62.25, -3.75], [$this->spawns["Alex"]->x, $this->spawns["Alex"]->y, $this->spawns["Alex"]->z]);
		self::assertSame($this->nether, $this->spawns["Alex"]->getWorld());

		$this->dispatch("spawnpoint Alex ~5 ~ ~", $this->console());
		self::assertEquals([5.0, 70.0, 0.0], [$this->spawns["Alex"]->x, $this->spawns["Alex"]->y, $this->spawns["Alex"]->z]);
	}

	public function testSpawnpointRejectsInvalidInput() : void{
		$steve = $this->spawn("Steve", $this->overworld);
		$this->spawn("Alex", $this->overworld);

		foreach([
			"spawnpoint Nobody", "spawnpoint 1 2", "spawnpoint 1 2 3 4", "spawnpoint Alex 1 2", "spawnpoint Alex 1 2 3 4", "spawnpoint nan 2 3", "spawnpoint 1 inf 3",
			"spawnpoint 1e999 2 3", "spawnpoint 30000001 64 0", "spawnpoint -30000001 64 0", "spawnpoint 0 321 0", "spawnpoint 0 -65 0", "spawnpoint 0 64 30000001",
			"spawnpoint Alex 0 9999 0", "spawnpoint Alex ~30000000 ~ ~", "spawnpoint Alex x y z", "spawnpoint @a", "spawnpoint Alex ~ ~ ~x"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->spawned(), $line);
		}
		self::assertStringContainsString("too big", $this->dispatch("spawnpoint 0 321 0", $steve)[0]);
		self::assertStringContainsString("too small", $this->dispatch("spawnpoint 0 -65 0", $steve)[0]);
	}

	public function testSpawnpointFromConsole() : void{
		$this->spawn("Alex", $this->overworld);
		$console = $this->console();
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("spawnpoint", $console));
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("spawnpoint Alex", $console));
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("spawnpoint 1 2 3", $console));
		self::assertSame([], $this->spawned());
		$this->dispatch("spawnpoint Alex 1 2 3", $console);
		self::assertEquals([1.0, 2.0, 3.0], [$this->spawns["Alex"]->x, $this->spawns["Alex"]->y, $this->spawns["Alex"]->z]);
	}

	public function testSpawnpointPermissions() : void{
		$steve = $this->spawn("Steve", $this->overworld, permissions: [DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF]);
		$this->spawn("Alex", $this->overworld);
		foreach(["spawnpoint Alex", "spawnpoint Alex 1 2 3"] as $line){
			$this->dispatch($line, $steve);
		}
		self::assertSame([], $this->spawned());
		$this->dispatch("spawnpoint", $steve);
		$this->dispatch("spawnpoint 1 2 3", $steve);
		$this->dispatch("spawnpoint Steve 1 2 3", $steve);
		self::assertSame(["Steve"], $this->spawned());

		$this->forget();
		$other = $this->spawn("Other", $this->overworld, permissions: [DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER]);
		$this->dispatch("spawnpoint", $other);
		$this->dispatch("spawnpoint 1 2 3", $other);
		$this->dispatch("spawnpoint Other", $other);
		self::assertSame([], $this->spawned());
		$this->dispatch("spawnpoint Alex 1 2 3", $other);
		self::assertSame(["Alex"], $this->spawned());
	}

	public function testSetWorldSpawnHereFloorsTheSenderPosition() : void{
		$steve = $this->spawn("Steve", $this->nether);
		self::assertSame(["Set the world spawn point to (10, 64, -4)"], $this->dispatch("setworldspawn", $steve));
		self::assertEquals([10.0, 64.0, -4.0], [$this->worldSpawns["nether"]->x, $this->worldSpawns["nether"]->y, $this->worldSpawns["nether"]->z]);
		self::assertArrayNotHasKey("overworld", $this->worldSpawns);
	}

	public function testSetWorldSpawnAtAPosition() : void{
		$steve = $this->spawn("Steve", $this->nether);
		$this->dispatch("setworldspawn 1.9 2.1 -0.5", $steve);
		self::assertEquals([1.0, 2.0, -1.0], [$this->worldSpawns["nether"]->x, $this->worldSpawns["nether"]->y, $this->worldSpawns["nether"]->z]);

		$this->dispatch("setworldspawn ~1 ~ ~-1", $steve);
		self::assertEquals([11.0, 64.0, -5.0], [$this->worldSpawns["nether"]->x, $this->worldSpawns["nether"]->y, $this->worldSpawns["nether"]->z]);

		self::assertSame(["Set the world spawn point to (7, 8, 9)"], $this->dispatch("setworldspawn 7 8 9", $this->console()));
		self::assertEquals([7.0, 8.0, 9.0], [$this->worldSpawns["overworld"]->x, $this->worldSpawns["overworld"]->y, $this->worldSpawns["overworld"]->z]);

		$this->forget();
		$this->dispatch("setworldspawn ~ ~3 ~", $this->console());
		self::assertEquals([0.0, 3.0, 0.0], [$this->worldSpawns["overworld"]->x, $this->worldSpawns["overworld"]->y, $this->worldSpawns["overworld"]->z]);
	}

	public function testSetWorldSpawnRejectsInvalidInput() : void{
		$steve = $this->spawn("Steve", $this->nether);

		foreach([
			"setworldspawn 1", "setworldspawn 1 2", "setworldspawn 1 2 3 4", "setworldspawn nan 2 3", "setworldspawn 1 inf 3", "setworldspawn 1e999 2 3",
			"setworldspawn 30000001 64 0", "setworldspawn -30000001 64 0", "setworldspawn 0 321 0", "setworldspawn 0 -65 0", "setworldspawn 0 64 30000001",
			"setworldspawn x y z", "setworldspawn ~ ~ ~x", "setworldspawn 0 64 0 extra", "setworldspawn Steve"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->worldSpawned(), $line);
		}
		self::assertStringContainsString("too big", $this->dispatch("setworldspawn 0 321 0", $steve)[0]);
		$this->dispatch("setworldspawn 0 -64 0", $steve);
		self::assertEquals(-64.0, $this->worldSpawns["nether"]->y);
	}

	public function testSetWorldSpawnFromConsoleNeedsPosition() : void{
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("setworldspawn", $this->console()));
		self::assertSame([], $this->worldSpawned());
	}

	public function testSetWorldSpawnPermission() : void{
		$steve = $this->spawn("Steve", $this->nether, permissions: false);
		$this->dispatch("setworldspawn", $steve);
		$this->dispatch("setworldspawn 1 2 3", $steve);
		self::assertSame([], $this->worldSpawned());

		$allowed = $this->spawn("Alex", $this->nether, permissions: [DefaultPermissionNames::COMMAND_SETWORLDSPAWN]);
		$this->dispatch("setworldspawn", $allowed);
		self::assertCount(1, $this->worldSpawns);
	}
}
