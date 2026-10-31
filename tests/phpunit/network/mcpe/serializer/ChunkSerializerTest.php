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

namespace quark\network\mcpe\serializer;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\VarInt;
use pocketmine\world\format\PalettedBlockArray;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\types\DimensionIds;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use function array_fill;
use function array_keys;
use function array_map;
use function array_slice;

final class ChunkSerializerTest extends TestCase{

	/**
	 * Reads a serialized chunk back into palettes of network IDs.
	 *
	 * @return array{list<list<array{int, list<int>}>>, list<list<int>>, string}
	 */
	public static function decode(string $payload, int $subChunkCount, int $biomeCount) : array{
		$reader = new ByteBufferReader($payload);
		$subChunks = [];
		for($i = 0; $i < $subChunkCount; ++$i){
			self::assertSame(8, Byte::readUnsigned($reader));
			$layers = [];
			for($layer = 0, $layerCount = Byte::readUnsigned($reader); $layer < $layerCount; ++$layer){
				$header = Byte::readUnsigned($reader);
				self::assertSame(1, $header & 1, "network palettes are runtime IDs");
				$bitsPerBlock = $header >> 1;
				$reader->readByteArray(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
				$paletteSize = $bitsPerBlock !== 0 ? VarInt::readSignedInt($reader) : 1;
				$palette = [];
				for($p = 0; $p < $paletteSize; ++$p){
					$palette[] = VarInt::readSignedInt($reader);
				}
				$layers[] = [$bitsPerBlock, $palette];
			}
			$subChunks[] = $layers;
		}
		$biomes = [];
		for($i = 0; $i < $biomeCount; ++$i){
			$bitsPerBlock = Byte::readUnsigned($reader) >> 1;
			$reader->readByteArray(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
			$paletteSize = $bitsPerBlock !== 0 ? VarInt::readSignedInt($reader) : 1;
			$palette = [];
			for($p = 0; $p < $paletteSize; ++$p){
				$palette[] = VarInt::readSignedInt($reader);
			}
			$biomes[] = $palette;
		}
		self::assertSame(0, Byte::readUnsigned($reader), "border blocks");
		return [$subChunks, $biomes, $reader->readByteArray($reader->getUnreadLength())];
	}

	private static function networkId(int $stateId) : int{
		return TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($stateId);
	}

	public function testEmptyChunk() : void{
		$chunk = new Chunk([], false);
		self::assertSame(0, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD));
		$payload = ChunkSerializer::serializeFullChunk($chunk, DimensionIds::OVERWORLD, TypeConverter::getInstance()->getBlockTranslator());

		[$subChunks, $biomes, $tiles] = self::decode($payload, 0, 24);
		self::assertSame([], $subChunks);
		self::assertSame(array_fill(0, 24, [BiomeIds::OCEAN]), $biomes);
		self::assertSame("", $tiles);
	}

	public function testSubChunksBelowTheTopAreAllWritten() : void{
		$air = VanillaBlocks::AIR()->getStateId();
		$stone = VanillaBlocks::STONE()->getStateId();
		$water = VanillaBlocks::WATER()->getStateId();
		$chunk = new Chunk([], false);
		$chunk->setBlockStateId(0, -64, 0, $stone);
		$chunk->setBlockStateId(3, 80, 3, $stone);
		$waterLayer = new PalettedBlockArray($air);
		$waterLayer->set(3, 0, 3, $water);
		$chunk->setSubChunk(5, new SubChunk($air, [...$chunk->getSubChunk(5)->getBlockLayers(), $waterLayer], new PalettedBlockArray(BiomeIds::PLAINS)));
		$chunk->setBiomeId(0, 0, 0, 1000);

		$count = ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD);
		self::assertSame(10, $count, "subchunks -4 to 5");
		$payload = ChunkSerializer::serializeFullChunk($chunk, DimensionIds::OVERWORLD, TypeConverter::getInstance()->getBlockTranslator(), "tiles");

		[$subChunks, $biomes, $tiles] = self::decode($payload, $count, 24);
		self::assertSame([[1, [self::networkId($air), self::networkId($stone)]]], $subChunks[0]);
		for($i = 1; $i < 9; ++$i){
			self::assertSame([], $subChunks[$i], "empty subchunk $i");
		}
		self::assertSame([
			[1, [self::networkId($air), self::networkId($stone)]],
			[1, [self::networkId($air), self::networkId($water)]],
		], $subChunks[9]);
		self::assertSame([BiomeIds::OCEAN], $biomes[0]);
		self::assertSame([BiomeIds::OCEAN, BiomeIds::OCEAN], $biomes[4], "unknown biome IDs are sent as ocean");
		self::assertSame([BiomeIds::PLAINS], $biomes[9]);
		self::assertSame("tiles", $tiles);
	}

	public function testNetherOnlySendsItsOwnRange() : void{
		$chunk = new Chunk([], false);
		$chunk->setBlockStateId(0, -64, 0, VanillaBlocks::STONE()->getStateId());
		$chunk->setBlockStateId(0, 50, 0, VanillaBlocks::NETHERRACK()->getStateId());

		$count = ChunkSerializer::getSubChunkCount($chunk, DimensionIds::NETHER);
		self::assertSame(4, $count);
		$payload = ChunkSerializer::serializeFullChunk($chunk, DimensionIds::NETHER, TypeConverter::getInstance()->getBlockTranslator(), "");
		[$subChunks] = self::decode($payload, $count, 8);
		self::assertSame([1, [self::networkId(VanillaBlocks::AIR()->getStateId()), self::networkId(VanillaBlocks::NETHERRACK()->getStateId())]], $subChunks[3][0]);
	}

	public function testDensePalette() : void{
		$air = VanillaBlocks::AIR()->getStateId();
		$states = [];
		foreach(VanillaBlocks::getAll() as $block){
			$states[$block->getStateId()] = true;
		}
		$states = array_slice(array_keys($states), 0, 300);
		$layer = new PalettedBlockArray($air);
		foreach($states as $i => $state){
			$layer->set($i & 0xf, ($i >> 4) & 0xf, $i >> 8, $state);
		}
		$chunk = new Chunk([0 => new SubChunk($air, [$layer], new PalettedBlockArray(BiomeIds::PLAINS))], false);

		$count = ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD);
		$payload = ChunkSerializer::serializeFullChunk($chunk, DimensionIds::OVERWORLD, TypeConverter::getInstance()->getBlockTranslator(), "");
		[$subChunks] = self::decode($payload, $count, 24);
		[$bitsPerBlock, $palette] = $subChunks[4][0];
		self::assertSame($layer->getBitsPerBlock(), $bitsPerBlock);
		self::assertSame(array_map(self::networkId(...), $layer->getPalette()), $palette);
	}
}
