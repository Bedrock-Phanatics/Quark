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

use pocketmine\world\format\PalettedBlockArray;
use quark\data\bedrock\BiomeIds;

class SubChunkTest extends TestCase{

	/**
	 * Test that a cloned SubChunk instance doesn't influence the original
	 */
	public function testClone() : void{
		$sub1 = new SubChunk(0, null, null, new PalettedBlockArray(BiomeIds::OCEAN));

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

	public function testCloneCopiesBothLayers() : void{
		$layer1 = new PalettedBlockArray(0);
		$layer1->set(0, 0, 0, 5);
		$sub1 = new SubChunk(0, new PalettedBlockArray(0), $layer1, new PalettedBlockArray(BiomeIds::OCEAN));

		$sub2 = clone $sub1;

		self::assertNotSame($sub1->getBlockLayer0(), $sub2->getBlockLayer0());
		self::assertNotSame($sub1->getBlockLayer1(), $sub2->getBlockLayer1());
		self::assertSame(5, $sub2->getBlockLayer1()?->get(0, 0, 0));
	}

	public function testGarbageCollectionDropsEmptyLayersAndShiftsDown() : void{
		$layer1 = new PalettedBlockArray(0);
		$layer1->set(0, 0, 0, 5);
		$sub = new SubChunk(0, new PalettedBlockArray(0), $layer1, new PalettedBlockArray(BiomeIds::OCEAN));

		self::assertFalse($sub->isEmptyFast());
		self::assertFalse($sub->isEmptyAuthoritative());
		self::assertSame($layer1, $sub->getBlockLayer0());
		self::assertNull($sub->getBlockLayer1());
		self::assertSame([$layer1], $sub->getBlockLayers());

		$layer1->set(0, 0, 0, 0);
		self::assertTrue($sub->isEmptyAuthoritative());
		self::assertNull($sub->getBlockLayer0());
		self::assertSame([], $sub->getBlockLayers());
	}

	public function testLoneSecondLayerIsStoredAsFirst() : void{
		$layer = new PalettedBlockArray(0);
		$layer->set(0, 0, 0, 5);
		$sub = new SubChunk(0, null, $layer, new PalettedBlockArray(BiomeIds::OCEAN));

		self::assertSame($layer, $sub->getBlockLayer0());
		self::assertNull($sub->getBlockLayer1());
		self::assertSame(5, $sub->getBlockStateId(0, 0, 0));
		self::assertSame([$layer], $sub->getBlockLayers());
	}

	public function testSetBlockLeavesSecondLayerAlone() : void{
		$layer1 = new PalettedBlockArray(0);
		$layer1->set(0, 0, 0, 5);
		$sub = new SubChunk(0, new PalettedBlockArray(0), $layer1, new PalettedBlockArray(BiomeIds::OCEAN));

		$sub->setBlockStateId(0, 0, 0, 3);

		self::assertSame(3, $sub->getBlockStateId(0, 0, 0));
		self::assertSame(5, $sub->getBlockLayer1()?->get(0, 0, 0));
		self::assertSame(0, $sub->getHighestBlockAt(0, 0));
	}
}
