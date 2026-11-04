<?php

declare(strict_types=1);

namespace quark\block\custom;

use pocketmine\math\Facing;
use quark\block\Door;

/**
 * Clients render each custom door half from its own state, so redstone must update the saved state of both halves.
 * Vanilla doors only need the powered half because the client merges them.
 */
class CustomDoor extends Door{
	protected function onReceivePower(int $power) : void{
		$wasOpen = $this->isOpen();
		parent::onReceivePower($power);
		if($this->isOpen() === $wasOpen){
			return;
		}
		//the other half reads its open state from this one, so write it even when it already looks right
		$other = $this->getSide($this->isTop() ? Facing::DOWN : Facing::UP);
		if($other instanceof Door && $other->hasSameTypeId($this)){
			$this->position->getWorld()->setBlock($other->position, $other->setOpen($this->isOpen()), false);
		}
	}
}
