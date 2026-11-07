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
use pocketmine\world\format\PalettedBlockArray;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;

use function substr_replace;

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

	public function testRoundTripKeepsBothBlockLayers() : void{
		$air = VanillaBlocks::AIR()->getStateId();
		$stone = new PalettedBlockArray($air);
		$stone->set(1, 2, 3, VanillaBlocks::STONE()->getStateId());
		$water = new PalettedBlockArray($air);
		$water->set(1, 2, 3, VanillaBlocks::WATER()->getStateId());
		$chunk = new Chunk([0 => new SubChunk($air, $stone, $water, new PalettedBlockArray(BiomeIds::OCEAN))], false);

		$serialized = FastChunkSerializer::serializeTerrain($chunk);
		$sub = FastChunkSerializer::deserializeTerrain($serialized)->getSubChunk(0);

		self::assertSame(VanillaBlocks::STONE()->getStateId(), $sub->getBlockLayer0()?->get(1, 2, 3));
		self::assertSame(VanillaBlocks::WATER()->getStateId(), $sub->getBlockLayer1()?->get(1, 2, 3));
		self::assertCount(2, $sub->getBlockLayers());
	}

	public function testMoreThanTwoBlockLayersAreRejected() : void{
		$valid = FastChunkSerializer::serializeTerrain(new Chunk([], false));
		//flags, subchunk count, then the first subchunk's y and air id put the layer count at offset 7
		$corrupt = substr_replace($valid, "\x03", 7, 1);

		$this->expectException(\UnexpectedValueException::class);
		FastChunkSerializer::deserializeTerrain($corrupt);
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
