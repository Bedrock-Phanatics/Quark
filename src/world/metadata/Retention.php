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

namespace quark\world\metadata;

/**
 * What a block change does to a block metadata value. Only changes made through the world (setBlock, setChunk and
 * everything built on them) are seen; editing a loaded Chunk object directly bypasses this.
 */
enum Retention : int{
	/**
	 * Belongs to the coordinate and survives any block change. Moves and copies leave it behind.
	 */
	case POSITION = 0;
	/**
	 * Removed when a block of another type replaces the block, kept through state changes such as rotation.
	 */
	case BLOCK_TYPE = 1;
	/**
	 * Removed by any change of the block state.
	 */
	case BLOCK_STATE = 2;
}
