<?php

declare(strict_types=1);

namespace pocketmine\block\tile\dispenser;

use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;

interface DispensableItem{

	/**
	 * Returns whether the item was dispensed in some way (used to determine which sound
	 * and particle to play).
	 *
	 * @param Position $pos
	 * @param Inventory $inventory
	 * @param int $slot
	 * @param Vector3 $side_pos
	 * @param int $facing
	 * @param Player|null $player
	 * @return bool
	 */
	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool;
}