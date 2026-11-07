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

namespace quark\player;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;

final class OfflinePlayerTest extends TestCase{
	public function testPlayTimesAreMillisecondTimestamps() : void{
		$player = new OfflinePlayer("Steve", CompoundTag::create()
			->setLong(Player::TAG_FIRST_PLAYED, 1700000000123)
			->setLong(Player::TAG_LAST_PLAYED, 1700000100999));

		self::assertSame("1700000000123", $player->getFirstPlayed()?->format("Uv"));
		self::assertSame("1700000100999", $player->getLastPlayed()?->format("Uv"));
		self::assertTrue($player->hasPlayedBefore());
	}

	public function testMissingDataHasNoPlayTimes() : void{
		$player = new OfflinePlayer("Steve", null);

		self::assertNull($player->getFirstPlayed());
		self::assertNull($player->getLastPlayed());
		self::assertFalse($player->hasPlayedBefore());
	}

	public function testMissingTagsHaveNoPlayTimes() : void{
		$player = new OfflinePlayer("Steve", CompoundTag::create());

		self::assertNull($player->getFirstPlayed());
		self::assertNull($player->getLastPlayed());
	}
}
