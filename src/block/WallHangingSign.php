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
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\world\BlockTransaction;

final class WallHangingSign extends BaseSign implements HorizontalFacing{
	use HorizontalFacingTrait;

	protected function getSupportingFace() : int{
		return Facing::rotateY($this->facing, clockwise: true);
	}

	public function onNearbyBlockChange() : void{
		//NOOP - disable default self-destruct behaviour
	}

	protected function recalculateCollisionBoxes() : array{
		//only the cross bar is collidable
		return [AxisAlignedBB::one()->trim(Facing::DOWN, 7 / 8)->squash(Facing::axis($this->facing), 3 / 4)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player === null){
			return false;
		}
		$attachFace = Facing::axis($face) === Axis::Y ? Facing::rotateY($player->getHorizontalFacing(), clockwise: true) : $face;

		if($this->canBeSupportedAt($blockReplace->getSide($attachFace), $attachFace)){
			$direction = $attachFace;
		}elseif($this->canBeSupportedAt($blockReplace->getSide($opposite = Facing::opposite($attachFace)), $opposite)){
			$direction = $opposite;
		}else{
			return false;
		}

		$this->facing = Facing::rotateY(Facing::opposite($direction), clockwise: true);
		//the front should always face the player if possible
		if($this->facing === $player->getHorizontalFacing()){
			$this->facing = Facing::opposite($this->facing);
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function canBeSupportedAt(Block $block, int $face) : bool{
		return
			($block instanceof WallHangingSign && Facing::axis(Facing::rotateY($block->getFacing(), clockwise: true)) === Facing::axis($face)) ||
			$block->getSupportType(Facing::opposite($face)) === SupportType::FULL;
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
