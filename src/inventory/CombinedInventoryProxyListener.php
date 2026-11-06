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
 * @internal
 * Relays backing inventory changes to a proxy without keeping it alive, since the backing inventories usually outlive it.
 */
final class CombinedInventoryProxyListener implements InventoryListener{

	/**
	 * @phpstan-param \WeakReference<CombinedInventoryProxy> $proxy
	 */
	public function __construct(
		private readonly \WeakReference $proxy
	){}

	public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
		$this->proxy->get()?->onBackingSlotChange($inventory, $slot, $oldItem);
	}

	public function onContentChange(Inventory $inventory, array $oldContents) : void{
		$this->proxy->get()?->onBackingContentChange($inventory, $oldContents);
	}
}
