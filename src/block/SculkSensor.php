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
use quark\item\Item;
use quark\math\AxisAlignedBB;
use quark\math\Facing;

/**
 * Phase 0 is inactive, 1 is active and 2 is cooldown.
 * TODO: Implement sculk vibration detection and propagation, and the redstone output of active sensors.
 */
class SculkSensor extends Transparent{
	public const MAX_PHASE = 2;

	protected int $phase = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_PHASE, $this->phase);
	}

	public function getPhase() : int{ return $this->phase; }

	/** @return $this */
	public function setPhase(int $phase) : self{
		if($phase < 0 || $phase > self::MAX_PHASE){
			throw new \InvalidArgumentException("Phase must be in range 0 ... " . self::MAX_PHASE);
		}
		$this->phase = $phase;
		return $this;
	}

	public function getLightLevel() : int{
		return 1;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 0.5)];
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::DOWN ? SupportType::FULL : SupportType::NONE;
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	protected function getXpDropAmount() : int{
		return 5;
	}
}
