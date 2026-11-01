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

namespace quark\world\format\io;

use PHPUnit\Framework\TestCase;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\world\format\Chunk;

final class FastChunkSerializerTest extends TestCase{

	public function testRoundTripWithRepeatedAndChangingBiomes() : void{
		$chunk = new Chunk([], true);
		$chunk->setBlockStateId(1, -60, 2, VanillaBlocks::STONE()->getStateId());
		$chunk->setBlockStateId(3, 100, 4, VanillaBlocks::DIRT()->getStateId());
		//a run of identical biomes, a different one in the middle, then the run again
		$chunk->setBiomeId(5, 40, 5, BiomeIds::DESERT);
		$chunk->setBiomeId(0, 300, 0, BiomeIds::PLAINS);

		$serialized = FastChunkSerializer::serializeTerrain($chunk);
		$copy = FastChunkSerializer::deserializeTerrain($serialized);

		self::assertSame($serialized, FastChunkSerializer::serializeTerrain($copy));
		self::assertTrue($copy->isPopulated());
		self::assertSame(VanillaBlocks::STONE()->getStateId(), $copy->getBlockStateId(1, -60, 2));
		self::assertSame(BiomeIds::DESERT, $copy->getBiomeId(5, 40, 5));
		self::assertSame(BiomeIds::PLAINS, $copy->getBiomeId(0, 300, 0));
		self::assertSame(BiomeIds::OCEAN, $copy->getBiomeId(0, 0, 0));
	}

	public function testRepeatedBiomesAreIndependentCopies() : void{
		$copy = FastChunkSerializer::deserializeTerrain(FastChunkSerializer::serializeTerrain(new Chunk([], false)));
		self::assertNotSame($copy->getSubChunk(0)->getBiomeArray(), $copy->getSubChunk(1)->getBiomeArray());

		$copy->setBiomeId(0, 16, 0, BiomeIds::DESERT);
		self::assertSame(BiomeIds::DESERT, $copy->getBiomeId(0, 16, 0));
		self::assertSame(BiomeIds::OCEAN, $copy->getBiomeId(0, 0, 0));
		self::assertSame(BiomeIds::OCEAN, $copy->getBiomeId(0, 32, 0));
	}
}
