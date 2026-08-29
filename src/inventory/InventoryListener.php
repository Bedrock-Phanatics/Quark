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

namespace quark\inventory;

use quark\item\Item;

/**
 * Classes implementing this interface can be injected into inventories to receive notifications when content changes
 * occur.
 * @see CallbackInventoryListener for a closure-based listener
 * @see Inventory::getListeners()
 */
interface InventoryListener{

	public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void;

	/**
	 * @param Item[] $oldContents
	 */
	public function onContentChange(Inventory $inventory, array $oldContents) : void;
}
