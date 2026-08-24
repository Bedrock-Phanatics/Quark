<?php

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