<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

interface Movable{

	public function canBeMoved() : bool;
}