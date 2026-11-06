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

namespace quark\block\inventory\window;

use quark\crafting\FurnaceType;
use quark\inventory\Inventory;
use quark\player\Player;
use quark\world\Position;

final class FurnaceInventoryWindow extends BlockInventoryWindow{
	public const SLOT_INPUT = 0;
	public const SLOT_FUEL = 1;
	public const SLOT_RESULT = 2;

	public function __construct(
		Player $viewer,
		Inventory $inventory,
		Position $holder,
		private FurnaceType $furnaceType
	){
		parent::__construct($viewer, $inventory, $holder);
	}

	public function getFurnaceType() : FurnaceType{ return $this->furnaceType; }
}
