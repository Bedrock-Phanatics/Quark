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

use pocketmine\world\format\PalettedBlockArray;

use PHPUnit\Framework\TestCase;
use quark\data\bedrock\BiomeIds;

class SubChunkTest extends TestCase{

	/**
	 * Test that a cloned SubChunk instance doesn't influence the original
	 */
	public function testClone() : void{
		$sub1 = new SubChunk(0, [], new PalettedBlockArray(BiomeIds::OCEAN));

		$sub1->setBlockStateId(0, 0, 0, 1);
		$sub1->getBlockLightArray()->set(0, 0, 0, 1);
		$sub1->getBlockSkyLightArray()->set(0, 0, 0, 1);

		$sub2 = clone $sub1;

		$sub2->setBlockStateId(0, 0, 0, 2);
		$sub2->getBlockLightArray()->set(0, 0, 0, 2);
		$sub2->getBlockSkyLightArray()->set(0, 0, 0, 2);

		self::assertNotSame($sub1->getBlockStateId(0, 0, 0), $sub2->getBlockStateId(0, 0, 0));
		self::assertNotSame($sub1->getBlockLightArray()->get(0, 0, 0), $sub2->getBlockLightArray()->get(0, 0, 0));
		self::assertNotSame($sub1->getBlockSkyLightArray()->get(0, 0, 0), $sub2->getBlockSkyLightArray()->get(0, 0, 0));
	}
}
