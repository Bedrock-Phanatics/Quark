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
use quark\block\tile\Tile;
use quark\block\tile\TileFactory;
use quark\block\VanillaBlocks;
use quark\inventory\BaseInventory;
use quark\inventory\Inventory;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\world\Position;
use quark\world\World;
use function count;

final class RedstoneBlockUtils{

	public static function getBlockAtSide(Position $position, int $side, int $step = 1) : Block{
		[$offsetX, $offsetY, $offsetZ] = Facing::OFFSET[$side];
		$x = (int) $position->x;
		$y = (int) $position->y;
		$z = (int) $position->z;
		return $position->getWorld()->getBlockAt(
			$x + ($offsetX * $step),
			$y + ($offsetY * $step),
			$z + ($offsetZ * $step)
		);
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
	 * @return array<int, Item>
	 */
	public static function fastReadOnlyInventoryContents(Inventory $inventory, bool $include_empty = false) : array{
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
			})->bindTo(null, BaseInventory::class);
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
			if($existingTile !== null){
				$world->removeTile($existingTile);
			}
			$tileNbt->setInt(Tile::TAG_X, $destinationX);
			$tileNbt->setInt(Tile::TAG_Y, $destinationY);
			$tileNbt->setInt(Tile::TAG_Z, $destinationZ);
			$movedTile = TileFactory::getInstance()->createFromData($world, $tileNbt);
			if($movedTile !== null){
				$world->addTile($movedTile);
			}
		}else{
			$world->useBreakOn($destination);
			$world->setBlockAt($destinationX, $destinationY, $destinationZ, $block, $update);
		}
	}
}
