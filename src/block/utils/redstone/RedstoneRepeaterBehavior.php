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

use Generator;
use quark\block\Block;
use quark\block\Flowable;
use quark\block\Glowstone;
use quark\block\Slab;
use quark\block\utils\SlabType;
use quark\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;use quark\world\redstone\RedstoneManager;
use quark\world\redstone\RedstoneWorldState;
use function assert;

trait RedstoneRepeaterBehavior{
	use RedstoneBlockAccessTrait;

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($this->canBePlacedUpon(RedstoneBlockUtils::getBlockAtSide($blockReplace->position, Facing::DOWN))){
			if($player !== null){
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}

			return Flowable::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}

		return false;
	}

	private function canBePlacedUpon(Block $block) : bool{
		return !$block->isTransparent() || ($block instanceof Slab && $block->getSlabType() === SlabType::TOP) || $block instanceof Glowstone;
	}

	public function getPowerLevel() : int{
		return $this->powered ? 15 : 0;
	}

	public function getOutputPowerLevel() : int{
		return $this->getPowerLevel();
	}

	public function canPower(int $side) : bool{
		return $side === Facing::opposite($this->facing);
	}

	public function canStronglyPower(int $side) : bool{
		return $this->canPower($side);
	}

	public function switch(bool $state) : void{
		if($state !== $this->powered){
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setPowered($state), false);
		}
	}

	public function onNearbyBlockChange() : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		if(!$this->canBePlacedUpon(RedstoneBlockUtils::getBlockAtSide($this->position, Facing::DOWN))){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}

		if(!$this->isLocked()){
			$world = RedstoneManager::getInstance()->get($this->position->getWorld());
			if($world->getExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z) instanceof RedstoneRepeaterBlockData){
				return;
			}
			$world->setExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, new RedstoneRepeaterBlockData(RedstoneRepeaterBlockData::OPERATION_SWITCH_RECALCULATE));
			$world->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks($this->delay));
		}
	}

	public function isLocked() : bool{
		foreach(Facing::axis($this->facing) === Axis::Z ? [Facing::EAST, Facing::WEST] : [Facing::NORTH, Facing::SOUTH] as $side){
			$repeater = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			if($repeater instanceof self && $repeater->isPowered() && $repeater->canPower(Facing::opposite($side))){
				return true;
			}
		}
		return false;
	}

	public function power(PowerSource $source) : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		assert($source instanceof Block);
		$world = RedstoneManager::getInstance()->get($this->position->getWorld());
		if($world->getExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z) instanceof RedstoneRepeaterBlockData){
			$world->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks($this->delay)); // corner case. override=false is important
			return;
		}
		$shouldPower = $this->recalculatePower($world) > 0;
		if($shouldPower === $this->powered){
			return;
		}
		$world->setExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, new RedstoneRepeaterBlockData($shouldPower ? RedstoneRepeaterBlockData::OPERATION_SWITCH_ON : RedstoneRepeaterBlockData::OPERATION_SWITCH_RECALCULATE));
		$world->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks($this->delay));
	}

	private function recalculatePower(RedstoneWorldState $world) : int{
		$behind = RedstoneBlockUtils::getBlockAtSide($this->position, $this->facing); //The stored facing points toward the repeater input.
		if(!RedstoneManager::getInstance()->isEnabledAt($behind->getPosition())){ return 0; }
		return (($behind instanceof PowerSource && $behind->getPowerLevel() > 0) || $world->isStronglyPowered($behind, $opposite_side = Facing::opposite($this->facing), $opposite_side)) ? 15 : 0;
	}

	public function onRedstoneTickReceive() : void{
		$world = RedstoneManager::getInstance()->get($this->position->getWorld());
		$extra_data = $world->getExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		if(!($extra_data instanceof RedstoneRepeaterBlockData)){
			return;
		}
		$this->runRepeaterState($world, $extra_data->operation);
	}

	/**
	 * @param RedstoneRepeaterBlockData::OPERATION_*|null $state
	 */
	public function runRepeaterState(RedstoneWorldState $world, ?int $state) : void{
		if($this->isLocked()){
			return;
		}
		$extra_data = $world->getExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		if(!($extra_data instanceof RedstoneRepeaterBlockData)){
			return;
		}
		while(true){
			if($state === RedstoneRepeaterBlockData::OPERATION_SWITCH_ON){
				if(!$this->powered){
					$this->switch(true);
					$state = RedstoneRepeaterBlockData::OPERATION_DISTRIBUTE;
				}else{
					$state = null;
				}
			}elseif($state === RedstoneRepeaterBlockData::OPERATION_DISTRIBUTE){
				$extra_data->operation = RedstoneRepeaterBlockData::OPERATION_SWITCH_RECALCULATE;
				$world->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks($this->delay));
				$facing = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing));
				if($facing instanceof Transmittable){
					$facing->power($this);
				}
				foreach($this->getSupportingBlocks() as $block){
					$block->power($this);
				}
				break;
			}elseif($state === RedstoneRepeaterBlockData::OPERATION_SWITCH_RECALCULATE){
				$powered = $this->recalculatePower($world) > 0;
				if($powered === $this->powered){
					$state = null;
				}else{
					$this->switch($powered);
					$state = RedstoneRepeaterBlockData::OPERATION_DISTRIBUTE;
				}
			}elseif($state === null){
				$world->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
				break;
			}else{
				throw new \RuntimeException("Unexpected repeater operation: {$state}");
			}
		}
	}

	/**
	 * @return Generator<Transmittable>
	 */
	public function getSupportingBlocks() : Generator{
		$facing = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing));
		if($facing instanceof self){
			return; // TODO: Generify this - blocks such as torches stop further flow of current as well ([repeater][torch][lamp] won't make [lamp] block light)
		}
		foreach(Facing::ALL as $face){
			if($face !== $this->facing){
				$block = RedstoneBlockUtils::getBlockAtSide($facing->position, $face);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->powered){
			$this->switch(false);
			RedstoneManager::getInstance()->get($this->position->getWorld())->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
			$facing = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing));
			if($facing instanceof Transmittable){
				$facing->power($this);
			}
			foreach($this->getSupportingBlocks() as $block){
				$block->power($this);
			}
		}
		return parent::onBreak($item, $player, $returnedItems);
	}
}
