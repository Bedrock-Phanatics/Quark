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
 * One player's view of an inventory. It carries the context the inventory itself doesn't have, like the block the
 * player opened it from, and is discarded when the player closes it. Create a new window every time you open one.
 */
abstract class InventoryWindow{

	public function __construct(
		protected Player $viewer,
		protected Inventory $inventory
	){}

	public function getViewer() : Player{
		return $this->viewer;
	}

	public function getInventory() : Inventory{
		return $this->inventory;
	}

	/**
	 * @internal Called by the Player when the window is opened.
	 */
	public function onOpen() : void{
		$this->inventory->onOpen($this);
	}

	/**
	 * @internal Called by the Player when the window is closed.
	 */
	public function onClose() : void{
		$this->inventory->onClose($this);
	}
}
