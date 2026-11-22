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
use pocketmine\world\format\LightArray;
use pocketmine\world\format\PalettedBlockArray;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use quark\world\light\BlockLightUpdate;
use quark\world\light\SkyLightUpdate;
use quark\world\utils\SubChunkExplorer;
use function array_map;
use function max;
use function mt_rand;
use function mt_srand;

final class WorldLightTest extends TestCase{

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private static function pool() : array{
		return array_map(fn($block) => $block->getStateId(), [
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(), VanillaBlocks::GLASS(), VanillaBlocks::GLOWSTONE(),
			VanillaBlocks::TORCH(), VanillaBlocks::WATER(), VanillaBlocks::OAK_LEAVES(), VanillaBlocks::ICE(), VanillaBlocks::DIRT(),
		]);
	}

	/**
	 * Builds a lit 3x3 area around chunk 0,0, the same for every call with the same seed.
	 */
	private static function area(int $seed) : SimpleChunkManager{
		mt_srand($seed);
		$pool = self::pool();
		$air = VanillaBlocks::AIR()->getStateId();
		$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
		for($chunkX = -1; $chunkX <= 1; ++$chunkX){
			for($chunkZ = -1; $chunkZ <= 1; ++$chunkZ){
				$subChunks = [];
				foreach([3, 4, 5] as $y){
					$layer = new PalettedBlockArray($air);
					for($x = 0; $x < 16; ++$x) for($z = 0; $z < 16; ++$z) $layer->set($x, 0, $z, VanillaBlocks::STONE()->getStateId());
					for($i = 0; $i < 300; ++$i){
						$layer->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), $pool[mt_rand(0, 9)]);
					}
					$subChunks[$y] = new SubChunk($air, $layer, null, new PalettedBlockArray(BiomeIds::PLAINS));
				}
				$chunk = new Chunk($subChunks, true);
				$chunk->setLightPopulated();
				$manager->setChunk($chunkX, $chunkZ, $chunk);
			}
		}
		for($chunkX = -1; $chunkX <= 1; ++$chunkX){
			for($chunkZ = -1; $chunkZ <= 1; ++$chunkZ){
				foreach(self::updates($manager) as $update){
					$update->recalculateChunk($chunkX, $chunkZ);
					$update->execute();
				}
			}
		}
		return $manager;
	}

	/**
	 * @return BlockLightUpdate[]|SkyLightUpdate[]
	 * @phpstan-return array{SkyLightUpdate, BlockLightUpdate}
	 */
	private static function updates(SimpleChunkManager $manager) : array{
		$registry = RuntimeBlockStateRegistry::getInstance();
		return [
			new SkyLightUpdate(new SubChunkExplorer($manager), $registry->lightFilter, $registry->blocksDirectSkyLight),
			new BlockLightUpdate(new SubChunkExplorer($manager), $registry->lightFilter, $registry->light),
		];
	}

	public function testFullLightMatchesSkyAndBlockLight() : void{
		mt_srand(7);
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		$set = static fn(string $property, mixed $value) => (new \ReflectionProperty(World::class, $property))->setValue($world, $value);
		$set("minY", World::Y_MIN);
		$set("maxY", World::Y_MAX);
		$chunks = [];
		foreach([[0, 0, true], [1, 0, true], [0, 1, false]] as [$chunkX, $chunkZ, $lit]){
			$chunk = new Chunk([], true);
			$chunk->setLightPopulated($lit);
			foreach($chunk->getSubChunks() as $subChunk){
				$sky = LightArray::fill(mt_rand(0, 15));
				$block = LightArray::fill(mt_rand(0, 15));
				for($i = 0; $i < 512; ++$i){
					$sky->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15));
					$block->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15));
				}
				$subChunk->setBlockSkyLightArray($sky);
				$subChunk->setBlockLightArray($block);
			}
			$chunks[World::chunkHash($chunkX, $chunkZ)] = $chunk;
		}
		$set("chunks", $chunks);

		foreach([true, false] as $hasSkyLight){
			$set("hasSkyLight", $hasSkyLight);
			for($reduction = 0; $reduction <= 11; ++$reduction){
				$set("skyLightReduction", $reduction);
				for($i = 0; $i < 2000; ++$i){
					$x = mt_rand(-8, 40);
					$y = mt_rand(World::Y_MIN - 4, World::Y_MAX + 4);
					$z = mt_rand(-8, 40);
					self::assertSame(max($world->getRealBlockSkyLightAt($x, $y, $z), $world->getBlockLightAt($x, $y, $z)), $world->getFullLightAt($x, $y, $z), "$x $y $z");
				}
			}
		}
	}

	public function testQueuedChangesMatchLightUpdates() : void{
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(World::class, "folderName"))->setValue($world, "light-test");
		(new \ReflectionProperty(World::class, "pulse"))->setValue($world, new WorldPulse($world));
		(new \ReflectionProperty(World::class, "blockStateRegistry"))->setValue($world, RuntimeBlockStateRegistry::getInstance());
		$chunks = new \ReflectionProperty(World::class, "chunks");
		$queue = new \ReflectionMethod(World::class, "queueLightChange");
		$execute = new \ReflectionMethod(World::class, "executeQueuedLightUpdates");

		for($seed = 1; $seed <= 4; ++$seed){
			$expected = self::area($seed);
			$actual = self::area($seed);
			$loaded = [];
			$expectedChunks = [];
			for($chunkX = -1; $chunkX <= 1; ++$chunkX){
				for($chunkZ = -1; $chunkZ <= 1; ++$chunkZ){
					$loaded[World::chunkHash($chunkX, $chunkZ)] = $actual->getChunk($chunkX, $chunkZ) ?? throw new \AssertionError();
					$expectedChunks[World::chunkHash($chunkX, $chunkZ)] = $expected->getChunk($chunkX, $chunkZ) ?? throw new \AssertionError();
				}
			}
			$chunks->setValue($world, $loaded);

			mt_srand($seed * 1000);
			$pool = self::pool();
			for($round = 0; $round < 5; ++$round){
				$changes = [];
				for($i = 0, $count = [3, 16, 17, 60, 200][$round]; $i < $count; ++$i){
					$changes[] = [mt_rand(-16, 31), mt_rand(40, 100), mt_rand(-16, 31), $pool[mt_rand(0, 9)]];
				}
				//the area's middle always changes last, so the light engine reaches every chunk the light can
				$changes[] = [8, 70, 8, $pool[$round]];

				[$sky, $block] = self::updates($expected);
				foreach($changes as $i => [$x, $y, $z, $stateId]){
					if($i === 16){
						//World runs the light updates of a tick's first changes before handing the rest to the engine
						$block->execute();
						$sky->execute();
						[$sky, $block] = self::updates($expected);
					}
					$expectedChunks[World::chunkHash($x >> 4, $z >> 4)]->setBlockStateId($x & 15, $y, $z & 15, $stateId);
					$sky->recalculateNode($x, $y, $z);
					$block->recalculateNode($x, $y, $z);

					$chunk = $loaded[World::chunkHash($x >> 4, $z >> 4)];
					$previousStateId = $chunk->getBlockStateId($x & 15, $y, $z & 15);
					$chunk->setBlockStateId($x & 15, $y, $z & 15, $stateId);
					$queue->invoke($world, $chunk, $x, $y, $z, $previousStateId, $stateId);
				}

				$block->execute();
				$sky->execute();
				$execute->invoke($world);

				foreach($expectedChunks as $hash => $expectedChunk){
					$actualChunk = $loaded[$hash];
					self::assertSame($expectedChunk->getHeightMapArray(), $actualChunk->getHeightMapArray(), "height map, seed $seed round $round");
					foreach($expectedChunk->getSubChunks() as $y => $subChunk){
						$actualSubChunk = $actualChunk->getSubChunk($y);
						self::assertSame($subChunk->getBlockLightArray()->getData(), $actualSubChunk->getBlockLightArray()->getData(), "block light, seed $seed round $round");
						self::assertSame($subChunk->getBlockSkyLightArray()->getData(), $actualSubChunk->getBlockSkyLightArray()->getData(), "sky light, seed $seed round $round");
					}
				}
			}
		}
	}
}
