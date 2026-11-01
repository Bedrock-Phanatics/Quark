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

namespace quark\world\light;

use PHPUnit\Framework\TestCase;
use pocketmine\world\format\PalettedBlockArray;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use quark\world\SimpleChunkManager;
use quark\world\utils\SubChunkExplorer;
use quark\world\World;
use function array_map;
use function mt_rand;
use function mt_srand;

final class LightPopulationTaskTest extends TestCase{

	private static function randomChunk(int $seed) : Chunk{
		mt_srand($seed);
		$pool = array_map(fn($block) => $block->getStateId(), [
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(), VanillaBlocks::GLASS(), VanillaBlocks::GLOWSTONE(),
			VanillaBlocks::TORCH(), VanillaBlocks::WATER(), VanillaBlocks::OAK_LEAVES(), VanillaBlocks::ICE(), VanillaBlocks::COBWEB(),
		]);
		$air = VanillaBlocks::AIR()->getStateId();
		$subChunks = [];
		foreach([-4, -1, 0, 3, 4, 19] as $y){
			$layer = new PalettedBlockArray($air);
			for($i = 0; $i < 600; ++$i){
				$layer->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), $pool[mt_rand(0, 9)]);
			}
			if($y === 0){
				for($x = 0; $x < 16; ++$x) for($z = 0; $z < 16; ++$z) $layer->set($x, 0, $z, VanillaBlocks::STONE()->getStateId());
			}
			$subChunks[$y] = new SubChunk($air, [$layer], new PalettedBlockArray(BiomeIds::PLAINS));
		}
		return new Chunk($subChunks, true);
	}

	public function testMatchesLightUpdates() : void{
		$registry = RuntimeBlockStateRegistry::getInstance();
		for($seed = 1; $seed <= 8; ++$seed){
			$expected = self::randomChunk($seed);
			$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
			$manager->setChunk(0, 0, $expected);
			foreach([
				new BlockLightUpdate(new SubChunkExplorer($manager), $registry->lightFilter, $registry->light),
				new SkyLightUpdate(new SubChunkExplorer($manager), $registry->lightFilter, $registry->blocksDirectSkyLight),
			] as $update){
				$update->recalculateChunk(0, 0);
				$update->execute();
			}

			$result = null;
			$task = new LightPopulationTask(self::randomChunk($seed), function(array $blockLight, array $skyLight, array $heightMap) use (&$result) : void{
				$result = [$blockLight, $skyLight, $heightMap];
			});
			$task->onRun();
			$task->onCompletion();
			self::assertNotNull($result);
			[$blockLight, $skyLight, $heightMap] = $result;

			self::assertSame($expected->getHeightMapArray(), $heightMap, "height map, seed $seed");
			foreach($expected->getSubChunks() as $y => $subChunk){
				self::assertSame($subChunk->getBlockLightArray()->getData(), $blockLight[$y]->getData(), "block light in subchunk $y, seed $seed");
				self::assertSame($subChunk->getBlockSkyLightArray()->getData(), $skyLight[$y]->getData(), "sky light in subchunk $y, seed $seed");
			}
		}
	}
}
