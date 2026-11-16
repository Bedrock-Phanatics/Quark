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

namespace quark\item;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\inventory\ArmorInventory;
use quark\utils\Utils;
use quark\world\format\io\GlobalItemDataHandlers;
use function count;
use function strtolower;

final class VanillaParityItemsTest extends TestCase{

	/**
	 * @return Item[]
	 * @phpstan-return array<string, Item>
	 */
	private static function newItems() : array{
		$items = [];
		foreach(Utils::stringifyKeys(VanillaItems::getAll()) as $name => $item){
			if($item->getTypeId() >= ItemTypeIds::ANGLER_POTTERY_SHERD || $item->getTypeId() === ItemTypeIds::POWDER_SNOW_BUCKET){
				$items[$name] = $item;
			}
		}
		return $items;
	}

	public function testNewItemsRoundTrip() : void{
		$items = self::newItems();
		self::assertGreaterThan(150, count($items));
		foreach(Utils::stringifyKeys($items) as $name => $item){
			$name = strtolower($name);
			$item->setCount(1);
			$copy = Item::nbtDeserialize($item->nbtSerialize());
			self::assertTrue($item->equalsExact($copy), "$name did not survive an NBT round trip");

			$data = GlobalItemDataHandlers::getSerializer()->serializeType($item);
			self::assertSame("minecraft:" . $name, $data->getName());
			self::assertNotNull(StringToItemParser::getInstance()->parse($name), "$name has no string alias");
		}
	}

	public function testUnknownTagsAreKept() : void{
		$compass = VanillaItems::LODESTONE_COMPASS();
		$compass->getNamedTag()->setInt("trackingHandle", 7);
		$copy = Item::nbtDeserialize($compass->nbtSerialize());
		self::assertSame(7, $copy->getNamedTag()->getInt("trackingHandle"));
	}

	public function testDurabilityIsSaved() : void{
		$shield = VanillaItems::SHIELD();
		$shield->applyDamage(10);
		$copy = Item::nbtDeserialize($shield->nbtSerialize());
		self::assertInstanceOf(Shield::class, $copy);
		self::assertSame(10, $copy->getDamage());
	}

	public function testStackSizes() : void{
		foreach([
			VanillaItems::SADDLE(), VanillaItems::CREEPER_BANNER_PATTERN(), VanillaItems::IRON_HORSE_ARMOR(),
			VanillaItems::COD_BUCKET(), VanillaItems::CHEST_MINECART(), VanillaItems::OAK_CHEST_BOAT(),
			VanillaItems::BAMBOO_RAFT(), VanillaItems::SHIELD(), VanillaItems::CROSSBOW(), VanillaItems::ELYTRA(),
			VanillaItems::WOLF_ARMOR(), VanillaItems::CARROT_ON_A_STICK(),
		] as $item){
			self::assertSame(1, $item->getMaxStackSize(), $item->getName());
		}
		self::assertSame(16, VanillaItems::ARMOR_STAND()->getMaxStackSize());
		self::assertSame(64, VanillaItems::ANGLER_POTTERY_SHERD()->getMaxStackSize());
		self::assertSame(64, VanillaItems::ALLAY_SPAWN_EGG()->getMaxStackSize());
	}

	public function testBoatsAndElytra() : void{
		self::assertTrue(VanillaItems::BAMBOO_CHEST_RAFT()->hasChest());
		self::assertSame(BoatType::BAMBOO, VanillaItems::BAMBOO_CHEST_RAFT()->getType());
		self::assertFalse(VanillaItems::CHERRY_BOAT()->hasChest());
		self::assertSame(ArmorInventory::SLOT_CHEST, VanillaItems::ELYTRA()->getArmorSlot());
	}

	public function testMalformedItemTagsDoNotCrash() : void{
		$nbt = VanillaItems::TRIAL_KEY()->nbtSerialize();
		$nbt->setTag("tag", CompoundTag::create()->setString("display", "not a compound"));
		self::assertTrue(Item::safeNbtDeserialize($nbt, "VanillaParityItemsTest")->isNull(), "malformed items are replaced by air");
	}
}
