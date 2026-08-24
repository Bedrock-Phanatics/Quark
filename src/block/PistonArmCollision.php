<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\redstone\RedstoneBlockAccessTrait;

use pocketmine\block\Transparent;
use pocketmine\block\utils\AnyFacing;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\block\utils\redstone\RedstoneBlockUtils;

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