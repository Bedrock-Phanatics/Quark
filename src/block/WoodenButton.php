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

namespace quark\block;

use quark\block\utils\redstone\ButtonTrait;
use quark\block\utils\redstone\PowerSource;
use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\block\utils\WoodMaterial;
use quark\block\utils\WoodTypeTrait;
use quark\entity\Entity;
use quark\entity\projectile\Arrow;
use quark\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;

class WoodenButton extends Button implements WoodMaterial, PowerSource{
	use ButtonTrait{ onScheduledUpdate as private updateButtonState; }
	use RedstoneBlockAccessTrait;
	use WoodTypeTrait;

	protected function getActivationTime() : int{
		return 30;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if($entity instanceof Arrow && !$this->pressed){
			$this->onInteract(VanillaItems::AIR(), Facing::UP, Vector3::zero());
			return false;
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->pressed){
			$buttonCell = AxisAlignedBB::one()->offset($this->position->x, $this->position->y, $this->position->z);
			foreach($this->position->getWorld()->getNearbyEntities($buttonCell) as $entity){
				if($entity instanceof Arrow && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
					$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, $this->getActivationTime());
					return;
				}
			}
		}
		$this->updateButtonState();
	}

	public function getFuelTime() : int{
		return $this->woodType->isFlammable() ? 100 : 0;
	}
}
