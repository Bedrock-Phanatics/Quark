<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\block\inventory\BlockInventory;

interface BlockInventoryWindow extends BlockInventory{

	public function getNetworkWindowType() : int;
}