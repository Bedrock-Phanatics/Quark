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

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\world\format\PalettedBlockArray;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use function array_values;
use function count;
use function pack;
use function strlen;
use function unpack;

/**
 * This class provides a serializer used for transmitting chunks between threads.
 * The serialization format **is not intended for permanent storage** and may change without warning.
 */
final class FastChunkSerializer{
	private const FLAG_POPULATED = 1 << 1;
	/** Written in place of the bits per block when a subchunk's biomes match the previous subchunk's. */
	private const REPEATED_BIOMES = 0xff;

	private function __construct(){
		//NOOP
	}

	/**
	 * @param int[] $palette
	 */
	private static function serializePalettedArray(ByteBufferWriter $stream, int $bitsPerBlock, string $wordArray, array $palette) : void{
		Byte::writeUnsigned($stream, $bitsPerBlock);
		$stream->writeByteArray($wordArray);
		$serialPalette = pack("L*", ...$palette);
		BE::writeUnsignedInt($stream, strlen($serialPalette));
		$stream->writeByteArray($serialPalette);
	}

	/**
	 * Fast-serializes the chunk for passing between threads
	 * TODO: tiles and entities
	 */
	public static function serializeTerrain(Chunk $chunk) : string{
		$stream = new ByteBufferWriter();
		Byte::writeUnsigned($stream, ($chunk->isPopulated() ? self::FLAG_POPULATED : 0));

		//subchunks
		$subChunks = $chunk->getSubChunks();
		$count = count($subChunks);
		Byte::writeUnsigned($stream, $count);

		//most subchunks share their neighbour's biomes, especially the empty ones above the terrain
		$previousBiomes = null;
		foreach($subChunks as $y => $subChunk){
			Byte::writeSigned($stream, $y);
			BE::writeUnsignedInt($stream, $subChunk->getEmptyBlockId());

			$layers = $subChunk->getBlockLayers();
			Byte::writeUnsigned($stream, count($layers));
			foreach($layers as $blocks){
				self::serializePalettedArray($stream, $blocks->getBitsPerBlock(), $blocks->getWordArray(), $blocks->getPalette());
			}

			$biomeArray = $subChunk->getBiomeArray();
			$biomes = [$biomeArray->getBitsPerBlock(), $biomeArray->getWordArray(), $biomeArray->getPalette()];
			if($biomes === $previousBiomes){
				Byte::writeUnsigned($stream, self::REPEATED_BIOMES);
			}else{
				self::serializePalettedArray($stream, ...$biomes);
				$previousBiomes = $biomes;
			}
		}

		return $stream->getData();
	}

	private static function deserializePalettedArray(ByteBufferReader $stream, int $bitsPerBlock) : PalettedBlockArray{
		$words = $stream->readByteArray(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
		$paletteSize = BE::readUnsignedInt($stream);
		/** @var int[] $unpackedPalette */
		$unpackedPalette = unpack("L*", $stream->readByteArray($paletteSize)); //unpack() will never fail here
		$palette = array_values($unpackedPalette);

		return PalettedBlockArray::fromData($bitsPerBlock, $words, $palette);
	}

	/**
	 * Deserializes a fast-serialized chunk
	 */
	public static function deserializeTerrain(string $data) : Chunk{
		$stream = new ByteBufferReader($data);

		$flags = Byte::readUnsigned($stream);
		$terrainPopulated = (bool) ($flags & self::FLAG_POPULATED);

		$subChunks = [];

		$previousBiomes = null;
		$count = Byte::readUnsigned($stream);
		for($subCount = 0; $subCount < $count; ++$subCount){
			$y = Byte::readSigned($stream);
			//TODO: why the heck are we using big-endian here?
			$airBlockId = BE::readUnsignedInt($stream);

			$layers = [];
			for($i = 0, $layerCount = Byte::readUnsigned($stream); $i < $layerCount; ++$i){
				$layers[] = self::deserializePalettedArray($stream, Byte::readUnsigned($stream));
			}
			$biomeBitsPerBlock = Byte::readUnsigned($stream);
			if($biomeBitsPerBlock === self::REPEATED_BIOMES){
				//every subchunk needs its own copy, since biomes can be changed
				$biomeArray = clone ($previousBiomes ?? throw new \InvalidArgumentException("Repeated biomes without a previous subchunk"));
			}else{
				$biomeArray = $previousBiomes = self::deserializePalettedArray($stream, $biomeBitsPerBlock);
			}
			$subChunks[$y] = new SubChunk($airBlockId, $layers, $biomeArray);
		}

		return new Chunk($subChunks, $terrainPopulated);
	}
}
