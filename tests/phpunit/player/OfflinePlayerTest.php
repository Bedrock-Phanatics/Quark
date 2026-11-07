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
use pocketmine\nbt\tag\ListTag;
use quark\data\SavedDataLoadingException;
use quark\item\custom\UnknownCustomItem;
use quark\item\VanillaItems;
use function array_keys;

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

	public function testInventoriesRoundTripThroughSaveData() : void{
		$player = new OfflinePlayer("Steve", CompoundTag::create()->setString("rank", "admin"));
		$player->getInventory()->setItem(35, VanillaItems::DIAMOND());
		$player->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$player->getOffHandInventory()->setItem(0, VanillaItems::TOTEM());
		$player->getEnderInventory()->setItem(26, VanillaItems::APPLE()->setCount(5));

		$data = $player->getSaveData();
		self::assertNotNull($data);
		self::assertSame("admin", $data->getString("rank"));
		$copy = new OfflinePlayer("Steve", $data);
		self::assertTrue(VanillaItems::DIAMOND()->equalsExact($copy->getInventory()->getItem(35)));
		self::assertTrue(VanillaItems::IRON_HELMET()->equalsExact($copy->getArmorInventory()->getHelmet()));
		self::assertTrue(VanillaItems::TOTEM()->equalsExact($copy->getOffHandInventory()->getItem(0)));
		self::assertTrue(VanillaItems::APPLE()->setCount(5)->equalsExact($copy->getEnderInventory()->getItem(26)));

		$copy->getOffHandInventory()->clearAll();
		$cleared = new OfflinePlayer("Steve", $copy->getSaveData());
		self::assertTrue($cleared->getOffHandInventory()->getItem(0)->isNull());
	}

	public function testSaveDataIsNullWhenInventoriesAreNotRead() : void{
		self::assertNull(new OfflinePlayer("Steve", CompoundTag::create())->getSaveData());
	}

	public function testUnloadableItemsFailInsteadOfBecomingAir() : void{
		$data = CompoundTag::create()->setTag("Inventory", new ListTag([
			CompoundTag::create()->setString("Name", "minecraft:does_not_exist")->setByte("Count", 1)->setByte("Slot", 9),
		]));
		$player = new OfflinePlayer("Steve", $data);

		for($attempt = 0; $attempt < 2; ++$attempt){
			try{
				$player->getEnderInventory();
				self::fail("Expected the unknown item to fail loading");
			}catch(SavedDataLoadingException){
			}
		}
		self::assertNull($player->getSaveData());
	}

	public function testUnknownCustomItemsSurviveAnUpdate() : void{
		$player = new OfflinePlayer("Steve", CompoundTag::create()->setTag("Inventory", new ListTag([
			CompoundTag::create()->setString("Name", "someplugin:gem")->setByte("Count", 1)->setByte("Slot", 9),
		])));
		$player->getInventory()->setItem(1, VanillaItems::DIAMOND());

		$item = new OfflinePlayer("Steve", $player->getSaveData())->getInventory()->getItem(0);
		self::assertInstanceOf(UnknownCustomItem::class, $item);
		self::assertSame("someplugin:gem", $item->getSavedIdentifier());
	}

	public function testOutOfRangeEnderChestSlotsAreIgnored() : void{
		$data = CompoundTag::create()->setTag("EnderChestInventory", new ListTag([
			VanillaItems::APPLE()->nbtSerialize(-2),
			VanillaItems::APPLE()->nbtSerialize(),
			VanillaItems::APPLE()->nbtSerialize(27),
			VanillaItems::DIAMOND()->nbtSerialize(3),
		]));

		$inventory = new OfflinePlayer("Steve", $data)->getEnderInventory();
		self::assertSame([3], array_keys($inventory->getContents()));
	}

	public function testMissingTagsHaveNoPlayTimes() : void{
		$player = new OfflinePlayer("Steve", CompoundTag::create());

		self::assertNull($player->getFirstPlayed());
		self::assertNull($player->getLastPlayed());
	}
}
