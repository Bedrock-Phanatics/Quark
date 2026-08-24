<?php

declare(strict_types=1);

namespace pocketmine\block\tile\comparator;

use pocketmine\block\inventory\BlockInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryListener;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\block\RedstoneComparator;

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