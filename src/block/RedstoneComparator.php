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

use quark\block\tile\Comparator;
use quark\block\utils\AnalogRedstoneSignalEmitter;
use quark\block\utils\AnalogRedstoneSignalEmitterTrait;
use quark\block\utils\HorizontalFacing;

use quark\block\utils\HorizontalFacingTrait;
use quark\block\utils\PoweredByRedstone;
use quark\block\utils\PoweredByRedstoneTrait;
use quark\block\utils\redstone\RedstoneComparatorBehavior;
use quark\block\utils\redstone\Transmittable;
use quark\block\utils\redstone\VariablePowerSource;
use quark\block\utils\redstone\Waitable;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use function assert;

class RedstoneComparator extends Flowable implements AnalogRedstoneSignalEmitter, PoweredByRedstone, HorizontalFacing, VariablePowerSource, Transmittable, Waitable{
	use RedstoneComparatorBehavior;
	use HorizontalFacingTrait;
	use AnalogRedstoneSignalEmitterTrait;
	use PoweredByRedstoneTrait;

	protected bool $isSubtractMode = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->isSubtractMode);
		$w->bool($this->powered);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof Comparator){
			$this->signalStrength = $tile->getSignalStrength();
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof Comparator);
		$tile->setSignalStrength($this->signalStrength);
	}

	public function isSubtractMode() : bool{
		return $this->isSubtractMode;
	}

	/** @return $this */
	public function setSubtractMode(bool $isSubtractMode) : self{
		$this->isSubtractMode = $isSubtractMode;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 7 / 8)];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->isSubtractMode = !$this->isSubtractMode;
		$this->position->getWorld()->setBlock($this->position, $this);
		return true;
	}

}
