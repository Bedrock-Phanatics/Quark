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
 * Shriekers placed by players can't summon wardens; only generated ones can.
 * TODO: Implement shrieking on vibrations, darkness and warden summoning.
 */
class SculkShrieker extends Transparent{
	protected bool $active = false;
	protected bool $canSummon = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->active);
		$w->bool($this->canSummon);
	}

	public function isActive() : bool{ return $this->active; }

	/** @return $this */
	public function setActive(bool $active) : self{
		$this->active = $active;
		return $this;
	}

	public function canSummon() : bool{ return $this->canSummon; }

	/** @return $this */
	public function setCanSummon(bool $canSummon) : self{
		$this->canSummon = $canSummon;
		return $this;
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
