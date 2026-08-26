<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\AnyFacing;

use pocketmine\block\utils\redstone\RedstoneBlockAccessTrait;
use pocketmine\block\utils\redstone\RedstoneBlockUtils;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\player\Player;

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
