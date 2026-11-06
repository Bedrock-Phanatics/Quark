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

namespace quark\block;

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use quark\block\inventory\window\EnchantingTableInventoryWindow;
use quark\block\utils\MenuAccessor;
use quark\block\utils\MenuAccessorTrait;
use quark\block\utils\SupportType;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\Position;

class EnchantingTable extends Transparent implements MenuAccessor{
	use MenuAccessorTrait;

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 0.25)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	protected function newMenu(Player $player, Position $position) : InventoryWindow{
		return new EnchantingTableInventoryWindow($player, $position);
	}
}
