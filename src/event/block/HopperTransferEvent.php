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
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\inventory\Inventory;
use quark\item\Item;

/**
 * Called when a hopper is about to move items from one inventory into another, either pushing out of its own
 * inventory or pulling from the container above it.
 */
class HopperTransferEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Block $hopper,
		private readonly Inventory $source,
		private readonly int $sourceSlot,
		private readonly Inventory $target,
		private readonly Item $item
	){
		parent::__construct($hopper);
	}

	public function getSource() : Inventory{
		return $this->source;
	}

	public function getSourceSlot() : int{
		return $this->sourceSlot;
	}

	public function getTarget() : Inventory{
		return $this->target;
	}

	/**
	 * Returns the items that will move. Fewer may move if the inventories change before the transfer happens.
	 */
	public function getItem() : Item{
		return clone $this->item;
	}
}
