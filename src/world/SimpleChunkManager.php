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

use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\utils\Limits;
use quark\world\format\Chunk;

class SimpleChunkManager implements ChunkManager{

	/** @var Chunk[] */
	protected array $chunks = [];

	public function __construct(
		private int $minY,
		private int $maxY
	){}

	public function getBlockAt(int $x, int $y, int $z) : Block{
		if($this->isInWorld($x, $y, $z) && ($chunk = $this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) !== null){
			return RuntimeBlockStateRegistry::getInstance()->fromStateId($chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK));
		}
		return VanillaBlocks::AIR();
	}

	public function setBlockAt(int $x, int $y, int $z, Block $block) : void{
		if(($chunk = $this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)) !== null){
			$chunk->setBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK, $block->getStateId());
		}else{
			throw new \InvalidArgumentException("Cannot set block at coordinates x=$x,y=$y,z=$z, terrain is not loaded or out of bounds");
		}
	}

	public function getChunk(int $chunkX, int $chunkZ) : ?Chunk{
		return $this->chunks[World::chunkHash($chunkX, $chunkZ)] ?? null;
	}

	public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->chunks[World::chunkHash($chunkX, $chunkZ)] = $chunk;
	}

	public function cleanChunks() : void{
		$this->chunks = [];
	}

	public function getMinY() : int{
		return $this->minY;
	}

	public function getMaxY() : int{
		return $this->maxY;
	}

	public function isInWorld(int $x, int $y, int $z) : bool{
		return (
			$x <= Limits::INT32_MAX && $x >= Limits::INT32_MIN &&
			$y < $this->maxY && $y >= $this->minY &&
			$z <= Limits::INT32_MAX && $z >= Limits::INT32_MIN
		);
	}
}
