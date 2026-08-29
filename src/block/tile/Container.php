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

namespace quark\block\tile;

use quark\inventory\Inventory;
use quark\inventory\InventoryHolder;

interface Container extends InventoryHolder{
	public const TAG_ITEMS = "Items";
	public const TAG_LOCK = "Lock";

	public function getRealInventory() : Inventory;

	/**
	 * Returns whether this container can be opened by an item with the given custom name.
	 */
	public function canOpenWith(string $key) : bool;
}
