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

use quark\block\utils\FacesOppositePlacingPlayerTrait;
use quark\block\utils\HorizontalFacing;
use quark\data\runtime\RuntimeDataDescriber;
use quark\math\Facing;

/**
 * TODO: Implement filtering vibrations by the comparator signal fed into the facing side.
 */
class CalibratedSculkSensor extends SculkSensor implements HorizontalFacing{
	use FacesOppositePlacingPlayerTrait {
		describeBlockOnlyState as describeFacing;
	}

	public const int DEFAULT_FACING = Facing::SOUTH;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$this->describeFacing($w);
	}
}
