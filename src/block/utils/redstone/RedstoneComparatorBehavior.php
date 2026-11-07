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
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\block\Block;
use quark\block\Flowable;
use quark\block\Glowstone;
use quark\block\Opaque;
use quark\block\RedstoneWire;
use quark\block\Slab;
use quark\block\tile\comparator\ComparatorInventoryListener;
use quark\block\tile\comparator\ComparatorWeightRegistry;
use quark\block\tile\ContainerTile;
use quark\block\utils\Container;
use quark\block\utils\SlabType;
use quark\inventory\CombinedInventoryProxy;
use quark\inventory\Inventory;
use quark\item\Item;
use quark\player\Player;
use quark\world\BlockTransaction;
use quark\world\redstone\RedstoneManager;
use quark\world\redstone\RedstoneWorldState;
use quark\world\sound\RedstonePowerOffSound;
use quark\world\sound\RedstonePowerOnSound;
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
		//the source may be any block in the network that changed, so its level says nothing about this comparator's inputs
		$manager->get($this->position->getWorld())->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks(1), RedstoneWorldState::WAITABLE_UPDATE_LOW_PRIORITY);
	}

	private function recalculatePower() : int{
		$manager = RedstoneManager::getInstance();
		$behind = RedstoneBlockUtils::getBlockAtSide($this->position, $this->facing);
		if(!$manager->isEnabledAt($behind->getPosition())){ return 0; }
		$world = $manager->get($this->position->getWorld());
		if($behind instanceof PowerSource){
			$rear = $behind instanceof RedstoneWire || $behind->canPower(Facing::opposite($this->facing)) ? $behind->getPowerLevel() : 0;
		}else{
			$inventory = self::getReadableInventory($behind);
			if($inventory !== null){
				$rear = self::getFullnessSignal($inventory);
			}else{
				$rear = 0;
				/** @var PowerSource $source */
				foreach($world->getStronglyPoweringSources($behind, $opposite_side = Facing::opposite($this->facing), $opposite_side) as $source){
					$rear = max($rear, $source->getPowerLevel());
				}
				if($rear < 15 && ($inventory = $this->getInventoryThroughBlock($behind)) !== null){
					$rear = max($rear, self::getFullnessSignal($inventory));
				}
			}
		}

		if($rear === 0){
			return 0;
		}

		$side_strengths = [];
		foreach(Facing::axis($this->facing) === Axis::Z ? [Facing::EAST, Facing::WEST] : [Facing::NORTH, Facing::SOUTH] as $side){
			$sideBlock = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			$pointsHere = !($sideBlock instanceof PowerSource) || $sideBlock instanceof RedstoneWire || $sideBlock->canPower(Facing::opposite($side));
			$side_strengths[] = $pointsHere && $manager->isEnabledAt($sideBlock->getPosition()) ? ComparatorWeightRegistry::getValue($sideBlock) : 0;
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

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($this->canBePlacedUpon(RedstoneBlockUtils::getBlockAtSide($blockReplace->position, Facing::DOWN))){
			if($player !== null){
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}
			return Flowable::place($tx, $item, $blockReplace, $face, $clickVector, $player);
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

	private static function getFullnessSignal(Inventory $inventory) : int{
		$fullness = 0;
		foreach(RedstoneBlockUtils::fastReadOnlyInventoryContents($inventory) as $item){
			$fullness += min(1, $item->getCount() / $item->getMaxStackSize());
		}
		return $fullness > 0 ? (int) floor(1 + ($fullness / $inventory->getSize()) * 14) : 0;
	}

	//like vanilla, a comparator reads a container through one solid block
	private function getInventoryThroughBlock(Block $behind) : ?Inventory{
		if(!$behind instanceof Opaque){
			return null;
		}
		$beyond = RedstoneBlockUtils::getBlockAtSide($behind->position, $this->facing);
		return RedstoneManager::getInstance()->isEnabledAt($beyond->position) ? self::getReadableInventory($beyond) : null;
	}

	/**
	 * Returns what a comparator reads from the block, including both halves of a double chest.
	 */
	private static function getReadableInventory(Block $block) : ?Inventory{
		if($block instanceof Container){
			return $block->getInventory();
		}
		$position = $block->getPosition();
		$tile = $position->getWorld()->getTile($position);
		return $tile instanceof ContainerTile ? $tile->getInventory() : null;
	}

	private function notifyContainer() : void{
		$behind = RedstoneBlockUtils::getBlockAtSide($this->position, $this->facing);
		$inventory = self::getReadableInventory($behind) ?? $this->getInventoryThroughBlock($behind);
		if($inventory === null){
			return;
		}
		//combined views are thrown away after use, so listen to the inventories that actually store the items
		foreach($inventory instanceof CombinedInventoryProxy ? $inventory->getBackingInventories() : [$inventory] as $backing){
			ComparatorInventoryListener::attach($backing, $this->position);
		}
	}

	public function onScheduledUpdate() : void{
		$this->notifyContainer();
	}
}
