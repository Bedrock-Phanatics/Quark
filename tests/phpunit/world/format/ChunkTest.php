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

namespace quark\world\format;

use PHPUnit\Framework\TestCase;

class ChunkTest extends TestCase{

	public function testClone() : void{
		$chunk = new Chunk([], false);
		$chunk->setBlockStateId(0, 0, 0, 1);
		$chunk->setBiomeId(0, 0, 0, 1);
		$chunk->setHeightMap(0, 0, 1);

		$chunk2 = clone $chunk;
		$chunk2->setBlockStateId(0, 0, 0, 2);
		$chunk2->setBiomeId(0, 0, 0, 2);
		$chunk2->setHeightMap(0, 0, 2);

		self::assertNotSame($chunk->getBlockStateId(0, 0, 0), $chunk2->getBlockStateId(0, 0, 0));
		self::assertNotSame($chunk->getBiomeId(0, 0, 0), $chunk2->getBiomeId(0, 0, 0));
		self::assertNotSame($chunk->getHeightMap(0, 0), $chunk2->getHeightMap(0, 0));
	}
}
