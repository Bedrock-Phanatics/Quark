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

namespace quark\event\world;

use quark\world\format\Chunk;
use quark\world\World;

/**
 * Chunk-related events
 */
abstract class ChunkEvent extends WorldEvent{
	public function __construct(
		World $world,
		private int $chunkX,
		private int $chunkZ,
		private Chunk $chunk
	){
		parent::__construct($world);
	}

	public function getChunk() : Chunk{
		return $this->chunk;
	}

	public function getChunkX() : int{ return $this->chunkX; }

	public function getChunkZ() : int{ return $this->chunkZ; }
}
