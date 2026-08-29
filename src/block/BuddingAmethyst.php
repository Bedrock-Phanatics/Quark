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

use quark\block\utils\AmethystTrait;
use quark\block\utils\BlockEventHelper;
use quark\item\Item;
use pocketmine\math\Facing;
use function array_rand;
use function mt_rand;

final class BuddingAmethyst extends Opaque{
	use AmethystTrait;

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		if(mt_rand(1, 5) === 1){
			$face = Facing::ALL[array_rand(Facing::ALL)];

			$adjacent = $this->getSide($face);

			if(!$this->position->getWorld()->isInWorld($adjacent->position->getFloorX(), $adjacent->position->getFloorY(), $adjacent->position->getFloorZ())){
				return;
			}

			//TODO: amethyst buds can spawn in water - we need waterlogging support for this

			$newStage = null;

			if($adjacent->getTypeId() === BlockTypeIds::AIR){
				$newStage = AmethystCluster::STAGE_SMALL_BUD;
			}elseif(
				$adjacent->getTypeId() === BlockTypeIds::AMETHYST_CLUSTER &&
				$adjacent instanceof AmethystCluster &&
				$adjacent->getStage() < AmethystCluster::STAGE_CLUSTER &&
				$adjacent->getFacing() === $face
			){
				$newStage = $adjacent->getStage() + 1;
			}
			if($newStage !== null){
				BlockEventHelper::grow($adjacent, VanillaBlocks::AMETHYST_CLUSTER()->setStage($newStage)->setFacing($face), null);
			}
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}
}
