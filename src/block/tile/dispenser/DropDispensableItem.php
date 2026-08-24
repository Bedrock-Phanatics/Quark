<?php

declare(strict_types=1);

namespace pocketmine\block\tile\dispenser;

use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;

class DropDispensableItem implements DispensableItem{

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$item = $inventory->getItem($slot);
		$dispensed = $item->pop();
		$inventory->setItem($slot, $item);
		$pos->getWorld()->dropItem($side_pos->add(0.3, 0.3, 0.3), $dispensed, (new Vector3(0.0, 0.0, 0.0))->getSide($facing)->multiply(0.4));
		return true;
	}
}