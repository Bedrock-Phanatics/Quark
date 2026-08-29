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

namespace quark\block\tile\dispenser;

use quark\block\VanillaBlocks;
use quark\entity\Location;
use quark\entity\projectile\Arrow;
use quark\entity\projectile\Egg;
use quark\entity\projectile\Snowball;
use quark\inventory\ArmorInventory;
use quark\item\Armor;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\player\Player;

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
