<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use pocketmine\block\Door;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\DoorSound;

trait PowerableDoorTrait{
	use PowerableTrait;

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 0;
	private(set) bool $requires_strong_power = false;

	public function onNearbyBlockChange() : void{
		Door::onNearbyBlockChange();
	}

	public function isPowered() : bool{
		return $this->open;
	}

	protected function onReceivePower(int $power) : void{
		$powered = $power > 0;
		if($powered !== $this->open){
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setOpen($powered), false);
			$this->position->getWorld()->addSound($this->position, new DoorSound());
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->open = !$this->open;

		$other = $this->getSide($this->top ? Facing::DOWN : Facing::UP);
		$world = $this->position->getWorld();
		if($other instanceof Door && $other->hasSameTypeId($this)){
			$other->open = $this->open;
			$world->setBlock($other->position, $other, false);
		}

		$world->setBlock($this->position, $this, false);
		$world->addSound($this->position, new DoorSound());
		return true;
	}
}