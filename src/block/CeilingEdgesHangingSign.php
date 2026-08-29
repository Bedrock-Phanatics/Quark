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

use quark\block\utils\HorizontalFacing;
use quark\block\utils\HorizontalFacingTrait;
use quark\block\utils\SupportType;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\world\BlockTransaction;

final class CeilingEdgesHangingSign extends BaseSign implements HorizontalFacing{
	use HorizontalFacingTrait;

	protected function getSupportingFace() : int{
		return Facing::UP;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($face !== Facing::DOWN){
			return false;
		}
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		if(!$this->canBeSupportedAt($blockReplace)){
			return false;
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::UP);
		return
			$supportBlock->getSupportType(Facing::DOWN) === SupportType::FULL ||
			(($supportBlock instanceof WallHangingSign || $supportBlock instanceof CeilingEdgesHangingSign) && Facing::axis($supportBlock->getFacing()) === Facing::axis($this->facing));
	}

	protected function getFacingDegrees() : float{
		return match($this->facing){
			Facing::SOUTH => 0,
			Facing::WEST => 90,
			Facing::NORTH => 180,
			Facing::EAST => 270,
			default => throw new AssumptionFailedError("Invalid facing direction: " . $this->facing),
		};
	}
}
