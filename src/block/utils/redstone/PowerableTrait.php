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

namespace pocketmine\block\utils\redstone;

use pocketmine\math\Facing;
use pocketmine\world\redstone\RedstoneManager;
use pocketmine\world\redstone\RedstoneWorldState;

trait PowerableTrait{

	public function onNearbyBlockChange() : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		parent::onNearbyBlockChange();
		$this->recalculatePowerState();
	}

	public function onPostPlace() : void{
		$this->recalculatePowerState();
	}

	public function recalculatePowerState() : void{
		$manager = RedstoneManager::getInstance();
		if(!$manager->isEnabledAt($this->position)){ return; }
		$power = 0;

		if(!$this->requires_strong_power){
			foreach(Facing::ALL as $side){
				if($this->acceptsPowerFromSide($side)){
					$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
					if($manager->isEnabledAt($block->getPosition()) && $block instanceof PowerSource && $block->canPower(Facing::opposite($side))){
						$block_power = $block->getOutputPowerLevel();
						if($block_power > $power){
							$power = $block_power;
							if($power === 15){
								break;
							}
						}
					}
				}
			}
		}

		if($power !== 15){
			$world = $manager->get($this->position->getWorld());
			if($world->isStronglyPowered($this, -1)){
				$power = 15;
			}else{
				foreach(Facing::ALL as $side){
					if($this->acceptsPowerFromSide($side)){
						$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
						if($world->isStronglyPowered($block, $opposite_side = Facing::opposite($side), $opposite_side)){
							$power = 15;
							break;
						}
					}
				}
			}
		}

		$this->onReceivePower($power);
	}

	public function power(PowerSource $source) : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		$delay = $this->isPowered() ? $this->deactivation_delay : $this->activation_delay;
		if($delay > 0){
			RedstoneManager::getInstance()->get($this->position->getWorld())->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks($delay), override: true);
		}else{
			$this->onRedstoneTickReceive();
		}
	}

	public function onRedstoneTickReceive() : void{
		$this->recalculatePowerState();
	}

	public function acceptsPowerFromSide(int $side) : bool{
		return true;
	}

	protected function onReceivePower(int $power) : void{
	}
}
