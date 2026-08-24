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
use pocketmine\block\Block;
use pocketmine\block\Flowable;
use pocketmine\block\Glowstone;
use pocketmine\block\Slab;
use pocketmine\block\tile\comparator\ComparatorInventoryListener;
use pocketmine\block\tile\comparator\ComparatorWeightRegistry;
use pocketmine\block\tile\Container;
use pocketmine\block\utils\SlabType;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;use pocketmine\world\redstone\RedstoneManager;
use pocketmine\world\redstone\RedstoneWorldState;
use pocketmine\world\sound\RedstonePowerOffSound;
use pocketmine\world\sound\RedstonePowerOnSound;
use function floor;
use function max;
use function min;

trait RedstoneComparatorBehavior{
	use RedstoneBlockAccessTrait;

	public function getPowerLevel() : int{
		return $this->signalStrength;
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

	public function setPowerLevel(int $level) : void{
		if($level !== $this->signalStrength){
			$this->signalStrength = $level;
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setPowered($this->signalStrength > 0), false);
			$facing = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing));
			if($facing instanceof Transmittable){
				$facing->power($this);
			}
			foreach($this->getSupportingBlocks() as $block){
				$block->power($this);
			}
		}
	}

	public function onNearbyBlockChange() : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		if(!$this->canBePlacedUpon(RedstoneBlockUtils::getBlockAtSide($this->position, Facing::DOWN))){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		$this->setPowerLevel($this->recalculatePower());
		$this->notifyContainer();
	}

	public function power(PowerSource $source) : void{
		$manager = RedstoneManager::getInstance();
		if(!$manager->isEnabledAt($this->position) || ($source instanceof Block && !$manager->isEnabledAt($source->getPosition()))){ return; }
		if($source->getPowerLevel() !== $this->signalStrength){
			RedstoneManager::getInstance()->get($this->position->getWorld())->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks(1), RedstoneWorldState::WAITABLE_UPDATE_LOW_PRIORITY);
		}
	}

	private function recalculatePower() : int{
		$manager = RedstoneManager::getInstance();
		$behind = RedstoneBlockUtils::getBlockAtSide($this->position, $this->facing);
		if(!$manager->isEnabledAt($behind->getPosition())){ return 0; }
		$world = $manager->get($this->position->getWorld());
		if($behind instanceof PowerSource){
			$rear = $behind->getPowerLevel();
		}else{
			$tile = $behind->position->getWorld()->getTileAt((int) $behind->position->x, (int) $behind->position->y, (int) $behind->position->z);
			if($tile instanceof Container){
				$inventory = $tile->getInventory();
				$fullness = 0;
				foreach(RedstoneBlockUtils::fastReadOnlyInventoryContents($inventory) as $item){
					$fullness += min(1, $item->getCount() / $item->getMaxStackSize());
				}
				$rear = $fullness > 0 ? (int) floor(1 + ($fullness / $inventory->getSize()) * 14) : 0;
			}else{
				$rear = 0;
				/** @var PowerSource $source */
				foreach($world->getStronglyPoweringSources($behind, $opposite_side = Facing::opposite($this->facing), $opposite_side) as $source){
					$rear = $source->getPowerLevel();
					break;
				}
			}
		}

		if($rear === 0){
			return 0;
		}

		$side_strengths = [];
		foreach(Facing::axis($this->facing) === Axis::Z ? [Facing::EAST, Facing::WEST] : [Facing::NORTH, Facing::SOUTH] as $side){
			$sideBlock = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			$side_strengths[] = $manager->isEnabledAt($sideBlock->getPosition()) ? ComparatorWeightRegistry::getValue($sideBlock) : 0;
		}

		return $this->isSubtractMode ? max($rear - max($side_strengths), 0) :
			($side_strengths[0] <= $rear && $side_strengths[1] <= $rear ? $rear : 0);
	}

	public function onRedstoneTickReceive() : void{
		$this->setPowerLevel($this->recalculatePower());
	}

	public function onContainerInputChange() : void{
		$manager = RedstoneManager::getInstance();
		if(!$manager->isEnabledAt($this->position)){ return; }
		$manager->get($this->position->getWorld())->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks(1), RedstoneWorldState::WAITABLE_UPDATE_LOW_PRIORITY);
	}

	/**
	 * @return Generator<Transmittable>
	 */
	public function getSupportingBlocks() : Generator{
		$facing = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing));
		if(!($facing instanceof self)){
			foreach(Facing::ALL as $face){
				if($face !== $this->facing){
					$block = RedstoneBlockUtils::getBlockAtSide($facing->position, $face);
					if($block instanceof Transmittable){
						yield $block;
					}
				}
			}
		}
	}

	private function canBePlacedUpon(Block $block) : bool{
		return !$block->isTransparent() || ($block instanceof Slab && $block->getSlabType() === SlabType::TOP) || $block instanceof Glowstone;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($this->canBePlacedUpon(RedstoneBlockUtils::getBlockAtSide($blockReplace->position, Facing::DOWN))){
			if($player !== null){
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}
			return Flowable::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}
		return false;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$old_state = $this->isSubtractMode();
		if(parent::onInteract($item, $face, $clickVector, $player, $returnedItems)){
			if($old_state !== $this->isSubtractMode()){
				$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), $this->isSubtractMode() ? new RedstonePowerOnSound() : new RedstonePowerOffSound());
			}
			return true;
		}
		return false;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$powered = $this->powered;
		$this->powered = false;
		if(parent::onBreak($item, $player, $returnedItems)){
			if($powered){
				foreach($this->getSupportingBlocks() as $block){
					$block->power($this);
				}
			}
			return true;
		}
		return false;
	}

	private function notifyContainer() : void{
		$behind_pos = $this->position->getSide($this->facing);
		$behind = $this->position->getWorld()->getTileAt((int) $behind_pos->x, (int) $behind_pos->y, (int) $behind_pos->z);
		if($behind instanceof Container){
			$inventory = $behind->getRealInventory();
			$listener = ComparatorInventoryListener::instance($this->facing);
			if(!$inventory->getListeners()->contains($listener)){
				$inventory->getListeners()->add($listener);
				$listener->update($inventory);
			}
		}
	}

	public function onScheduledUpdate() : void{
		$this->notifyContainer();
	}
}
