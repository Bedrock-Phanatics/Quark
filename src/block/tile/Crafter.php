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

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ShortTag;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;

/**
 * Contents are kept and dropped when the crafter is broken, but nothing can use them yet.
 * TODO: Implement the crafter UI, disabling slots and redstone-triggered crafting.
 */
class Crafter extends Tile implements ContainerTile{
	use ContainerTileTrait;
	use RetainedTagsTrait;

	private const RETAINED_TAGS = [
		"crafting_ticks_remaining" => IntTag::class,
		"disabled_slots" => ShortTag::class,
		"triggered" => IntTag::class,
	];

	private SimpleInventory $inventory;

	public function __construct(){
		parent::__construct();
		$this->inventory = new SimpleInventory(9);
	}

	public function getInventory() : Inventory{
		return $this->inventory;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadItems($nbt);
		$this->loadRetainedTags($nbt, self::RETAINED_TAGS);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveItems($nbt);
		$this->saveRetainedTags($nbt);
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllWindows();
			parent::close();
		}
	}
}
