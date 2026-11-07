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

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\UnexpectedTagTypeException;
use quark\data\bedrock\item\SavedItemStackData;
use quark\inventory\Inventory;
use quark\item\Item;
use quark\world\Position;

/**
 * This trait implements most methods in the {@link ContainerTile} interface. It should only be used by Tiles.
 */
trait ContainerTileTrait{
	/** @var string|null */
	private $lock = null;

	abstract public function getInventory() : Inventory;

	protected function loadItems(CompoundTag $tag) : void{
		try{
			$inventoryTag = $tag->getListTag(ContainerTile::TAG_ITEMS, CompoundTag::class);
		}catch(UnexpectedTagTypeException){
			//preserve the old behaviour of not throwing on wrong types
			$inventoryTag = null;
		}
		if($inventoryTag !== null){
			$inventory = $this->getInventory();
			$listeners = $inventory->getListeners()->toArray();
			$inventory->getListeners()->remove(...$listeners); //prevent any events being fired by initialization

			$newContents = [];
			$errorLogContext = "Container";
			foreach($inventoryTag as $itemNBT){
				$slotId = $itemNBT->getByte(SavedItemStackData::TAG_SLOT);
				$newContents[$slotId] = Item::safeNbtDeserialize($itemNBT, "$errorLogContext slot $slotId");
			}
			$inventory->setContents($newContents);

			$inventory->getListeners()->add(...$listeners);
		}

		if(($lockTag = $tag->getTag(ContainerTile::TAG_LOCK)) instanceof StringTag){
			$this->lock = $lockTag->getValue();
		}
	}

	protected function saveItems(CompoundTag $tag) : void{
		$items = [];
		foreach($this->getInventory()->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}

		$tag->setTag(ContainerTile::TAG_ITEMS, new ListTag($items, NBT::TAG_Compound));

		if($this->lock !== null){
			$tag->setString(ContainerTile::TAG_LOCK, $this->lock);
		}
	}

	/**
	 * @see ContainerTile::canOpenWith()
	 */
	public function canOpenWith(string $key) : bool{
		return $this->lock === null || $this->lock === $key;
	}

	/**
	 * @see Position::asPosition()
	 */
	abstract protected function getPosition() : Position;

	/**
	 * @see Tile::onBlockDestroyedHook()
	 */
	protected function onBlockDestroyedHook() : void{
		$inv = $this->getInventory();
		$pos = $this->getPosition();
		//close menus first so nobody can move items in or out while the contents are being dropped
		$inv->removeAllWindows();

		$world = $pos->getWorld();
		$dropPos = $pos->add(0.5, 0.5, 0.5);
		foreach($inv->getContents() as $k => $item){
			$world->dropItem($dropPos, $item);
		}
		$inv->clearAll();
	}
}
