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

use PHPUnit\Framework\TestCase;

final class WorldDeferredUpdateTest extends TestCase{

	public function testFullQueueEvictsStalestChunkInsteadOfDroppingNewUpdates() : void{
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		$updates = new \ReflectionProperty(World::class, "deferredBlockUpdates");
		$count = new \ReflectionProperty(World::class, "deferredBlockUpdateCount");
		$max = (new \ReflectionClassConstant(World::class, "MAX_DEFERRED_BLOCK_UPDATES"))->getValue();
		self::assertIsInt($max);

		self::assertTrue($world->deferUpdateUntilLoaded(1608, 64, 1608, 1));
		self::assertTrue($world->deferUpdateUntilLoaded(1608, 64, 1608, 1), "a repeat doesn't count twice");
		self::assertSame(1, $count->getValue($world));

		for($i = 1; $i < $max; ++$i){
			$world->deferUpdateUntilLoaded(8, $i & 0xff, 8 + ($i >> 8), 1);
		}
		self::assertSame($max, $count->getValue($world));

		self::assertTrue($world->deferUpdateUntilLoaded(3208, 64, 3208, 1));
		$buckets = $updates->getValue($world);
		self::assertIsArray($buckets);
		self::assertArrayNotHasKey(World::chunkHash(100, 100), $buckets, "the stalest chunk's updates were evicted");
		self::assertArrayHasKey(World::chunkHash(200, 200), $buckets, "the newest update was kept");
		self::assertSame($max, $count->getValue($world));
	}
}
