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

use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use quark\block\utils\HorizontalConnectable;
use quark\block\utils\StaticSupportTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\item\VanillaItems;
use function mt_rand;

final class ChorusPlant extends Flowable implements HorizontalConnectable{
	use StaticSupportTrait;

	/** @var int[] facing => facing */
	protected array $connections = [];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingFlags($this->connections);
	}

	/**
	 * @param int $facing any of Facing::ALL
	 */
	public function isConnectedAt(int $facing) : bool{
		return isset($this->connections[$facing]);
	}

	/**
	 * @param int $facing any of Facing::ALL
	 */
	public function setConnectedAt(int $facing, bool $connected) : void{
		Facing::validate($facing);
		if($connected){
			$this->connections[$facing] = $facing;
		}else{
			unset($this->connections[$facing]);
		}
	}

	protected function recalculateCollisionBoxes() : array{
		$bb = AxisAlignedBB::one();
		foreach(Facing::ALL as $facing){
			if(!isset($this->connections[$facing])){
				$bb->trim($facing, 2 / 16);
			}
		}

		return [$bb];
	}

	private function recalculateConnections() : bool{
		$changed = false;
		foreach(Facing::ALL as $facing){
			$block = $this->getSide($facing);
			$connected = match($block->getTypeId()){
				BlockTypeIds::CHORUS_FLOWER, $this->getTypeId() => true,
				BlockTypeIds::END_STONE => $facing === Facing::DOWN,
				default => false
			};
			if($connected !== $this->isConnectedAt($facing)){
				$this->setConnectedAt($facing, $connected);
				$changed = true;
			}
		}
		return $changed;
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		//worlds from before 1.26.60 have no stored connections, so derive them on read and save them once
		if($this->recalculateConnections()){
			$this->collisionBoxes = null;
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
		}
		return $this;
	}

	public function onScheduledUpdate() : void{
		$this->position->getWorld()->setBlock($this->position, $this);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	private function canBeSupportedBy(Block $block) : bool{
		return $block->hasSameTypeId($this) || $block->getTypeId() === BlockTypeIds::END_STONE;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$position = $block->position;
		$world = $position->getWorld();

		$down = $world->getBlock($position->down());
		$verticalAir = $down->getTypeId() === BlockTypeIds::AIR || $world->getBlock($position->up())->getTypeId() === BlockTypeIds::AIR;

		foreach($position->sidesAroundAxis(Axis::Y) as $sidePosition){
			$block = $world->getBlock($sidePosition);

			if($block->getTypeId() === BlockTypeIds::CHORUS_PLANT){
				if(!$verticalAir){
					return false;
				}

				if($this->canBeSupportedBy($block->getSide(Facing::DOWN))){
					return true;
				}
			}
		}

		return $this->canBeSupportedBy($down);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if(mt_rand(0, 1) === 1){
			return [VanillaItems::CHORUS_FRUIT()];
		}

		return [];
	}
}
