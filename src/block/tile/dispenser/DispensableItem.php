<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\block\tile\dispenser;

use quark\inventory\Inventory;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\Position;

interface DispensableItem{

	/**
	 * Returns whether the item was dispensed in some way (used to determine which sound
	 * and particle to play).
	 */
	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool;
}
