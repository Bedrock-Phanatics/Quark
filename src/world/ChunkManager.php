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
use quark\world\format\Chunk;

interface ChunkManager{

	/**
	 * Returns a Block object representing the block state at the given coordinates.
	 */
	public function getBlockAt(int $x, int $y, int $z) : Block;

	/**
	 * Sets the block at the given coordinates to the block state specified.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function setBlockAt(int $x, int $y, int $z, Block $block) : void;

	public function getChunk(int $chunkX, int $chunkZ) : ?Chunk;

	public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk) : void;

	/**
	 * Returns the lowest buildable Y coordinate of the world
	 */
	public function getMinY() : int;

	/**
	 * Returns the highest buildable Y coordinate of the world
	 */
	public function getMaxY() : int;

	/**
	 * Returns whether the specified coordinates are within the valid world boundaries, taking world format limitations
	 * into account.
	 */
	public function isInWorld(int $x, int $y, int $z) : bool;
}
