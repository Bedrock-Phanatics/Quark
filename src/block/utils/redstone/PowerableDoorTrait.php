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

namespace quark\block\utils\redstone;

use pocketmine\math\Facing;
use quark\block\Door;
use quark\world\sound\DoorSound;

trait PowerableDoorTrait{
	use PowerableTrait;

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 0;
	private(set) bool $requires_strong_power = false;

	public function isPowered() : bool{
		return $this->open;
	}

	//both halves share one open state, so a door opens when either half is powered
	protected function onReceivePower(int $power) : void{
		$other = $this->getSide($this->top ? Facing::DOWN : Facing::UP);
		$other = $other instanceof Door && $other->hasSameTypeId($this) ? $other : null;
		$open = $power > 0 || ($other !== null && $other->getReceivedPower() > 0);
		if($open === $this->open){
			return;
		}
		$world = $this->position->getWorld();
		$world->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setOpen($open), false);
		if($other !== null){
			$world->setBlockAt((int) $other->position->x, (int) $other->position->y, (int) $other->position->z, $other->setOpen($open), false);
		}
		$world->addSound($this->position, new DoorSound());
	}
}
