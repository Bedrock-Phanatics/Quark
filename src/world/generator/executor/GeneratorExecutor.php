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

namespace quark\world\generator\executor;

use quark\world\format\Chunk;

interface GeneratorExecutor{
	/**
	 * @param Chunk[]|null[] $adjacentChunks
	 * @phpstan-param array<int, Chunk|null> $adjacentChunks
	 * @phpstan-param \Closure(Chunk $centerChunk, array<int, Chunk> $adjacentChunks) : void $onCompletion
	 */
	public function populate(int $chunkX, int $chunkZ, ?Chunk $centerChunk, array $adjacentChunks, \Closure $onCompletion) : void;

	public function shutdown() : void;

}
