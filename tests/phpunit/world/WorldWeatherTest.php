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
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\utils\Limits;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\world\format\Chunk;

final class WorldWeatherTest extends TestCase{

	private static function world() : World{
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(World::class, "players"))->setValue($world, []);
		(new \ReflectionProperty(World::class, "time"))->setValue($world, World::TIME_NOON);
		$world->setWeatherCycleEnabled(true);
		return $world;
	}

	public function testSetWeather() : void{
		$world = self::world();
		self::assertSame(Weather::CLEAR, $world->getWeather());

		self::assertTrue($world->setWeather(Weather::RAIN, 100));
		self::assertSame(Weather::RAIN, $world->getWeather());
		self::assertSame(100, $world->getWeatherDuration());

		self::assertTrue($world->setWeather(Weather::THUNDER));
		self::assertSame(Weather::THUNDER, $world->getWeather());
		self::assertGreaterThanOrEqual(3600, $world->getWeatherDuration());
		self::assertLessThanOrEqual(15600, $world->getWeatherDuration());
	}

	public function testInvalidDurations() : void{
		$world = self::world();
		foreach([0, -1, Limits::INT32_MAX + 1] as $duration){
			try{
				$world->setWeather(Weather::RAIN, $duration);
				self::fail("Duration $duration was accepted");
			}catch(\InvalidArgumentException){
				self::assertSame(Weather::CLEAR, $world->getWeather());
			}
		}
	}

	public function testSkyLightReduction() : void{
		$world = self::world();
		(new \ReflectionProperty(World::class, "sunAnglePercentage"))->setValue($world, $world->computeSunAnglePercentage());
		$expected = [Weather::CLEAR->name => 0, Weather::RAIN->name => 3, Weather::THUNDER->name => 5];
		foreach([Weather::CLEAR, Weather::RAIN, Weather::THUNDER] as $weather){
			$world->setWeather($weather, 1);
			self::assertSame($expected[$weather->name], $world->getSkyLightReduction(), $weather->name);
		}
	}

	public function testWeatherPackets() : void{
		$events = function(Weather $weather) : array{
			$sent = [];
			$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["sendDataPacket"])->getMock();
			$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket|EncodedPacket $packet) use (&$sent) : bool{
				self::assertInstanceOf(LevelEventPacket::class, $packet);
				$sent[] = [$packet->eventId, $packet->eventData];
				return true;
			});
			$session->syncWorldWeather($weather);
			return $sent;
		};
		self::assertSame([[LevelEvent::STOP_RAIN, 0], [LevelEvent::STOP_THUNDER, 0]], $events(Weather::CLEAR));
		self::assertSame([[LevelEvent::START_RAIN, 65535], [LevelEvent::STOP_THUNDER, 0]], $events(Weather::RAIN));
		self::assertSame([[LevelEvent::START_RAIN, 65535], [LevelEvent::START_THUNDER, 65535]], $events(Weather::THUNDER));
	}

	public function testBiomesAreReadAtTheirHeight() : void{
		$world = self::world();
		(new \ReflectionProperty(World::class, "minY"))->setValue($world, World::Y_MIN);
		(new \ReflectionProperty(World::class, "maxY"))->setValue($world, World::Y_MAX);
		$chunk = new Chunk([], true);
		$chunk->setBiomeId(1, 100, 1, BiomeIds::DESERT);
		(new \ReflectionProperty(World::class, "chunks"))->setValue($world, [World::chunkHash(0, 0) => $chunk]);

		self::assertSame(BiomeIds::DESERT, $world->getBiomeId(1, 100, 1));
		self::assertNotSame(BiomeIds::DESERT, $world->getBiomeId(1, 4, 1));
		self::assertSame($world->getBiomeId(1, World::Y_MAX - 1, 1), $world->getBiomeId(1, World::Y_MAX + 50, 1));

		$this->expectException(\InvalidArgumentException::class);
		$world->setBiomeId(1, World::Y_MAX, 1, BiomeIds::DESERT);
	}

	public function testRainOnlyFallsUnderOpenSkyInWetBiomes() : void{
		$world = self::world();
		(new \ReflectionProperty(World::class, "minY"))->setValue($world, World::Y_MIN);
		(new \ReflectionProperty(World::class, "maxY"))->setValue($world, World::Y_MAX);
		$chunk = new Chunk([], true);
		$chunk->setBlockStateId(1, 64, 1, VanillaBlocks::STONE()->getStateId());
		for($y = World::Y_MIN; $y < World::Y_MAX; ++$y){
			$chunk->setBiomeId(2, $y, 2, BiomeIds::DESERT);
		}
		(new \ReflectionProperty(World::class, "chunks"))->setValue($world, [World::chunkHash(0, 0) => $chunk]);

		self::assertFalse($world->isRainingAt(1, 65, 1));
		$world->setWeather(Weather::RAIN, 100);
		self::assertTrue($world->isRainingAt(1, 65, 1));
		self::assertTrue($world->isRainingAt(1, 64, 1));
		self::assertFalse($world->isRainingAt(1, 63, 1));
		self::assertFalse($world->isRainingAt(2, 65, 2));
		self::assertFalse($world->isRainingAt(40, 65, 40));
	}

	public function testExpiredLightningIsRemovedInOrder() : void{
		$world = self::world();
		$bolts = new \ReflectionProperty(World::class, "lightningBolts");
		$bolts->setValue($world, [5 => 10, 6 => 10, 7 => 30]);
		$despawn = new \ReflectionMethod(World::class, "despawnLightning");

		$despawn->invoke($world, 9);
		self::assertSame([5 => 10, 6 => 10, 7 => 30], $bolts->getValue($world));
		$despawn->invoke($world, 10);
		self::assertSame([7 => 30], $bolts->getValue($world));
		$despawn->invoke($world, 30);
		self::assertSame([], $bolts->getValue($world));
	}
}
