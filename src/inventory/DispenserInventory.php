<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\inventory\SimpleInventory;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\world\Position;

final class DispenserInventory extends SimpleInventory implements BlockInventoryWindow{

	protected Position $holder;

	public function __construct(Position $holder){
		parent::__construct(9);
		$this->holder = $holder;
	}

	public function getNetworkWindowType() : int{
		return WindowTypes::DISPENSER;
	}

	public function getHolder() : Position{
		return $this->holder;
	}
}