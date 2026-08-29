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

use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

class RedMushroom extends Flowable{

	public function ticksRandomly() : bool{
		return true;
	}

	public function onNearbyBlockChange() : void{
		if($this->getSide(Facing::DOWN)->isTransparent()){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$down = $this->getSide(Facing::DOWN);
		$position = $this->position;
		$lightLevel = $position->getWorld()->getFullLightAt($position->x, $position->y, $position->z);
		$downId = $down->getTypeId();
		if(($lightLevel <= 12 && !$down->isTransparent()) || $downId === BlockTypeIds::MYCELIUM || $downId === BlockTypeIds::PODZOL || $down->hasTypeTag(BlockTypeTags::NYLIUM)){
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}

		return false;
	}
}
