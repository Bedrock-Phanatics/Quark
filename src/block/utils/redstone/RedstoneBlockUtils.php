<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use Generator;
use pocketmine\block\Block;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\inventory\BaseInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\Position;
use pocketmine\world\World;
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
	 * @param Inventory $inventory
	 * @param bool $include_empty
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