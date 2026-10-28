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

namespace quark\world\tnt;

use PHPUnit\Framework\TestCase;

final class TntConfigTest extends TestCase{
	protected function tearDown() : void{
		TntConfig::configure(true, 64, 192, 24, 20);
	}

	public function testConfiguredLimitsAreExposed() : void{
		TntConfig::configure(true, 12, 30, 4, 8);
		self::assertTrue(TntConfig::isEnabled());
		self::assertSame(12, TntConfig::getMaxActivePerChunk());
		self::assertSame(30, TntConfig::getMaxActiveNearby());
		self::assertSame(4, TntConfig::getMaxIgnitionsPerChunkPerTick());
		self::assertSame(8, TntConfig::getMaxDispenserIgnitionsPerSecond());
	}

	public function testLimitsCanBeDisabledWithZero() : void{
		TntConfig::configure(false, -1, -2, -3, -4);
		self::assertFalse(TntConfig::isEnabled());
		self::assertSame(0, TntConfig::getMaxActivePerChunk());
		self::assertSame(0, TntConfig::getMaxActiveNearby());
		self::assertSame(0, TntConfig::getMaxIgnitionsPerChunkPerTick());
		self::assertSame(0, TntConfig::getMaxDispenserIgnitionsPerSecond());
	}
}
