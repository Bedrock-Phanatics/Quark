<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

interface VariablePowerSource extends PowerSource{

	public function setPowerLevel(int $level) : void;
}