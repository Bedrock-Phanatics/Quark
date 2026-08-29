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
use quark\block\utils\AnalogRedstoneSignalEmitter;
use quark\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;

trait PressurePlateTrait{

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		foreach($this->getRelyingBlocks() as $block){
			$block->power($this);
		}
	}

	protected function recalculateCollisionBoxes() : array{
		return [new AxisAlignedBB(0.0625, 0, 0.0625, 0.9375, $this->hasOutputSignal() ? 0.03125 : 0.0625, 0.9375)];
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		if(parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock)){
			$pos = $blockReplace->getPosition();
			$below = $pos->down();
			return $pos->getWorld()->getBlockAt((int) $below->x, (int) $below->y, (int) $below->z)->isSolid();
		}
		return false;
	}

	public function getPowerLevel() : int{
		return $this instanceof AnalogRedstoneSignalEmitter ? $this->getOutputSignalStrength() : ($this->hasOutputSignal() ? 15 : 0);
	}

	public function getOutputPowerLevel() : int{
		return $this->getPowerLevel();
	}

	public function canPower(int $side) : bool{
		return true;
	}

	public function canStronglyPower(int $side) : bool{
		return $side === Facing::DOWN;
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingOnSupportBlocks() : Generator{
		$supporting_side = $this->getSupportingSide();
		$supporting = RedstoneBlockUtils::getBlockAtSide($this->position, $supporting_side);
		$skip_side = Facing::opposite($supporting_side);
		foreach(Facing::ALL as $side){
			if($side !== $skip_side){
				$block = RedstoneBlockUtils::getBlockAtSide($supporting->position, $side);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingBlocks() : Generator{
		yield from $this->getRelyingOnSupportBlocks();
		$skip_side = $this->getSupportingSide();
		foreach(Facing::HORIZONTAL as $side){
			if($side !== $skip_side){
				$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}
	}

	public function getSupportingSide() : int{
		return Facing::DOWN;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->hasOutputSignal()){
			if($this instanceof AnalogRedstoneSignalEmitter){
				$this->setOutputSignalStrength(0);
			}else{
				$this->setPressed(false);
			}
			foreach($this->getRelyingOnSupportBlocks() as $block){
				$block->power($this);
			}
		}
		return parent::onBreak($item, $player, $returnedItems);
	}
}
