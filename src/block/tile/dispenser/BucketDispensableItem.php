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

use quark\block\BlockTypeIds;
use quark\block\Liquid;
use quark\block\VanillaBlocks;
use quark\inventory\Inventory;
use quark\item\VanillaItems;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\Position;

class BucketDispensableItem extends DropDispensableItem{

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$world = $pos->getWorld();
		$block = $world->getBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z);
		$filled = $block instanceof Liquid && $block->isSource() ? match($block->getTypeId()){
			BlockTypeIds::WATER => VanillaItems::WATER_BUCKET(),
			BlockTypeIds::LAVA => VanillaItems::LAVA_BUCKET(),
			default => null
		} : null;
		if($filled === null){
			return parent::dispense($pos, $inventory, $slot, $side_pos, $facing, $player);
		}

		//take the bucket before the liquid, so a failure can't leave both
		$item = $inventory->getItem($slot);
		$item->pop();
		$inventory->setItem($slot, $item);
		$world->setBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z, VanillaBlocks::AIR());
		foreach($inventory->addItem($filled) as $drop){
			$world->dropItem($side_pos->add(0.3, 0.3, 0.3), $drop);
		}
		return true;
	}
}
