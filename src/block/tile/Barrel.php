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

use quark\block\inventory\BarrelInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use quark\world\World;

class Barrel extends Spawnable implements Container, Nameable{
	use NameableTrait;
	use ContainerTrait;

	protected BarrelInventory $inventory;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new BarrelInventory($this->position);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadName($nbt);
		$this->loadItems($nbt);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveName($nbt);
		$this->saveItems($nbt);
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();
			parent::close();
		}
	}

	public function getInventory() : BarrelInventory{
		return $this->inventory;
	}

	public function getRealInventory() : BarrelInventory{
		return $this->inventory;
	}

	public function getDefaultName() : string{
		return "Barrel";
	}
}
