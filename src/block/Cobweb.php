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

use quark\entity\Entity;
use quark\item\Item;
use quark\item\VanillaItems;

class Cobweb extends Flowable{

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if(($item->getBlockToolType() & BlockToolType::SHEARS) !== 0){
			return [$this->asItem()];
		}
		return [
			VanillaItems::STRING()
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function blocksDirectSkyLight() : bool{
		return true;
	}
}
