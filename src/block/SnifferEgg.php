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

use quark\block\utils\CrackedState;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\math\AxisAlignedBB;

/**
 * TODO: Implement cracking over time (faster on moss) and hatching into a snifflet.
 */
class SnifferEgg extends Transparent{
	protected CrackedState $crackedState = CrackedState::NO_CRACKS;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->crackedState);
	}

	public function getCrackedState() : CrackedState{ return $this->crackedState; }

	/** @return $this */
	public function setCrackedState(CrackedState $crackedState) : self{
		$this->crackedState = $crackedState;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(1 / 16, 0, 2 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
