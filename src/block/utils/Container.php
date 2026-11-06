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

namespace quark\block\utils;

use quark\inventory\Inventory;

/**
 * Blocks that store items, like chests and furnaces. See ContainerTrait for the default implementation.
 */
interface Container extends MenuAccessor{

	/**
	 * Returns whether an item with the given custom name can open the container, if it's locked.
	 */
	public function canOpenWith(string $key) : bool;

	/**
	 * Returns the stored contents, or null if the block's tile is missing or the wrong type, which can happen with old
	 * worlds or blocks placed incorrectly by plugins.
	 */
	public function getInventory() : ?Inventory;
}
