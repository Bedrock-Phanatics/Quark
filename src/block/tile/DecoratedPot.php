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
use quark\item\Item;
use quark\item\VanillaItems;

/**
 * TODO: Implement inserting items into the pot, the wobble animation and hopper interaction.
 */
class DecoratedPot extends Spawnable{
	public const TAG_SHERDS = "sherds"; //TAG_List<TAG_String>
	private const TAG_ITEM = "item"; //TAG_Compound

	private ?ListTag $sherds = null;
	private Item $item;

	public function __construct(){
		parent::__construct();
		$this->item = VanillaItems::AIR();
	}

	public function getItem() : Item{ return clone $this->item; }

	public function setItem(Item $item) : void{
		$this->item = clone $item;
	}

	/**
	 * Returns the sherd IDs exactly as stored, or null if the pot has no sherd data.
	 */
	public function getSherds() : ?ListTag{
		return $this->sherds?->safeClone();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->readSherds($nbt);
		$item = $nbt->getTag(self::TAG_ITEM);
		$this->item = $item instanceof CompoundTag ? Item::safeNbtDeserialize($item, "DecoratedPot item") : VanillaItems::AIR();
	}

	private function readSherds(CompoundTag $nbt) : void{
		$sherds = $nbt->getTag(self::TAG_SHERDS);
		$this->sherds = $sherds instanceof ListTag && $sherds->getTagType() === NBT::TAG_String ? $sherds->safeClone() : null;
	}

	//Pot items only carry the sherds; the contents are always dropped separately.
	public function copyDataFromItem(Item $item) : void{
		$blockNbt = $item->getCustomBlockData();
		if($blockNbt !== null){
			$this->readSherds($blockNbt);
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->addSherds($nbt);
		if(!$this->item->isNull()){
			$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addSherds($nbt);
	}

	private function addSherds(CompoundTag $nbt) : void{
		if($this->sherds !== null){
			$nbt->setTag(self::TAG_SHERDS, $this->sherds->safeClone());
		}
	}

	protected function onBlockDestroyedHook() : void{
		if(!$this->item->isNull()){
			$pos = $this->getPosition();
			$pos->getWorld()->dropItem($pos->add(0.5, 0.5, 0.5), $this->item);
			$this->item = VanillaItems::AIR();
		}
	}
}
