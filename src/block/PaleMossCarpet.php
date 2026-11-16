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

use quark\block\utils\StaticSupportTrait;
use quark\block\utils\WallConnectionType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\math\AxisAlignedBB;
use quark\math\Facing;

/**
 * The top part only exists above a bottom carpet, holding the sides that grew onto walls.
 * Sides reuse the wall connection type, as vanilla stores the same none/short/tall values.
 * TODO: Implement growing sides onto adjacent walls and bone meal.
 */
class PaleMossCarpet extends Flowable{
	use StaticSupportTrait;

	protected bool $top = false;
	/**
	 * @var WallConnectionType[]
	 * @phpstan-var array<Facing::NORTH|Facing::EAST|Facing::SOUTH|Facing::WEST, WallConnectionType>
	 */
	protected array $sides = [];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->top);
		$w->wallConnections($this->sides);
	}

	public function isTop() : bool{ return $this->top; }

	/** @return $this */
	public function setTop(bool $top) : self{
		$this->top = $top;
		return $this;
	}

	public function getSideConnection(int $face) : ?WallConnectionType{
		return $this->sides[$face] ?? null;
	}

	/** @return $this */
	public function setSideConnection(int $face, ?WallConnectionType $type) : self{
		if($face !== Facing::NORTH && $face !== Facing::SOUTH && $face !== Facing::WEST && $face !== Facing::EAST){
			throw new \InvalidArgumentException("Facing can only be north, east, south or west");
		}
		if($type !== null){
			$this->sides[$face] = $type;
		}else{
			unset($this->sides[$face]);
		}
		return $this;
	}

	public function isSolid() : bool{
		return !$this->top;
	}

	protected function recalculateCollisionBoxes() : array{
		return $this->top ? [] : [AxisAlignedBB::one()->trim(Facing::UP, 15 / 16)];
	}

	public function getFlameEncouragement() : int{
		return 15;
	}

	public function getFlammability() : int{
		return 100;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$down = $block->getSide(Facing::DOWN);
		return $this->top ? $down instanceof PaleMossCarpet && !$down->top : $down->getTypeId() !== BlockTypeIds::AIR;
	}
}
