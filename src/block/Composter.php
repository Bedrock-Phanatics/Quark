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
use quark\data\runtime\RuntimeDataDescriber;
use quark\math\AxisAlignedBB;
use quark\math\Facing;

/**
 * Fill level 8 means bone meal is ready to be collected.
 * TODO: Implement composting items, collecting bone meal and hopper interaction.
 */
class Composter extends Transparent{
	public const MAX_FILL_LEVEL = 8;

	protected int $fillLevel = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_FILL_LEVEL, $this->fillLevel);
	}

	public function getFillLevel() : int{ return $this->fillLevel; }

	/** @return $this */
	public function setFillLevel(int $fillLevel) : self{
		if($fillLevel < 0 || $fillLevel > self::MAX_FILL_LEVEL){
			throw new \InvalidArgumentException("Fill level must be in range 0 ... " . self::MAX_FILL_LEVEL);
		}
		$this->fillLevel = $fillLevel;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$wall = 2 / 16;
		return [
			AxisAlignedBB::one()->trim(Facing::UP, 1 - $wall),
			AxisAlignedBB::one()->trim(Facing::EAST, 1 - $wall),
			AxisAlignedBB::one()->trim(Facing::WEST, 1 - $wall),
			AxisAlignedBB::one()->trim(Facing::NORTH, 1 - $wall),
			AxisAlignedBB::one()->trim(Facing::SOUTH, 1 - $wall),
		];
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::DOWN ? SupportType::FULL : SupportType::NONE;
	}

	public function getFlameEncouragement() : int{
		return 5;
	}

	public function getFlammability() : int{
		return 20;
	}

	public function getFuelTime() : int{
		return 300;
	}
}
