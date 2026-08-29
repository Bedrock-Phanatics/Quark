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

use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\block\utils\redstone\RedstoneBlockUtils;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\Facing;
use quark\player\Player;

class PistonArmCollision extends Transparent implements AnyFacing{
	use RedstoneBlockAccessTrait;

	protected int $facing = Facing::NORTH;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
	}

	public function getFacing() : int{
		return $this->facing;
	}

	public function setFacing(int $facing) : self{
		$this->facing = $facing;
		return $this;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$side = RedstoneBlockUtils::getBlockAtSide($this->position, $this->getPistonSide());
		if($side instanceof Piston && !$this->position->getWorld()->useBreakOn($side->getPosition(), $item, $player, true, $returnedItems)){
			return false;
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function getPistonSide() : int{
		return $this->facing >= 2 ? $this->facing : Facing::opposite($this->facing);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function getSilkTouchDrops(Item $item) : array{
		return [];
	}
}
