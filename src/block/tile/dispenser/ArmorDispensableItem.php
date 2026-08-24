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

namespace pocketmine\block\tile\dispenser;

use InvalidArgumentException;
use pocketmine\entity\Living;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\Inventory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;

class ArmorDispensableItem extends DropDispensableItem{

	protected int $slot;

	public function __construct(int $slot){
		if(
			$slot !== ArmorInventory::SLOT_HEAD &&
			$slot !== ArmorInventory::SLOT_CHEST &&
			$slot !== ArmorInventory::SLOT_LEGS &&
			$slot !== ArmorInventory::SLOT_FEET
		){
			throw new InvalidArgumentException("Invalid armor inventory slot: {$slot}");
		}

		$this->slot = $slot;
	}

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$item = $inventory->getItem($slot);
		$world = $pos->getWorld();
		foreach($world->getNearbyEntities(AxisAlignedBB::one()->offset($side_pos->x, $side_pos->y, $side_pos->z)) as $entity){
			if($entity instanceof Living){
				$armor_inventory = $entity->getArmorInventory();
				if($armor_inventory->isSlotEmpty($this->slot)){
					$equipped = $item->pop();
					$inventory->setItem($slot, $item);
					$armor_inventory->setItem($this->slot, $equipped);
					return true;
				}
			}
		}

		return parent::dispense($pos, $inventory, $slot, $side_pos, $facing, $player);
	}
}
