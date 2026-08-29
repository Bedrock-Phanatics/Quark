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

namespace quark\block;

use quark\block\utils\BlockEventHelper;
use quark\block\utils\DirtType;
use quark\item\Item;
use pocketmine\math\Facing;
use function mt_rand;

class Mycelium extends Opaque{

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaBlocks::DIRT()->asItem()
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		//TODO: light levels
		$x = mt_rand($this->position->x - 1, $this->position->x + 1);
		$y = mt_rand($this->position->y - 2, $this->position->y + 2);
		$z = mt_rand($this->position->z - 1, $this->position->z + 1);
		$world = $this->position->getWorld();
		$block = $world->getBlockAt($x, $y, $z);
		if($block instanceof Dirt && $block->getDirtType() === DirtType::NORMAL){
			if($block->getSide(Facing::UP) instanceof Transparent){
				BlockEventHelper::spread($block, VanillaBlocks::MYCELIUM(), $this);
			}
		}
	}
}
