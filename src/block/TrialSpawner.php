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

use quark\block\utils\TrialSpawnerState;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;

/**
 * TODO: Implement trial spawner activation, mob spawning and rewards.
 */
class TrialSpawner extends Transparent{
	protected TrialSpawnerState $state = TrialSpawnerState::INACTIVE;
	protected bool $ominous = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->state);
		$w->bool($this->ominous);
	}

	public function getState() : TrialSpawnerState{ return $this->state; }

	/** @return $this */
	public function setState(TrialSpawnerState $state) : self{
		$this->state = $state;
		return $this;
	}

	public function isOminous() : bool{ return $this->ominous; }

	/** @return $this */
	public function setOminous(bool $ominous) : self{
		$this->ominous = $ominous;
		return $this;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}
}
