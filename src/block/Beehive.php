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
 * Used for both beehives and bee nests.
 * TODO: Implement honey and honeycomb harvesting, bee occupants and calming bees with campfire smoke.
 */
class Beehive extends Opaque implements HorizontalFacing{
	use FacesOppositePlacingPlayerTrait {
		describeBlockOnlyState as describeFacing;
	}

	public const int DEFAULT_FACING = Facing::SOUTH;

	public const MAX_HONEY_LEVEL = 5;

	protected int $honeyLevel = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeFacing($w);
		$w->boundedIntAuto(0, self::MAX_HONEY_LEVEL, $this->honeyLevel);
	}

	public function getHoneyLevel() : int{ return $this->honeyLevel; }

	/** @return $this */
	public function setHoneyLevel(int $honeyLevel) : self{
		if($honeyLevel < 0 || $honeyLevel > self::MAX_HONEY_LEVEL){
			throw new \InvalidArgumentException("Honey level must be in range 0 ... " . self::MAX_HONEY_LEVEL);
		}
		$this->honeyLevel = $honeyLevel;
		return $this;
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
