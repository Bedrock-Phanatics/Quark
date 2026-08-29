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

use quark\block\utils\AnyFacing;
use quark\block\utils\AnyFacingTrait;
use quark\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

class EndRod extends Flowable implements AnyFacing{
	use AnyFacingTrait;

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face;
		if($blockClicked instanceof EndRod && $blockClicked->facing === $this->facing){
			$this->facing = Facing::opposite($face);
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function isSolid() : bool{
		return true;
	}

	public function getLightLevel() : int{
		return 14;
	}

	protected function recalculateCollisionBoxes() : array{
		$myAxis = Facing::axis($this->facing);

		$bb = AxisAlignedBB::one();
		foreach([Axis::Y, Axis::Z, Axis::X] as $axis){
			if($axis === $myAxis){
				continue;
			}
			$bb->squash($axis, 6 / 16);
		}
		return [$bb];
	}
}
