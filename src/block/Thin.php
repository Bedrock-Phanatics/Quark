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

use quark\block\utils\SupportType;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use function count;

/**
 * Thin blocks behave like glass panes. They connect to full-cube blocks horizontally adjacent to them if possible.
 */
class Thin extends Transparent{
	/** @var bool[] facing => dummy */
	protected array $connections = [];

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();

		$this->collisionBoxes = null;

		foreach(Facing::HORIZONTAL as $facing){
			$side = $this->getSide($facing);
			if($side instanceof Thin || $side instanceof Wall || $side->getSupportType(Facing::opposite($facing)) === SupportType::FULL){
				$this->connections[$facing] = true;
			}else{
				unset($this->connections[$facing]);
			}
		}

		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$inset = 7 / 16;

		$bbs = [];

		if(isset($this->connections[Facing::WEST]) || isset($this->connections[Facing::EAST])){
			$bb = AxisAlignedBB::one()->squash(Axis::Z, $inset);

			if(!isset($this->connections[Facing::WEST])){
				$bb->trim(Facing::WEST, $inset);
			}elseif(!isset($this->connections[Facing::EAST])){
				$bb->trim(Facing::EAST, $inset);
			}
			$bbs[] = $bb;
		}

		if(isset($this->connections[Facing::NORTH]) || isset($this->connections[Facing::SOUTH])){
			$bb = AxisAlignedBB::one()->squash(Axis::X, $inset);

			if(!isset($this->connections[Facing::NORTH])){
				$bb->trim(Facing::NORTH, $inset);
			}elseif(!isset($this->connections[Facing::SOUTH])){
				$bb->trim(Facing::SOUTH, $inset);
			}
			$bbs[] = $bb;
		}

		if(count($bbs) === 0){
			//centre post AABB (only needed if not connected on any axis - other BBs overlapping will do this if any connections are made)
			return [
				AxisAlignedBB::one()->contract($inset, 0, $inset)
			];
		}

		return $bbs;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
