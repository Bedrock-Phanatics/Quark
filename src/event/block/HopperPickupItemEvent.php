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

namespace quark\event\block;

use quark\block\Block;
use quark\entity\object\ItemEntity;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\inventory\Inventory;
use quark\item\Item;

/**
 * Called when a hopper is about to collect a dropped item.
 */
class HopperPickupItemEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Block $hopper,
		private readonly Inventory $inventory,
		private readonly ItemEntity $itemEntity,
		private readonly Item $item
	){
		parent::__construct($hopper);
	}

	public function getInventory() : Inventory{
		return $this->inventory;
	}

	public function getItemEntity() : ItemEntity{
		return $this->itemEntity;
	}

	/**
	 * Returns the items that will be collected, which may be only part of the dropped stack.
	 */
	public function getItem() : Item{
		return clone $this->item;
	}
}
