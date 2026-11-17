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
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use quark\block\Shelf as BlockShelf;
use quark\data\bedrock\item\SavedItemData;
use quark\data\bedrock\item\SavedItemStackData;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\item\Item;
use quark\network\mcpe\convert\TypeConverter;

/**
 * Items are stored positionally like the chiseled bookshelf, with a Slot tag as well in case vanilla reads it.
 * TODO: Implement placing and swapping items, and swapping the hotbar with a powered row of shelves.
 */
class Shelf extends Spawnable implements ContainerTile{
	use ContainerTileTrait;

	public const SLOTS = 3;

	private SimpleInventory $inventory;

	public function __construct(){
		parent::__construct();
		$this->inventory = new SimpleInventory(self::SLOTS);
		//shelf items are rendered from the spawn data, so viewers need the block resent on every change
		$tile = \WeakReference::create($this);
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(static function(Inventory $unused) use ($tile) : void{
			$shelf = $tile->get();
			if($shelf === null || !$shelf->isAttached()){
				return;
			}
			$pos = $shelf->getPosition();
			$block = $pos->getWorld()->getBlock($pos);
			if($block instanceof BlockShelf){
				$pos->getWorld()->setBlock($pos, $block);
			}
		}));
	}

	public function getInventory() : Inventory{
		return $this->inventory;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$items = $nbt->getTag(ContainerTile::TAG_ITEMS);
		if(!$items instanceof ListTag || $items->getTagType() !== NBT::TAG_Compound){
			return;
		}
		$contents = [];
		/** @var CompoundTag $itemNbt */
		foreach($items as $index => $itemNbt){
			$slotTag = $itemNbt->getTag(SavedItemStackData::TAG_SLOT);
			$slot = $slotTag instanceof ByteTag ? $slotTag->getValue() : $index;
			if($slot < 0 || $slot >= self::SLOTS || $itemNbt->getByte(SavedItemStackData::TAG_COUNT, 0) === 0){
				continue;
			}
			$contents[$slot] = Item::safeNbtDeserialize($itemNbt, "Shelf slot $slot");
		}
		$listeners = $this->inventory->getListeners()->toArray();
		$this->inventory->getListeners()->remove(...$listeners);
		$this->inventory->setContents($contents);
		$this->inventory->getListeners()->add(...$listeners);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setTag(ContainerTile::TAG_ITEMS, $this->serializeItems(fn(Item $item) => $item->nbtSerialize()));
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$translator = TypeConverter::getInstance()->getItemTranslator();
		$nbt->setTag(ContainerTile::TAG_ITEMS, $this->serializeItems(fn(Item $item) => $translator->toNetworkNbt($item)));
	}

	/**
	 * @phpstan-param \Closure(Item) : CompoundTag $serialize
	 */
	private function serializeItems(\Closure $serialize) : ListTag{
		$items = [];
		foreach($this->inventory->getContents(true) as $slot => $item){
			$items[] = ($item->isNull() ?
				CompoundTag::create()
					->setByte(SavedItemStackData::TAG_COUNT, 0)
					->setShort(SavedItemData::TAG_DAMAGE, 0)
					->setString(SavedItemData::TAG_NAME, "") :
				$serialize($item)
			)->setByte(SavedItemStackData::TAG_SLOT, $slot);
		}
		return new ListTag($items, NBT::TAG_Compound);
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllWindows();
			parent::close();
		}
	}
}
