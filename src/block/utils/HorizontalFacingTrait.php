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

namespace quark\block\utils;

use quark\data\runtime\RuntimeDataDescriber;
use pocketmine\math\Axis;
use pocketmine\math\Facing;

trait HorizontalFacingTrait{
	protected int $facing = Facing::NORTH;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
	}

	public function getFacing() : int{ return $this->facing; }

	/** @return $this */
	public function setFacing(int $facing) : self{
		$axis = Facing::axis($facing);
		if($axis !== Axis::X && $axis !== Axis::Z){
			throw new \InvalidArgumentException("Facing must be horizontal");
		}
		$this->facing = $facing;
		return $this;
	}
}
