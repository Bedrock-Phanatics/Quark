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
use quark\item\VanillaItems;
use quark\player\InventoryWindow;
use function array_fill;
use function array_fill_keys;
use function array_keys;
use function count;
use function spl_object_id;

/**
 * Presents several inventories as one, with their slots laid out one after another. Double chests use this, but any
 * inventories can be combined. Proxies are cheap and hold no items themselves, so create one whenever you need it.
 */
final class CombinedInventoryProxy extends BaseInventory{
	/**
	 * @var Inventory[]
	 * @phpstan-var array<int, Inventory>
	 */
	private array $backingInventories = [];
	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $offsets = [];
	/**
	 * @var Inventory[] combined slot => backing inventory
	 * @phpstan-var array<int, Inventory>
	 */
	private array $slotOwners = [];
	/**
	 * @var int[] combined slot => first combined slot of its backing inventory
	 * @phpstan-var array<int, int>
	 */
	private array $slotOffsets = [];
	private readonly int $size;

	private InventoryListener $backingInventoryListener;

	/**
	 * @param Inventory[] $backingInventories
	 * @phpstan-param list<Inventory> $backingInventories
	 */
	public function __construct(array $backingInventories){
		parent::__construct();
		if(count($backingInventories) === 0){
			throw new \InvalidArgumentException("At least one inventory is required");
		}
		$size = 0;
		foreach($backingInventories as $backingInventory){
			foreach($backingInventory instanceof self ? $backingInventory->backingInventories : [$backingInventory] as $inventory){
				$id = spl_object_id($inventory);
				if(isset($this->backingInventories[$id])){
					throw new \InvalidArgumentException("The same inventory can't be combined more than once");
				}
				$this->backingInventories[$id] = $inventory;
				$this->offsets[$id] = $size;
				$slots = $inventory->getSize();
				if($slots > 0){
					$this->slotOwners += array_fill($size, $slots, $inventory);
					$this->slotOffsets += array_fill($size, $slots, $size);
				}
				$size += $slots;
			}
		}
		$this->size = $size;

		$this->backingInventoryListener = new CombinedInventoryProxyListener(\WeakReference::create($this));
		foreach($this->backingInventories as $inventory){
			$inventory->getListeners()->add($this->backingInventoryListener);
		}
	}

	public function __destruct(){
		foreach($this->backingInventories as $inventory){
			$inventory->getListeners()->remove($this->backingInventoryListener);
		}
	}

	/**
	 * @return Inventory[]
	 * @phpstan-return list<Inventory>
	 */
	public function getBackingInventories() : array{
		$result = [];
		foreach($this->backingInventories as $inventory){
			$result[] = $inventory;
		}
		return $result;
	}

	/**
	 * @internal
	 */
	public function onBackingSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
		$this->onSlotChange($this->offsets[spl_object_id($inventory)] + $slot, $oldItem);
	}

	/**
	 * @internal
	 *
	 * @param Item[] $oldContents
	 * @phpstan-param array<int, Item> $oldContents
	 */
	public function onBackingContentChange(Inventory $inventory, array $oldContents) : void{
		if(count($this->backingInventories) === 1){
			$this->onContentChange($oldContents);
			return;
		}
		$offset = $this->offsets[spl_object_id($inventory)];
		for($slot = 0, $limit = $inventory->getSize(); $slot < $limit; $slot++){
			$this->onSlotChange($offset + $slot, $oldContents[$slot] ?? VanillaItems::AIR());
		}
	}

	/**
	 * Returns the backing inventory holding the combined slot, writing its own slot number to $localSlot. This runs for
	 * every slot access, so it avoids allocating a result array.
	 *
	 * @param-out int $localSlot
	 */
	public function getBackingInventory(int $slot, ?int &$localSlot) : Inventory{
		$inventory = $this->slotOwners[$slot] ?? throw new \InvalidArgumentException("Invalid combined inventory slot $slot");
		$localSlot = $slot - $this->slotOffsets[$slot];
		return $inventory;
	}

	protected function internalSetItem(int $index, Item $item) : void{
		$inventory = $this->getBackingInventory($index, $localSlot);
		$inventory->setItem($localSlot, $item);
	}

	public function setItem(int $index, Item $item) : void{
		$this->internalSetItem($index, $item);
	}

	protected function internalSetContents(array $items) : void{
		$contentsByInventory = array_fill_keys(array_keys($this->backingInventories), []);
		foreach($items as $i => $item){
			$inventory = $this->getBackingInventory($i, $localSlot);
			$contentsByInventory[spl_object_id($inventory)][$localSlot] = $item;
		}
		foreach($contentsByInventory as $id => $contents){
			$this->backingInventories[$id]->setContents($contents);
		}
	}

	public function getSize() : int{
		return $this->size;
	}

	public function getItem(int $index) : Item{
		return ($this->slotOwners[$index] ?? $this->getBackingInventory($index, $_))->getItem($index - $this->slotOffsets[$index]);
	}

	public function getContents(bool $includeEmpty = false) : array{
		$result = [];
		foreach($this->backingInventories as $id => $inventory){
			$offset = $this->offsets[$id];
			foreach($inventory->getContents($includeEmpty) as $i => $item){
				$result[$offset + $i] = $item;
			}
		}
		return $result;
	}

	public function getMatchingItemCount(int $slot, Item $test, bool $checkTags) : int{
		return ($this->slotOwners[$slot] ?? $this->getBackingInventory($slot, $_))->getMatchingItemCount($slot - $this->slotOffsets[$slot], $test, $checkTags);
	}

	public function isSlotEmpty(int $index) : bool{
		return ($this->slotOwners[$index] ?? $this->getBackingInventory($index, $_))->isSlotEmpty($index - $this->slotOffsets[$index]);
	}

	//windows are registered on the backing inventories, so destroying either half closes the combined view too
	public function onOpen(InventoryWindow $window) : void{
		foreach($this->backingInventories as $inventory){
			$inventory->onOpen($window);
		}
	}

	public function onClose(InventoryWindow $window) : void{
		foreach($this->backingInventories as $inventory){
			$inventory->onClose($window);
		}
	}

	public function removeAllWindows() : void{
		foreach($this->backingInventories as $inventory){
			$inventory->removeAllWindows();
		}
	}

	public function getViewers() : array{
		$viewers = [];
		foreach($this->backingInventories as $inventory){
			$viewers += $inventory->getViewers();
		}
		return $viewers;
	}
}
