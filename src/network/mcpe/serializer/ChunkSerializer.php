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

use Bedwire\Nbt;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\nbt\TreeRoot;
use quark\block\tile\Spawnable;
use quark\data\bedrock\BiomeIds;
use quark\data\bedrock\LegacyBiomeIdToStringIdMap;
use quark\network\mcpe\convert\BlockTranslator;
use quark\network\mcpe\protocol\types\DimensionIds;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use function count;

final class ChunkSerializer{
	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the min/max subchunk index expected in the protocol.
	 * This has no relation to the world height supported by PM.
	 *
	 * @phpstan-param DimensionIds::* $dimensionId
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public static function getDimensionChunkBounds(int $dimensionId) : array{
		return match($dimensionId){
			DimensionIds::OVERWORLD => [-4, 19],
			DimensionIds::NETHER => [0, 7],
			DimensionIds::THE_END => [0, 15],
			default => throw new \InvalidArgumentException("Unknown dimension ID $dimensionId"),
		};
	}

	/**
	 * Returns the number of subchunks that will be sent from the given chunk.
	 * Chunks are sent in a stack, so every chunk below the top non-empty one must be sent.
	 *
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public static function getSubChunkCount(Chunk $chunk, int $dimensionId) : int{
		//if the protocol world bounds ever exceed the PM supported bounds again in the future, we might need to
		//polyfill some stuff here
		[$minSubChunkIndex, $maxSubChunkIndex] = self::getDimensionChunkBounds($dimensionId);
		for($y = $maxSubChunkIndex, $count = $maxSubChunkIndex - $minSubChunkIndex + 1; $y >= $minSubChunkIndex; --$y, --$count){
			if($chunk->getSubChunk($y)->isEmptyFast()){
				continue;
			}
			return $count;
		}

		return 0;
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public static function serializeFullChunk(Chunk $chunk, int $dimensionId, BlockTranslator $blockTranslator, ?string $tiles = null, ?int $subChunkCount = null) : string{
		$stream = new ByteBufferWriter();

		$subChunkCount ??= self::getSubChunkCount($chunk, $dimensionId);
		$writtenCount = 0;

		[$minSubChunkIndex, $maxSubChunkIndex] = self::getDimensionChunkBounds($dimensionId);
		$subChunks = $chunk->getSubChunks();
		for($y = $minSubChunkIndex; $writtenCount < $subChunkCount; ++$y, ++$writtenCount){
			self::serializeSubChunk($subChunks[$y], $blockTranslator, $stream, false);
		}

		$knownBiomes = LegacyBiomeIdToStringIdMap::getInstance()->getLegacyToStringMap();
		//all biomes must always be written :(
		for($y = $minSubChunkIndex; $y <= $maxSubChunkIndex; ++$y){
			$biomes = $subChunks[$y]->getBiomeArray();
			$bitsPerBlock = $biomes->getBitsPerBlock();
			//the last bit is non-persistence (like for blocks), though it has no effect on biomes since they always use integer IDs
			Byte::writeUnsigned($stream, ($bitsPerBlock << 1) | 1);
			$stream->writeByteArray($biomes->getWordArray());

			$palette = $biomes->getPalette();
			if($bitsPerBlock !== 0){
				VarInt::writeSignedInt($stream, count($palette));
			}
			foreach($palette as $p){
				VarInt::writeSignedInt($stream, isset($knownBiomes[$p]) ? $p : BiomeIds::OCEAN);
			}
		}

		Byte::writeUnsigned($stream, 0); //border block array count
		//Border block entry format: 1 byte (4 bits X, 4 bits Z). These are however useless since they crash the regular client.

		if($tiles !== null){
			$stream->writeByteArray($tiles);
		}else{
			$stream->writeByteArray(self::serializeTiles($chunk));
		}
		return $stream->getData();
	}

	public static function serializeSubChunk(SubChunk $subChunk, BlockTranslator $blockTranslator, ByteBufferWriter $stream, bool $persistentBlockStates) : void{
		$layers = $subChunk->getBlockLayers();
		Byte::writeUnsigned($stream, 8); //version

		Byte::writeUnsigned($stream, count($layers));

		$blockStateDictionary = $blockTranslator->getBlockStateDictionary();

		foreach($layers as $blocks){
			$bitsPerBlock = $blocks->getBitsPerBlock();
			$words = $blocks->getWordArray();
			Byte::writeUnsigned($stream, ($bitsPerBlock << 1) | ($persistentBlockStates ? 0 : 1));
			$stream->writeByteArray($words);
			$palette = $blocks->getPalette();

			if($bitsPerBlock !== 0){
				VarInt::writeSignedInt($stream, count($palette)); //yes, this is intentionally zigzag
			}
			if($persistentBlockStates){
				foreach($palette as $p){
					//TODO: introduce a binary cache for this
					$state = $blockStateDictionary->generateDataFromStateId($blockTranslator->internalIdToNetworkId($p));
					if($state === null){
						$state = $blockTranslator->getFallbackStateData();
					}

					$stream->writeByteArray(Nbt::write(new TreeRoot($state->toNbt()), Nbt::NETWORK));
				}
			}else{
				foreach($palette as $p){
					VarInt::writeSignedInt($stream, $blockTranslator->internalIdToNetworkId($p));
				}
			}
		}
	}

	public static function serializeTiles(Chunk $chunk) : string{
		$encoded = "";
		foreach($chunk->getTiles() as $tile){
			if($tile instanceof Spawnable){
				$encoded .= $tile->getSerializedSpawnCompound();
			}
		}

		return $encoded;
	}
}
