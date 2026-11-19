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
use quark\block\utils\WoodMaterial;
use quark\block\utils\WoodTypeTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\math\Facing;

/**
 * TODO: Implement placing and taking items, and swapping the hotbar with a powered row of up to three shelves.
 */
class Shelf extends Transparent implements HorizontalFacing, WoodMaterial{
	use FacesOppositePlacingPlayerTrait {
		describeBlockOnlyState as describeFacing;
	}
	use WoodTypeTrait;

	public const int DEFAULT_FACING = Facing::SOUTH;

	public const MAX_POWERED_SHELF_TYPE = 3;

	protected bool $powered = false;
	/** Position of this shelf within a powered row, as stored by vanilla. */
	protected int $poweredShelfType = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeFacing($w);
		$w->bool($this->powered);
		$w->boundedIntAuto(0, self::MAX_POWERED_SHELF_TYPE, $this->poweredShelfType);
	}

	public function isPowered() : bool{ return $this->powered; }

	/** @return $this */
	public function setPowered(bool $powered) : self{
		$this->powered = $powered;
		return $this;
	}

	public function getPoweredShelfType() : int{ return $this->poweredShelfType; }

	/** @return $this */
	public function setPoweredShelfType(int $type) : self{
		if($type < 0 || $type > self::MAX_POWERED_SHELF_TYPE){
			throw new \InvalidArgumentException("Powered shelf type must be in range 0 ... " . self::MAX_POWERED_SHELF_TYPE);
		}
		$this->poweredShelfType = $type;
		return $this;
	}

	public function getFuelTime() : int{
		return $this->woodType->isFlammable() ? 300 : 0;
	}

	public function getFlameEncouragement() : int{
		return $this->woodType->isFlammable() ? 30 : 0;
	}

	public function getFlammability() : int{
		return $this->woodType->isFlammable() ? 20 : 0;
	}
}
