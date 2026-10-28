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

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RedstoneConfigTest extends TestCase{
	protected function tearDown() : void{
		RedstoneConfig::configure(65536, 262144, 8192, true, true, 1024, 4096);
	}

	public function testAllWorldPolicyEnablesEveryWorldName() : void{
		RedstoneConfig::configure(10, 20, 30, true, true, 40, 50);
		self::assertTrue(RedstoneConfig::isWorldNameEnabled('world'));
		self::assertTrue(RedstoneConfig::isWorldNameEnabled('anything'));
	}

	public function testAllowlistIsNormalized() : void{
		RedstoneConfig::configure(10, 20, 30, true, true, 40, 50, true, RedstoneConfig::WORLD_POLICY_ALLOWLIST, [' Factions ', 'SPAWN']);
		self::assertTrue(RedstoneConfig::isWorldNameEnabled('factions'));
		self::assertTrue(RedstoneConfig::isWorldNameEnabled('Spawn'));
		self::assertFalse(RedstoneConfig::isWorldNameEnabled('survival'));
		self::assertSame(['factions', 'spawn'], RedstoneConfig::getWorlds());
	}

	public function testBlocklistDisablesOnlyListedWorlds() : void{
		RedstoneConfig::configure(10, 20, 30, true, true, 40, 50, true, RedstoneConfig::WORLD_POLICY_BLOCKLIST, ['redstone_test']);
		self::assertFalse(RedstoneConfig::isWorldNameEnabled('REDSTONE_TEST'));
		self::assertTrue(RedstoneConfig::isWorldNameEnabled('factions'));
	}

	public function testGlobalDisableOverridesWorldPolicy() : void{
		RedstoneConfig::configure(10, 20, 30, true, true, 40, 50, false, RedstoneConfig::WORLD_POLICY_ALLOWLIST, ['world']);
		self::assertFalse(RedstoneConfig::isWorldNameEnabled('world'));
	}

	public function testLimitsCannotBeConfiguredBelowOne() : void{
		RedstoneConfig::configure(0, -1, 0, true, true, -5, 0);
		self::assertSame(1, RedstoneConfig::getMaxWireNetworkSize());
		self::assertSame(1, RedstoneConfig::getMaxScheduledUpdates());
		self::assertSame(1, RedstoneConfig::getMaxUpdatesPerTick());
		self::assertSame(1, RedstoneConfig::getMaxPistonActionsPerTick());
		self::assertSame(1, RedstoneConfig::getMaxDispenserActionsPerTick());
	}

	public function testInvalidWorldPolicyIsRejected() : void{
		$this->expectException(InvalidArgumentException::class);
		RedstoneConfig::configure(10, 20, 30, true, true, 40, 50, true, 'sometimes');
	}
}
