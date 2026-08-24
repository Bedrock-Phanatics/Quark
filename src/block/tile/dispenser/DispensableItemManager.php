<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block\tile\dispenser;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Egg;
use pocketmine\entity\projectile\Snowball;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\Armor;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;

final class DispensableItemManager{

	private static DispensableItem $default;

	/** @var DispensableItem[] Registered behavior for each supported item type. */
	private static array $items = [];

	public static function init() : void{
		self::register(VanillaItems::ARROW(), new ProjectileDispensableItem(static function(Location $location, Item $item, ?Player $player) : Arrow{ return new Arrow($location, $player, false); }));
		self::register(VanillaItems::EGG(), new ProjectileDispensableItem(static function(Location $location, Item $item, ?Player $player) : Egg{ return new Egg($location, $player); }));
		self::register(VanillaItems::SNOWBALL(), new ProjectileDispensableItem(static function(Location $location, Item $item, ?Player $player) : Snowball{ return new Snowball($location, $player); }));
		self::register(VanillaItems::LAVA_BUCKET(), new LiquidBucketDispensableItem());
		self::register(VanillaItems::WATER_BUCKET(), new LiquidBucketDispensableItem());
		self::register(VanillaItems::BUCKET(), new BucketDispensableItem());
		self::register(VanillaBlocks::TNT()->asItem(), new TntDispensableItem());

		self::register(VanillaBlocks::MOB_HEAD()->asItem(), new ArmorDispensableItem(ArmorInventory::SLOT_HEAD));
		self::register(VanillaBlocks::CARVED_PUMPKIN()->asItem(), new ArmorDispensableItem(ArmorInventory::SLOT_HEAD));
		foreach(VanillaItems::getAll() as $item){
			if($item instanceof Armor){
				self::register($item, new ArmorDispensableItem($item->getArmorSlot()));
			}
		}

		self::registerFallback(new DropDispensableItem());
	}

	public static function register(Item $item, DispensableItem $dispensable) : void{
		self::$items[$item->getStateId()] = $dispensable;
	}

	public static function registerFallback(DispensableItem $item) : void{
		self::$default = $item;
	}

	public static function get(Item $item) : DispensableItem{
		return self::$items[$item->getStateId()] ?? self::$default;
	}
}
