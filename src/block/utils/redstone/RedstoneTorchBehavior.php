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

use Generator;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\player\Player;use pocketmine\world\redstone\RedstoneManager;
use pocketmine\world\redstone\RedstoneWorldState;

trait RedstoneTorchBehavior{
	use RedstoneBlockAccessTrait;

	public function getPowerLevel() : int{
		return $this->lit ? 15 : 0;
	}

	public function getOutputPowerLevel() : int{
		return $this->getPowerLevel();
	}

	public function canPower(int $side) : bool{
		return $side !== Facing::opposite($this->facing);
	}

	public function canStronglyPower(int $side) : bool{
		return $side === Facing::UP;
	}

	public function switch(bool $state) : void{
		if($state === $this->lit){
			return;
		}

		$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setLit($state), false);
		foreach($this->getRelyingBlocks() as $block){
			$block->power($this);
		}
	}

	public function power(?PowerSource $source = null) : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		RedstoneManager::getInstance()->get($this->position->getWorld())->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks(1));
	}

	private function updateState(bool $manual) : void{
		$state = !RedstoneManager::getInstance()->get($this->position->getWorld())->isStronglyPowered(RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing)), $this->facing);
		if($state === $this->lit){
			return;
		}

		if($manual){
			$world = RedstoneManager::getInstance()->get($this->position->getWorld());
			$data = $world->getExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
			if(!($data instanceof RedstoneTorchBlockData)){
				$data = new RedstoneTorchBlockData();
				$world->setExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $data);
			}
			$tick = $world->tick;
			if($state){
				$data->count($tick);
			}
			if($data->isBurntOut($tick)){
				$state = false;
			}
		}
		$this->switch($state);
	}

	public function onRedstoneTickReceive() : void{
		$this->updateState(true);
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingBlocks() : Generator{
		$sides = Facing::HORIZONTAL;
		$sides[] = Facing::DOWN;
		foreach($sides as $side){
			$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			if($block instanceof Transmittable){
				yield $block;
			}
		}

		yield from $this->getRelyingOnSupportBlocks();
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingOnSupportBlocks() : Generator{
		$up = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::UP);
		if($up instanceof Transmittable){
			yield $up;
		}
		foreach(Facing::ALL as $side){
			if($side !== Facing::DOWN){
				$block = RedstoneBlockUtils::getBlockAtSide($up->position, $side);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}
	}

	public function onPostPlace() : void{
		$this->power();
		foreach($this->getRelyingBlocks() as $block){
			$block->power($this);
		}
	}

	public function onScheduledUpdate() : void{
		RedstoneManager::getInstance()->get($this->position->getWorld())->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		$this->updateState(false);
	}

	public function onNearbyBlockChange() : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		RedstoneManager::getInstance()->get($this->position->getWorld())->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		$this->updateState(false);
		parent::onNearbyBlockChange();
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->lit){
			$this->lit = false;
			foreach($this->getRelyingOnSupportBlocks() as $block){
				$block->power($this);
			}
		}

		RedstoneManager::getInstance()->get($this->position->getWorld())->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		return parent::onBreak($item, $player, $returnedItems);
	}
}
