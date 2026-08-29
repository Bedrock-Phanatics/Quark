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
use quark\block\utils\Colored;
use quark\block\utils\ColoredTrait;
use quark\block\utils\Fallable;
use quark\block\utils\FallableTrait;
use pocketmine\math\Facing;

class ConcretePowder extends Opaque implements Fallable, Colored{
	use ColoredTrait;
	use FallableTrait {
		onNearbyBlockChange as protected startFalling;
	}

	public function onNearbyBlockChange() : void{
		if(($water = $this->getAdjacentWater()) !== null){
			BlockEventHelper::form($this, VanillaBlocks::CONCRETE()->setColor($this->color), $water);
		}else{
			$this->startFalling();
		}
	}

	public function tickFalling() : ?Block{
		if($this->getAdjacentWater() === null){
			return null;
		}
		return VanillaBlocks::CONCRETE()->setColor($this->color);
	}

	private function getAdjacentWater() : ?Water{
		foreach(Facing::ALL as $i){
			if($i === Facing::DOWN){
				continue;
			}
			$block = $this->getSide($i);
			if($block instanceof Water){
				return $block;
			}
		}

		return null;
	}
}
