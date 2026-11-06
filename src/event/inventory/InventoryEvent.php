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

/**
 * Inventory related events
 */
namespace quark\event\inventory;

use quark\event\Event;
use quark\inventory\Inventory;
use quark\player\InventoryWindow;
use quark\player\Player;

abstract class InventoryEvent extends Event{
	public function __construct(
		protected InventoryWindow $window
	){}

	/**
	 * Returns the window involved, which carries context like the block it was opened from.
	 */
	public function getWindow() : InventoryWindow{
		return $this->window;
	}

	public function getInventory() : Inventory{
		return $this->window->getInventory();
	}

	/**
	 * @return Player[]
	 */
	public function getViewers() : array{
		return $this->window->getInventory()->getViewers();
	}
}
