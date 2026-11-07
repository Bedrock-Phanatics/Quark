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

namespace quark\player;

use quark\inventory\Inventory;

/**
 * Window on one of the player's own inventories. These stay open for as long as the player is online.
 */
final class PlayerInventoryWindow extends InventoryWindow{

	public function __construct(
		Player $viewer,
		Inventory $inventory,
		private readonly PlayerInventoryType $type
	){
		parent::__construct($viewer, $inventory);
	}

	public function getType() : PlayerInventoryType{ return $this->type; }
}
