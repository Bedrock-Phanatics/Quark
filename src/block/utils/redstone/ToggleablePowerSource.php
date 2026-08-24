<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

interface ToggleablePowerSource extends PowerSource{

	public function switch(bool $state) : void;
}