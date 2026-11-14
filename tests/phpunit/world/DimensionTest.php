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

namespace quark\world;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use quark\block\VanillaBlocks;
use quark\entity\Entity;
use quark\item\VanillaItems;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\format\Chunk;
use quark\world\format\io\data\BedrockWorldData;
use quark\world\format\io\exception\UnsupportedWorldFormatException;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\format\io\WritableWorldProvider;
use quark\world\format\io\zigrite\ZigriteDB;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use function array_key_last;
use function array_keys;
use function sys_get_temp_dir;
use function uniqid;

final class DimensionTest extends TestCase{

	private string $path;

	protected function setUp() : void{
		DimensionRegistry::reset();
		$this->path = Path::join(sys_get_temp_dir(), uniqid("quark-dimension-test-"));
	}

	protected function tearDown() : void{
		DimensionRegistry::reset();
		(new Filesystem())->remove($this->path);
	}

	public function testVanillaDimensions() : void{
		$registry = DimensionRegistry::getInstance();
		self::assertSame([Dimension::OVERWORLD, Dimension::NETHER, Dimension::THE_END], array_keys($registry->getAll()));
		self::assertSame($registry->get(Dimension::OVERWORLD), $registry->getDefault());

		$overworld = $registry->getDefault();
		self::assertSame([DimensionIds::OVERWORLD, World::Y_MIN, World::Y_MAX, true, true, true, false], [$overworld->networkId, $overworld->minY, $overworld->maxY, $overworld->skyLight, $overworld->weather, $overworld->bedWorks, $overworld->respawnAnchorWorks]);
		$nether = $registry->get(Dimension::NETHER) ?? self::fail();
		self::assertSame([DimensionIds::NETHER, 0, 128, false, false, false, true], [$nether->networkId, $nether->minY, $nether->maxY, $nether->skyLight, $nether->weather, $nether->bedWorks, $nether->respawnAnchorWorks]);
		$end = $registry->get(Dimension::THE_END) ?? self::fail();
		self::assertSame([DimensionIds::THE_END, 0, 256, false, false, false, false], [$end->networkId, $end->minY, $end->maxY, $end->skyLight, $end->weather, $end->bedWorks, $end->respawnAnchorWorks]);
		self::assertNull($registry->get("minecraft:unknown"));
	}

	public function testCustomDimension() : void{
		$registry = DimensionRegistry::getInstance();
		$skyblock = new Dimension("test:skyblock", DimensionIds::OVERWORLD, 0, 256, weather: false);
		$registry->register($skyblock);
		self::assertSame($skyblock, $registry->get("test:skyblock"));
		self::assertSame("test:skyblock", array_key_last($registry->getAll()));
	}

	public function testDuplicateIdentifierIsRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		DimensionRegistry::getInstance()->register(new Dimension(Dimension::NETHER, DimensionIds::NETHER, 0, 128));
	}

	/**
	 * @return \Generator<string, array{string, int, int, int}>
	 */
	public static function invalidDimensionProvider() : \Generator{
		yield "no namespace" => ["skyblock", DimensionIds::OVERWORLD, 0, 256];
		yield "uppercase" => ["Test:Skyblock", DimensionIds::OVERWORLD, 0, 256];
		yield "empty path" => ["test:", DimensionIds::OVERWORLD, 0, 256];
		yield "unknown client dimension" => ["test:a", 3, 0, 256];
		yield "unaligned min" => ["test:a", DimensionIds::OVERWORLD, 8, 256];
		yield "unaligned max" => ["test:a", DimensionIds::OVERWORLD, 0, 250];
		yield "empty range" => ["test:a", DimensionIds::OVERWORLD, 64, 64];
		yield "inverted range" => ["test:a", DimensionIds::OVERWORLD, 128, 64];
		yield "below the client" => ["test:a", DimensionIds::OVERWORLD, -80, 256];
		yield "above the client" => ["test:a", DimensionIds::OVERWORLD, 0, 336];
		yield "taller than the nether client" => ["test:a", DimensionIds::NETHER, 0, 144];
		yield "below the nether client" => ["test:a", DimensionIds::NETHER, -16, 128];
		yield "taller than the end client" => ["test:a", DimensionIds::THE_END, 0, 272];
	}

	/**
	 * @dataProvider invalidDimensionProvider
	 */
	public function testInvalidDefinitionsAreRejected(string $identifier, int $networkId, int $minY, int $maxY) : void{
		$this->expectException(\InvalidArgumentException::class);
		/** @phpstan-ignore argument.type */
		new Dimension($identifier, $networkId, $minY, $maxY);
	}

	public function testCreationOptions() : void{
		$options = WorldCreationOptions::create();
		self::assertSame(DimensionRegistry::getInstance()->getDefault(), $options->getDimension());
		$nether = DimensionRegistry::getInstance()->get(Dimension::NETHER) ?? self::fail();
		self::assertSame($nether, $options->setDimension($nether)->getDimension());

		$this->expectException(\InvalidArgumentException::class);
		$options->setDimension(new Dimension("test:unregistered", DimensionIds::OVERWORLD, 0, 256));
	}

	public function testCreationOptionsRejectAnImpostor() : void{
		$this->expectException(\InvalidArgumentException::class);
		WorldCreationOptions::create()->setDimension(new Dimension(Dimension::NETHER, DimensionIds::NETHER, 0, 128));
	}

	/**
	 * @return \Generator<string, array{\Closure(string, WorldCreationOptions) : void, \Closure(string) : WritableWorldProvider}>
	 */
	public static function formatProvider() : \Generator{
		yield "leveldb" => [
			static fn(string $path, WorldCreationOptions $options) => LevelDB::generate($path, "Dimension Test", $options),
			static fn(string $path) => new LevelDB($path, self::logger())
		];
		yield "zigritedb" => [
			static fn(string $path, WorldCreationOptions $options) => ZigriteDB::generate($path, "Dimension Test", $options),
			static fn(string $path) => new ZigriteDB($path, self::logger())
		];
	}

	/**
	 * @dataProvider formatProvider
	 * @phpstan-param \Closure(string, WorldCreationOptions) : void $generate
	 * @phpstan-param \Closure(string) : WritableWorldProvider $open
	 */
	public function testDimensionPersists(\Closure $generate, \Closure $open) : void{
		$custom = new Dimension("test:void", DimensionIds::THE_END, 0, 64);
		DimensionRegistry::getInstance()->register($custom);
		try{
			$generate($this->path, WorldCreationOptions::create()->setDimension($custom));
			$provider = $open($this->path);
		}catch(UnsupportedWorldFormatException $e){
			self::markTestSkipped($e->getMessage());
		}
		try{
			self::assertSame("test:void", $provider->getWorldData()->getDimension());
			$provider->getWorldData()->save();
		}finally{
			$provider->close();
		}
		$provider = $open($this->path);
		try{
			self::assertSame("test:void", $provider->getWorldData()->getDimension());
		}finally{
			$provider->close();
		}
	}

	private static function logger() : \Logger{
		return new \PrefixedLogger(\GlobalLogger::get(), "DimensionTest");
	}

	public function testWorldsWithoutADimensionAreOverworld() : void{
		LevelDB::generate($this->path, "Old World", WorldCreationOptions::create());
		$levelDat = Path::join($this->path, "level.dat");
		$data = new BedrockWorldData($levelDat);
		self::assertSame(Dimension::OVERWORLD, $data->getDimension());
		$data->getCompoundTag()->removeTag("dimension");
		$data->save();

		self::assertSame(Dimension::OVERWORLD, (new BedrockWorldData($levelDat))->getDimension());
	}

	private static function world(Dimension $dimension) : World{
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		$set = static fn(string $property, mixed $value) => (new \ReflectionProperty(World::class, $property))->setValue($world, $value);
		$set("players", []);
		$set("dimension", $dimension);
		$set("minY", $dimension->minY);
		$set("maxY", $dimension->maxY);
		$set("hasSkyLight", $dimension->skyLight);
		$set("hasWeather", $dimension->weather);
		$chunk = new Chunk([], true);
		$chunk->setLightPopulated();
		$chunk->getSubChunk(0)->getBlockSkyLightArray()->set(0, 0, 0, 15);
		$set("chunks", [World::chunkHash(0, 0) => $chunk]);
		return $world;
	}

	public function testSkyLight() : void{
		$overworld = self::world(DimensionRegistry::getInstance()->getDefault());
		self::assertSame(15, $overworld->getPotentialBlockSkyLightAt(0, 0, 0));
		self::assertSame(15, $overworld->getPotentialBlockSkyLightAt(0, World::Y_MAX, 0));

		$nether = self::world(DimensionRegistry::getInstance()->get(Dimension::NETHER) ?? self::fail());
		self::assertSame(0, $nether->getPotentialBlockSkyLightAt(0, 0, 0));
		self::assertSame(0, $nether->getPotentialBlockSkyLightAt(0, 200, 0));
		self::assertSame(0, $nether->getRealBlockSkyLightAt(0, 0, 0));

		$low = new Dimension("test:low", DimensionIds::OVERWORLD, 0, 128);
		self::assertSame(15, self::world($low)->getPotentialBlockSkyLightAt(0, 128, 0), "Sky light starts at the dimension's build limit");
	}

	public function testHeightLimits() : void{
		$nether = self::world(DimensionRegistry::getInstance()->get(Dimension::NETHER) ?? self::fail());
		self::assertSame(0, $nether->getMinY());
		self::assertSame(128, $nether->getMaxY());
		self::assertTrue($nether->isInWorld(0, 127, 0));
		self::assertFalse($nether->isInWorld(0, 128, 0));
		self::assertFalse($nether->isInWorld(0, -1, 0));
	}

	public function testDimensionWithoutWeatherStaysClear() : void{
		$world = self::world(DimensionRegistry::getInstance()->get(Dimension::NETHER) ?? self::fail());
		self::assertFalse($world->setWeather(Weather::RAIN, 100));
		self::assertFalse($world->setWeather(Weather::THUNDER));
		self::assertSame(Weather::CLEAR, $world->getWeather());
		self::assertTrue($world->setWeather(Weather::CLEAR, 100));
		self::assertSame(100, $world->getWeatherDuration());

		self::assertTrue(self::world(DimensionRegistry::getInstance()->getDefault())->setWeather(Weather::RAIN, 100));
	}

	public function testRespawnAnchorSetsSpawnWhereItWorks() : void{
		$nether = DimensionRegistry::getInstance()->get(Dimension::NETHER) ?? self::fail();
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getDimension")->willReturn($nether);
		$anchor = VanillaBlocks::RESPAWN_ANCHOR()->setCharges(1);
		$anchor->position($world, 0, 64, 0);

		$player = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$player->expects(self::once())->method("setSpawn")->with(self::callback(fn(?Vector3 $pos) => $pos !== null && $pos->equals(new Vector3(0, 64, 0))));
		self::assertTrue($anchor->onInteract(VanillaItems::AIR(), 0, Vector3::zero(), $player));
	}
}
