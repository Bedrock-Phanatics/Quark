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

use pocketmine\block\Liquid;
use pocketmine\block\VanillaBlocks;
use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;

class BucketDispensableItem extends DropDispensableItem{

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$world = $pos->getWorld();
		$block = $world->getBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z);
		if($block instanceof Liquid && $block->isSource()){
			$world->setBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z, VanillaBlocks::AIR());
			$item = $inventory->getItem($slot);
			$item->pop();
			$inventory->setItem($slot, $item);
			foreach($inventory->addItem($block->getFlowingForm()->asItem()) as $drop){
				$world->dropItem($side_pos->add(0.3, 0.3, 0.3), $drop);
			}
			return true;
		}

		return parent::dispense($pos, $inventory, $slot, $side_pos, $facing, $player);
	}
}
