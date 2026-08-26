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

namespace pocketmine\block;

use pocketmine\block\utils\redstone\ButtonTrait;
use pocketmine\block\utils\redstone\PowerSource;
use pocketmine\block\utils\redstone\RedstoneBlockAccessTrait;
use pocketmine\block\utils\WoodMaterial;
use pocketmine\block\utils\WoodTypeTrait;
use pocketmine\entity\Entity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\item\VanillaItems;
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
