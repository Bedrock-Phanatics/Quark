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

namespace quark\event\block;

use quark\block\tile\BrewingStand;
use quark\event\Cancellable;
use quark\event\CancellableTrait;

/**
 * Called when a brewing stand consumes a new fuel item.
 */
class BrewingFuelUseEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	private int $fuelTime = 20;

	public function __construct(
		private BrewingStand $brewingStand
	){
		parent::__construct($brewingStand->getBlock());
	}

	public function getBrewingStand() : BrewingStand{
		return $this->brewingStand;
	}

	/**
	 * Returns how many times the fuel can be used for potion brewing before it runs out.
	 */
	public function getFuelTime() : int{
		return $this->fuelTime;
	}

	/**
	 * Sets how many times the fuel can be used for potion brewing before it runs out.
	 */
	public function setFuelTime(int $fuelTime) : void{
		if($fuelTime <= 0){
			throw new \InvalidArgumentException("Fuel time must be positive");
		}
		$this->fuelTime = $fuelTime;
	}
}
