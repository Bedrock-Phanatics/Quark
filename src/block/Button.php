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

use quark\block\utils\AnyFacing;
use quark\block\utils\AnyFacingTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use quark\world\sound\RedstonePowerOffSound;
use quark\world\sound\RedstonePowerOnSound;

abstract class Button extends Flowable implements AnyFacing{
	use AnyFacingTrait;

	protected bool $pressed = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->pressed);
	}

	public function isPressed() : bool{ return $this->pressed; }

	/** @return $this */
	public function setPressed(bool $pressed) : self{
		$this->pressed = $pressed;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($this->canBeSupportedAt($blockReplace, $face)){
			$this->facing = $face;
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}
		return false;
	}

	abstract protected function getActivationTime() : int;

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$this->pressed){
			$this->pressed = true;
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this);
			$world->scheduleDelayedBlockUpdate($this->position, $this->getActivationTime());
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new RedstonePowerOnSound());
		}

		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->pressed){
			$this->pressed = false;
			$world = $this->position->getWorld();
			$world->setBlock($this->position, $this);
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new RedstonePowerOffSound());
		}
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this, $this->facing)){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	private function canBeSupportedAt(Block $block, int $face) : bool{
		return $block->getAdjacentSupportType(Facing::opposite($face))->hasCenterSupport();
	}
}
