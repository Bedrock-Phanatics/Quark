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

namespace quark\block\tile\comparator;

use quark\block\RedstoneComparator;
use quark\inventory\Inventory;
use quark\inventory\InventoryListener;
use quark\item\Item;
use quark\world\Position;

/**
 * Tells a comparator when the container it reads from changes. Removes itself once the comparator is gone.
 */
final class ComparatorInventoryListener implements InventoryListener{

	private function __construct(
		private readonly Position $comparator
	){}

	/**
	 * Starts notifying the comparator at the given position about changes to the inventory, if it isn't already.
	 */
	public static function attach(Inventory $inventory, Position $comparator) : void{
		foreach($inventory->getListeners() as $listener){
			if($listener instanceof self && $listener->comparator->getWorld() === $comparator->getWorld() && $listener->comparator->equals($comparator)){
				return;
			}
		}
		$listener = new self(Position::fromObject($comparator->floor(), $comparator->getWorld()));
		$inventory->getListeners()->add($listener);
		$listener->update($inventory);
	}

	public function update(Inventory $inventory) : void{
		$comparator = $this->comparator;
		$block = $comparator->isValid() ? $comparator->getWorld()->getBlockAt($comparator->getFloorX(), $comparator->getFloorY(), $comparator->getFloorZ()) : null;
		if(!$block instanceof RedstoneComparator){
			$inventory->getListeners()->remove($this);
			return;
		}
		$block->onContainerInputChange();
	}

	public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
		$this->update($inventory);
	}

	public function onContentChange(Inventory $inventory, array $oldContents) : void{
		$this->update($inventory);
	}
}
