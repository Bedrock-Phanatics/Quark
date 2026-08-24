<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use pocketmine\block\utils\redstone\RedstoneBlockUtils;

trait RedstoneBlockAccessTrait{

	public function getSide(int $side, int $step = 1){
		return RedstoneBlockUtils::getBlockAtSide($this->position, $side, $step);
	}
}