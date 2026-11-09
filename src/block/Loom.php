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

use pocketmine\math\Facing;
use quark\block\inventory\window\LoomInventoryWindow;
use quark\block\utils\FacesOppositePlacingPlayerTrait;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\MenuAccessor;
use quark\block\utils\MenuAccessorTrait;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\Position;

final class Loom extends Opaque implements MenuAccessor, HorizontalFacing{
	use MenuAccessorTrait;
	use FacesOppositePlacingPlayerTrait;

	public const int DEFAULT_FACING = Facing::SOUTH;

	protected function newMenu(Player $player, Position $position) : InventoryWindow{
		return new LoomInventoryWindow($player, $position);
	}
}
