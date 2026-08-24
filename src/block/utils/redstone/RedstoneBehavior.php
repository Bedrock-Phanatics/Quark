<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use pocketmine\block\utils\redstone\RedstoneBlockAccessTrait;

use pocketmine\block\Redstone as VanillaRedstone;
use pocketmine\block\utils\redstone\PowerSource;

trait RedstoneBehavior{
	use RedstoneBlockAccessTrait;

	public function getPowerLevel() : int{
		return 15;
	}

	public function getOutputPowerLevel() : int{
		return $this->getPowerLevel();
	}

	public function canPower(int $side) : bool{
		return true;
	}

	public function canStronglyPower(int $side) : bool{
		return false;
	}
}