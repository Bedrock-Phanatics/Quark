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

namespace quark\world\redstone;

use PHPUnit\Framework\TestCase;
use function function_exists;

final class BlockUpdateQueueTest extends TestCase{
	protected function setUp() : void{
		if(!function_exists('morton3d_encode')){
			self::markTestSkipped('The Morton extension is required for block-position hashing.');
		}
	}

	public function testDuplicateDoesNotCreateMoreWork() : void{
		$queue = new BlockUpdateQueue();
		self::assertTrue($queue->push(1, 2, 3, 5, false, 10));
		self::assertTrue($queue->push(1, 2, 3, 8, false, 10));
		self::assertSame(1, $queue->getSize());
		self::assertSame([[1, 2, 3]], $queue->pop(5, 10));
		self::assertSame([], $queue->pop(8, 10));
	}

	public function testOverrideMovesExistingUpdate() : void{
		$queue = new BlockUpdateQueue();
		$queue->push(4, 5, 6, 3, false, 10);
		self::assertTrue($queue->push(4, 5, 6, 7, true, 10));
		self::assertSame([], $queue->pop(3, 10));
		self::assertSame([[4, 5, 6]], $queue->pop(7, 10));
	}

	public function testQueueLimitRejectsNewPositions() : void{
		$queue = new BlockUpdateQueue();
		self::assertTrue($queue->push(0, 0, 0, 1, false, 1));
		self::assertFalse($queue->push(1, 0, 0, 1, false, 1));
		self::assertSame(1, $queue->getSize());
	}

	public function testTickBudgetDefersRemainingUpdates() : void{
		$queue = new BlockUpdateQueue();
		$queue->push(1, 0, 0, 2, false, 10);
		$queue->push(2, 0, 0, 2, false, 10);

		self::assertCount(1, $queue->pop(2, 1));
		self::assertSame(1, $queue->getSize());
		self::assertCount(1, $queue->pop(3, 1));
		self::assertSame(0, $queue->getSize());
	}
}
