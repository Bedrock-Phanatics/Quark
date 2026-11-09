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

use pocketmine\math\Vector3;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\world\particle\DustParticle;
use quark\world\particle\ExplodeParticle;
use quark\world\particle\HeartParticle;
use quark\world\particle\ItemBreakParticle;
use quark\world\particle\Particle;
use quark\world\particle\RedstoneParticle;
use quark\world\particle\SmokeParticle;
use quark\world\particle\TerrainParticle;
use quark\world\Position;
use quark\world\World;
use quark\world\WorldManager;
use function abs;
use function explode;

final class ParticleCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var list<array{Vector3, Particle}> */
	private array $particles = [];
	private World $world;

	protected function setUp() : void{
		parent::setUp();
		$this->world = self::createStub(World::class);
		$this->world->method("isLoaded")->willReturn(true);
		$this->world->method("addParticle")->willReturnCallback(function(Vector3 $pos, Particle $particle) : void{
			$this->particles[] = [$pos, $particle];
		});
		$manager = self::createStub(WorldManager::class);
		$manager->method("getDefaultWorld")->willReturn($this->world);
		$this->server->method("getWorldManager")->willReturn($manager);
	}

	private function forget() : void{
		$this->particles = [];
	}

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, float $x = 10.0, float $y = 64.0, float $z = 10.0, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$player->method("getPosition")->willReturn(new Position($x, $y, $z, $this->world));
		return $player;
	}

	public function testSpawnsAParticleAtAPosition() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Playing effect explode for 1 times"], $this->dispatch("particle explode 1 2 3 0 0 0", $steve));
		self::assertCount(1, $this->particles);
		self::assertSame([1.0, 2.0, 3.0], [$this->particles[0][0]->x, $this->particles[0][0]->y, $this->particles[0][0]->z]);
		self::assertInstanceOf(ExplodeParticle::class, $this->particles[0][1]);
	}

	public function testRepeatsTheParticleCountTimesWithinTheSpread() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Playing effect explode for 50 times"], $this->dispatch("particle EXPLODE 0 64 0 2 3 4 50", $steve));
		self::assertCount(50, $this->particles);
		foreach($this->particles as [$pos]){
			self::assertLessThanOrEqual(2.0, abs($pos->x));
			self::assertLessThanOrEqual(3.0, abs($pos->y - 64.0));
			self::assertLessThanOrEqual(4.0, abs($pos->z));
		}
	}

	public function testRelativeCoordinatesStartFromTheSender() : void{
		$steve = $this->spawn("Steve", 10.5, 64.0, -3.0);
		$this->dispatch("particle explode ~1 ~ ~-2.5 0 0 0", $steve);
		self::assertSame([11.5, 64.0, -5.5], [$this->particles[0][0]->x, $this->particles[0][0]->y, $this->particles[0][0]->z]);

		$this->forget();
		$this->dispatch("particle explode ~ ~5 5 0 0 0", $this->console());
		self::assertSame([0.0, 5.0, 5.0], [$this->particles[0][0]->x, $this->particles[0][0]->y, $this->particles[0][0]->z]);
	}

	public function testBuildsParticlesFromData() : void{
		$steve = $this->spawn("Steve");
		$expected = [
			"smoke 3" => SmokeParticle::class,
			"smoke" => SmokeParticle::class,
			"reddust 2" => RedstoneParticle::class,
			"heart -5" => HeartParticle::class,
			"itembreak apple" => ItemBreakParticle::class,
			"iconcrack minecraft:apple" => ItemBreakParticle::class,
			"terrain stone" => TerrainParticle::class,
			"blockcrack stone" => TerrainParticle::class,
			"blockdust 255_0_10" => DustParticle::class,
			"blockdust 1_2_3_4" => DustParticle::class
		];
		foreach($expected as $spec => $class){
			[$name, $data] = explode(" ", $spec, limit: 2) + [1 => null];
			$this->forget();
			$this->dispatch("particle $name 0 64 0 0 0 0 1" . ($data === null ? "" : " $data"), $steve);
			self::assertCount(1, $this->particles, $spec);
			self::assertInstanceOf($class, $this->particles[0][1], $spec);
		}
	}

	public function testRejectsInvalidDataWithoutSpawningParticles() : void{
		$steve = $this->spawn("Steve");
		foreach([
			"smoke abc", "smoke 2147483648", "smoke 1.5", "smoke 99999999999999999999", "reddust x", "heart -2147483649", "itembreak", "itembreak notanitem", "itembreak air",
			"terrain", "terrain notablock", "terrain air", "terrain apple", "blockdust", "blockdust 1_2", "blockdust 256_0_0", "blockdust -1_0_0", "blockdust a_b_c",
			"blockdust 1_2_3_4_5", "blockdust 1__3", "blockdust _1_2_3"
		] as $spec){
			[$name, $data] = explode(" ", $spec, limit: 2) + [1 => null];
			$messages = $this->dispatch("particle $name 0 64 0 0 0 0 1" . ($data === null ? "" : " $data"), $steve);
			self::assertSame([], $this->particles, $spec);
			self::assertStringContainsString("Invalid value for <data>", $messages[0] ?? "", $spec);
		}
	}

	public function testRejectsInvalidInputWithoutSpawningParticles() : void{
		$steve = $this->spawn("Steve");

		foreach([
			"particle", "particle explode", "particle explode 0 64 0", "particle explode 0 64 0 0 0", "particle notaparticle 0 64 0 0 0 0", "particle explode 0 64 0 0 0 0 0",
			"particle explode 0 64 0 0 0 0 -1", "particle explode 0 64 0 0 0 0 1001", "particle explode 0 64 0 0 0 0 abc", "particle explode 0 64 0 0 0 0 1.5",
			"particle explode 0 64 0 257 0 0", "particle explode 0 64 0 0 -257 0", "particle explode 0 64 0 0 0 nan", "particle explode 0 64 0 0 0 1e999",
			"particle explode nan 64 0 0 0 0", "particle explode 0 inf 0 0 0 0", "particle explode 0 1e999 0 0 0 0", "particle explode 0 64 x 0 0 0",
			"particle explode 30000001 64 0 0 0 0", "particle explode -30000001 64 0 0 0 0", "particle explode 0 64 30000001 0 0 0", "particle explode 0 9999 0 0 0 0",
			"particle explode 0 -9999 0 0 0 0", "particle explode ~30000000 64 0 0 0 0", "particle explode 0 64 0 0 0 0 1 x extra"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->particles, $line);
		}
		self::assertStringContainsString("too big", $this->dispatch("particle explode 0 9999 0 0 0 0", $steve)[0]);
		self::assertStringContainsString("too small", $this->dispatch("particle explode -30000001 64 0 0 0 0", $steve)[0]);
	}

	public function testAcceptsTheLimits() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("particle explode 30000000 -64 -30000000 256 -256 0 1000", $steve);
		self::assertCount(1000, $this->particles);
	}

	public function testRequiresPermission() : void{
		$steve = $this->spawn("Steve", permissions: false);
		$this->dispatch("particle explode 0 64 0 0 0 0", $steve);
		self::assertSame([], $this->particles);

		$allowed = $this->spawn("Alex", permissions: [DefaultPermissionNames::COMMAND_PARTICLE]);
		$this->dispatch("particle explode 0 64 0 0 0 0", $allowed);
		self::assertCount(1, $this->particles);
	}
}
