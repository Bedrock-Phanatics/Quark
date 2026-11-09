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

namespace quark\block\utils\redstone;

use Generator;
use quark\block\Block;
use quark\block\tile\TileFactory;
use quark\block\VanillaBlocks;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\math\Facing;
use quark\math\Vector3;
use quark\world\Position;
use quark\world\World;
use function count;

final class RedstoneBlockUtils{

	private const int READ_CACHE_LIMIT = 32768;

	/** @var array<int, Block>|null */
	private static ?array $readCache = null;
	private static ?World $readCacheWorld = null;

	/**
	 * Shares side lookups in the world until endReadCache(), which is only safe while nothing writes to it.
	 * The world's own block cache is too small for large circuits, so without this most lookups clone a fresh block.
	 * Returns false when a cache is already active, in which case the caller must not end it.
	 */
	public static function beginReadCache(World $world) : bool{
		if(self::$readCache !== null){
			return false;
		}
		self::$readCache = [];
		self::$readCacheWorld = $world;
		return true;
	}

	public static function endReadCache() : void{
		self::$readCache = null;
		self::$readCacheWorld = null;
	}

	public static function getBlockAtSide(Position $position, int $side, int $step = 1) : Block{
		[$offsetX, $offsetY, $offsetZ] = Facing::OFFSET[$side];
		$x = (int) $position->x + ($offsetX * $step);
		$y = (int) $position->y + ($offsetY * $step);
		$z = (int) $position->z + ($offsetZ * $step);
		$world = $position->getWorld();
		if(self::$readCache === null || self::$readCacheWorld !== $world){
			return $world->getBlockAt($x, $y, $z);
		}
		$hash = World::blockHash($x, $y, $z);
		if(isset(self::$readCache[$hash])){
			return self::$readCache[$hash];
		}
		if(count(self::$readCache) >= self::READ_CACHE_LIMIT){
			return $world->getBlockAt($x, $y, $z);
		}
		//kept out of the world's cache, which would otherwise keep trimming itself
		return self::$readCache[$hash] = $world->getBlockAt($x, $y, $z, addToCache: false);
	}

	/**
	 * @template T
	 * @param list<T> $array
	 * @return Generator<T>
	 */
	public static function reverse(array $array) : Generator{
		for($i = count($array) - 1; $i >= 0; $i--){
			yield $array[$i];
		}
	}

	/**
	 * Returns the inventory's items without cloning them, so they must not be modified.
	 *
	 * @return array<int, Item>
	 */
	public static function fastReadOnlyInventoryContents(Inventory $inventory, bool $include_empty = false) : array{
		if(!$inventory instanceof SimpleInventory){
			return $inventory->getContents($include_empty);
		}
		static $inventoryContentsReader = null;
		if($inventoryContentsReader === null){
			$air = VanillaItems::AIR();
			$inventoryContentsReader = (static function($inventory, bool $include_empty) use($air){
				$contents = [];
				foreach($inventory->slots as $index => $slot){
					if($slot !== null){
						$contents[$index] = $slot;
					}elseif($include_empty){
						$contents[$index] = $air;
					}
				}
				return $contents;
			})->bindTo(null, SimpleInventory::class);
		}
		return $inventoryContentsReader($inventory, $include_empty);
	}

	public static function moveBlockAndTile(World $world, Vector3 $source, Vector3 $destination, bool $update = true) : void{
		$sourceX = (int) $source->x;
		$sourceY = (int) $source->y;
		$sourceZ = (int) $source->z;
		$destinationX = (int) $destination->x;
		$destinationY = (int) $destination->y;
		$destinationZ = (int) $destination->z;
		$block = $world->getBlockAt($sourceX, $sourceY, $sourceZ);
		$tileNbt = $world->getTileAt($sourceX, $sourceY, $sourceZ)?->saveNBT();
		$world->setBlockAt($sourceX, $sourceY, $sourceZ, VanillaBlocks::AIR(), $update);
		if($tileNbt !== null){
			$world->setBlockAt($destinationX, $destinationY, $destinationZ, $block, $update);
			$existingTile = $world->getTileAt($destinationX, $destinationY, $destinationZ);
			$existingTile?->close();
			$movedTile = TileFactory::getInstance()->createFromData($tileNbt);
			if($movedTile !== null){
				$world->addTile($movedTile, new Vector3($destinationX, $destinationY, $destinationZ));
			}
		}else{
			$world->useBreakOn($destination);
			$world->setBlockAt($destinationX, $destinationY, $destinationZ, $block, $update);
		}
	}
}
