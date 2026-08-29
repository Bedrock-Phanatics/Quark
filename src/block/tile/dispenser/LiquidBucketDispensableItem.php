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

use quark\inventory\Inventory;
use quark\item\LiquidBucket;
use quark\item\VanillaItems;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\Position;

class LiquidBucketDispensableItem extends DropDispensableItem{

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$world = $pos->getWorld();
		if($world->getBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z)->canBeReplaced()){
			/** @var LiquidBucket $item */
			$item = $inventory->getItem($slot);

			$liquid = $item->getLiquid();
			$item->pop();
			//Commit the inventory mutation before changing the world. If an unexpected
			//failure occurs, losing one item is preferable to duplicating liquid.
			$inventory->setItem($slot, $item);
			$world->setBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z, $liquid);
			foreach($inventory->addItem(VanillaItems::BUCKET()) as $drop){
				$world->dropItem($side_pos->add(0.3, 0.3, 0.3), $drop);
			}
			return true;
		}

		return parent::dispense($pos, $inventory, $slot, $side_pos, $facing, $player);
	}
}
