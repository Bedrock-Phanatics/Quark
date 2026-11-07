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

namespace quark\inventory\transaction;

use quark\inventory\BaseInventory;
use quark\inventory\transaction\action\SlotChangeAction;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\player\InventoryWindow;

/**
 * Records changes made through the normal Inventory API (addItem() and so on) without touching the real inventory,
 * then turns them into SlotChangeActions for a transaction.
 */
final class SlotChangeActionBuilder extends BaseInventory{

	/**
	 * @var \SplFixedArray|(Item|null)[]
	 * @phpstan-var \SplFixedArray<Item|null>
	 */
	private \SplFixedArray $changedSlots;

	public function __construct(
		private InventoryWindow $inventoryWindow
	){
		parent::__construct();
		$this->changedSlots = new \SplFixedArray($this->inventoryWindow->getInventory()->getSize());
	}

	public function getInventoryWindow() : InventoryWindow{
		return $this->inventoryWindow;
	}

	protected function internalSetContents(array $items) : void{
		for($i = 0, $size = $this->getSize(); $i < $size; ++$i){
			if(!isset($items[$i])){
				$this->clear($i);
			}else{
				$this->setItem($i, $items[$i]);
			}
		}
	}

	protected function internalSetItem(int $index, Item $item) : void{
		//setting a slot back to what it was must drop the earlier change, or getItem() would keep returning it
		$this->changedSlots[$index] = $item->equalsExact($this->inventoryWindow->getInventory()->getItem($index)) ?
			null :
			($item->isNull() ? VanillaItems::AIR() : clone $item);
	}

	public function getSize() : int{
		return $this->changedSlots->getSize();
	}

	public function getItem(int $index) : Item{
		return $this->changedSlots[$index] !== null ? clone $this->changedSlots[$index] : $this->inventoryWindow->getInventory()->getItem($index);
	}

	public function getContents(bool $includeEmpty = false) : array{
		$contents = $this->inventoryWindow->getInventory()->getContents($includeEmpty);
		foreach($this->changedSlots as $index => $item){
			if($item !== null){
				if($includeEmpty || !$item->isNull()){
					$contents[$index] = clone $item;
				}else{
					unset($contents[$index]);
				}
			}
		}
		return $contents;
	}

	public function getMatchingItemCount(int $slot, Item $test, bool $checkTags) : int{
		$slotItem = $this->changedSlots[$slot];
		if($slotItem !== null){
			return $slotItem->equals($test, true, $checkTags) ? $slotItem->getCount() : 0;
		}
		return $this->inventoryWindow->getInventory()->getMatchingItemCount($slot, $test, $checkTags);
	}

	public function isSlotEmpty(int $index) : bool{
		$slotItem = $this->changedSlots[$index];
		return $slotItem !== null ? $slotItem->isNull() : $this->inventoryWindow->getInventory()->isSlotEmpty($index);
	}

	/**
	 * @return SlotChangeAction[]
	 * @phpstan-return list<SlotChangeAction>
	 */
	public function generateActions() : array{
		$result = [];
		$inventory = $this->inventoryWindow->getInventory();
		foreach($this->changedSlots as $index => $newItem){
			if($newItem !== null){
				$oldItem = $inventory->getItem($index);
				if(!$newItem->equalsExact($oldItem)){
					$result[] = new SlotChangeAction($this->inventoryWindow, $index, $oldItem, $newItem);
				}
			}
		}
		return $result;
	}

	//a builder is a scratch copy, it never has windows of its own
	public function getViewers() : array{
		return [];
	}

	public function removeAllWindows() : void{

	}

	public function onOpen(InventoryWindow $window) : void{

	}

	public function onClose(InventoryWindow $window) : void{

	}
}
