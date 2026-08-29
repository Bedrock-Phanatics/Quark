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

use quark\block\inventory\ShulkerBoxInventory;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use quark\world\World;

class ShulkerBox extends Spawnable implements Container, Nameable{
	use NameableTrait {
		addAdditionalSpawnData as addNameSpawnData;
	}
	use ContainerTrait;

	public const TAG_FACING = "facing";

	protected int $facing = Facing::NORTH;

	protected ShulkerBoxInventory $inventory;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new ShulkerBoxInventory($this->position);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadName($nbt);
		$this->loadItems($nbt);
		$this->facing = $nbt->getByte(self::TAG_FACING, $this->facing);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveName($nbt);
		$this->saveItems($nbt);
		$nbt->setByte(self::TAG_FACING, $this->facing);
	}

	public function copyDataFromItem(Item $item) : void{
		$this->readSaveData($item->getNamedTag());
		if($item->hasCustomName()){
			$this->setName($item->getCustomName());
		}
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();
			parent::close();
		}
	}

	protected function onBlockDestroyedHook() : void{
		//NOOP override of ContainerTrait - shulker boxes retain their contents when destroyed
	}

	public function getCleanedNBT() : ?CompoundTag{
		$nbt = parent::getCleanedNBT();
		if($nbt !== null){
			$nbt->removeTag(self::TAG_FACING);
		}
		return $nbt;
	}

	public function getFacing() : int{
		return $this->facing;
	}

	public function setFacing(int $facing) : void{
		$this->facing = $facing;
	}

	public function getInventory() : ShulkerBoxInventory{
		return $this->inventory;
	}

	public function getRealInventory() : ShulkerBoxInventory{
		return $this->inventory;
	}

	public function getDefaultName() : string{
		return "Shulker Box";
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_FACING, $this->facing);
		$this->addNameSpawnData($nbt);
	}
}
