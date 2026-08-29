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
use quark\block\utils\CoralMaterial;
use quark\block\utils\CoralTypeTrait;
use quark\item\Item;
use function mt_rand;

final class CoralBlock extends Opaque implements CoralMaterial{
	use CoralTypeTrait;

	public function onNearbyBlockChange() : void{
		if(!$this->dead){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, mt_rand(40, 200));
		}
	}

	public function onScheduledUpdate() : void{
		if(!$this->dead){
			$world = $this->position->getWorld();

			$hasWater = false;
			foreach($this->position->sides() as $vector3){
				if($world->getBlock($vector3) instanceof Water){
					$hasWater = true;
					break;
				}
			}
			if(!$hasWater){
				BlockEventHelper::die($this, (clone $this)->setDead(true));
			}
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [(clone $this)->setDead(true)->asItem()];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}
}
