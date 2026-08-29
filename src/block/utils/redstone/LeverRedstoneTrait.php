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
use quark\block\utils\LeverFacing;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\RedstonePowerOffSound;
use quark\world\sound\RedstonePowerOnSound;

trait LeverRedstoneTrait{
	use RedstoneBlockAccessTrait;

	private ?int $redstoneSupportingSide = null;

	public function getPowerLevel() : int{ return $this->activated ? 15 : 0; }
	public function getOutputPowerLevel() : int{ return $this->getPowerLevel(); }
	public function canPower(int $side) : bool{ return true; }
	public function canStronglyPower(int $side) : bool{ return $side === $this->getSupportingSide(); }

	/** @return Generator<Transmittable> */
	private function getRedstoneDependantsOnSupport() : Generator{
		$supportingSide = $this->getSupportingSide();
		$supporting = RedstoneBlockUtils::getBlockAtSide($this->position, $supportingSide);
		$skipSide = Facing::opposite($supportingSide);
		foreach(Facing::ALL as $side){
			if($side !== $skipSide && ($block = RedstoneBlockUtils::getBlockAtSide($supporting->position, $side)) instanceof Transmittable){
				yield $block;
			}
		}
	}

	/** @return Generator<Transmittable> */
	private function getRedstoneDependants() : Generator{
		yield from $this->getRedstoneDependantsOnSupport();
		$skipSide = $this->getSupportingSide();
		foreach(Facing::HORIZONTAL as $side){
			if($side !== $skipSide && ($block = RedstoneBlockUtils::getBlockAtSide($this->position, $side)) instanceof Transmittable){
				yield $block;
			}
		}
	}

	public function setFacing(LeverFacing $facing) : self{
		$this->redstoneSupportingSide = null;
		$this->facing = $facing;
		return $this;
	}

	public function getSupportingSide() : int{
		return $this->redstoneSupportingSide ??= match($this->facing){
			LeverFacing::DOWN_AXIS_X, LeverFacing::DOWN_AXIS_Z => Facing::UP,
			LeverFacing::UP_AXIS_X, LeverFacing::UP_AXIS_Z => Facing::DOWN,
			default => Facing::opposite($this->facing->getFacing())
		};
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->activated){
			$this->activated = false;
			foreach($this->getRedstoneDependantsOnSupport() as $block){ $block->power($this); }
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->activated = !$this->activated;
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this, false);
		$world->addSound($this->position->add(0.5, 0.5, 0.5), $this->activated ? new RedstonePowerOnSound() : new RedstonePowerOffSound());
		foreach($this->getRedstoneDependants() as $block){ $block->power($this); }
		return true;
	}
}
