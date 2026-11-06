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

use quark\crafting\CraftingGrid;
use quark\player\Player;
use quark\player\TemporaryInventoryWindow;
use quark\world\Position;

final class CraftingTableInventoryWindow extends BlockInventoryWindow implements TemporaryInventoryWindow{
	private CraftingGrid $grid;

	public function __construct(Player $viewer, Position $holder){
		$this->grid = new CraftingGrid(CraftingGrid::SIZE_BIG);
		parent::__construct($viewer, $this->grid, $holder);
	}

	public function getCraftingGrid() : CraftingGrid{ return $this->grid; }
}
