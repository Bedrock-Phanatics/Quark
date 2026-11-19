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
 * Stability is the horizontal distance from the nearest supported scaffolding column.
 * TODO: Implement stability updates, falling when unsupported, climbing and placing scaffolding by clicking it.
 */
class Scaffolding extends Transparent{
	public const MAX_STABILITY = 7;

	protected int $stability = 0;
	protected bool $stabilityCheck = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_STABILITY, $this->stability);
		$w->bool($this->stabilityCheck);
	}

	public function getStability() : int{ return $this->stability; }

	/** @return $this */
	public function setStability(int $stability) : self{
		if($stability < 0 || $stability > self::MAX_STABILITY){
			throw new \InvalidArgumentException("Stability must be in range 0 ... " . self::MAX_STABILITY);
		}
		$this->stability = $stability;
		return $this;
	}

	public function getStabilityCheck() : bool{ return $this->stabilityCheck; }

	/** @return $this */
	public function setStabilityCheck(bool $stabilityCheck) : self{
		$this->stabilityCheck = $stabilityCheck;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::DOWN, 14 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::UP ? SupportType::CENTER : SupportType::NONE;
	}

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 20;
	}

	public function getFuelTime() : int{
		return 50;
	}
}
