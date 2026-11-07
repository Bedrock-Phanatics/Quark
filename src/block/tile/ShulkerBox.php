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

use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use quark\block\BlockTypeIds;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\inventory\transaction\action\validator\CallbackSlotValidator;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\Item;
use quark\item\ItemTypeIds;

class ShulkerBox extends Spawnable implements ContainerTile, Nameable{
	use NameableTrait {
		addAdditionalSpawnData as addNameSpawnData;
	}
	use ContainerTileTrait;

	public const TAG_FACING = "facing";

	protected int $facing = Facing::NORTH;

	protected Inventory $inventory;

	public function __construct(){
		parent::__construct();
		$this->inventory = new SimpleInventory(27);
		//nesting shulker boxes would let a client build arbitrarily deep item NBT
		$this->inventory->getSlotValidators()->add(new CallbackSlotValidator(static function(Inventory $_, Item $item) : ?TransactionValidationException{
			$blockTypeId = ItemTypeIds::toBlockTypeId($item->getTypeId());
			return $blockTypeId === BlockTypeIds::SHULKER_BOX || $blockTypeId === BlockTypeIds::DYED_SHULKER_BOX ?
				new TransactionValidationException("Shulker boxes can't contain shulker boxes") : null;
		}));
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
			$this->inventory->removeAllWindows();
			parent::close();
		}
	}

	protected function onBlockDestroyedHook() : void{
		//NOOP override of ContainerTileTrait - shulker boxes retain their contents when destroyed
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

	public function getInventory() : Inventory{
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
