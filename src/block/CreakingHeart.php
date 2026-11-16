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

use quark\block\utils\CreakingHeartState;
use quark\block\utils\PillarRotation;
use quark\block\utils\PillarRotationTrait;
use quark\data\runtime\RuntimeDataDescriber;

/**
 * Hearts placed by players are not natural and never spawn a creaking.
 * TODO: Implement waking at night between pale oak logs, spawning and protecting the creaking, and resin drops.
 */
class CreakingHeart extends Opaque implements PillarRotation{
	use PillarRotationTrait {
		describeBlockOnlyState as describeAxis;
	}

	protected CreakingHeartState $state = CreakingHeartState::UPROOTED;
	protected bool $natural = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeAxis($w);
		$w->enum($this->state);
		$w->bool($this->natural);
	}

	public function getState() : CreakingHeartState{ return $this->state; }

	/** @return $this */
	public function setState(CreakingHeartState $state) : self{
		$this->state = $state;
		return $this;
	}

	public function isNatural() : bool{ return $this->natural; }

	/** @return $this */
	public function setNatural(bool $natural) : self{
		$this->natural = $natural;
		return $this;
	}
}
