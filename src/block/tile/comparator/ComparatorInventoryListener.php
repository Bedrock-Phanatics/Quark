<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block\tile\comparator;

use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\RedstoneComparator;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryListener;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;

final class ComparatorInventoryListener implements InventoryListener{

	public static function instance(int $facing) : self{
		static $instances = [];;
		return $instances[$facing] ??= new self(Vector3::zero()->getSide(Facing::opposite($facing)));
	}

	private function __construct(
		readonly public Vector3 $facing
	){}

	public function update(Inventory $inventory) : void{
		if(!($inventory instanceof BlockInventory)){
			$inventory->getListeners()->remove($this);
			return;
		}
		$holder = $inventory->getHolder();
		if($holder->world === null){
			$inventory->getListeners()->remove($this);
			return;
		}
		$comparator = $holder->world->getBlockAt((int) ($holder->x + $this->facing->x), (int) ($holder->y + $this->facing->y), (int) ($holder->z + $this->facing->z));
		if(!($comparator instanceof RedstoneComparator)){
			$inventory->getListeners()->remove($this);
			return;
		}
		$comparator->onContainerInputChange();
	}

	public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
		$this->update($inventory);
	}

	public function onContentChange(Inventory $inventory, array $oldContents) : void{
		$this->update($inventory);
	}
}
