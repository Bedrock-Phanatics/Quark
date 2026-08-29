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

namespace quark\event\inventory;

use quark\block\tile\Furnace;
use quark\event\block\BlockEvent;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\item\Item;

/**
 * Called when a furnace is about to consume a new fuel item.
 */
class FurnaceBurnEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	private bool $burning = true;

	public function __construct(
		private Furnace $furnace,
		private Item $fuel,
		private int $burnTime
	){
		parent::__construct($furnace->getBlock());
	}

	public function getFurnace() : Furnace{
		return $this->furnace;
	}

	public function getFuel() : Item{
		return $this->fuel;
	}

	/**
	 * Returns the number of ticks that the furnace will be powered for.
	 */
	public function getBurnTime() : int{
		return $this->burnTime;
	}

	/**
	 * Sets the number of ticks that the given fuel will power the furnace for.
	 */
	public function setBurnTime(int $burnTime) : void{
		$this->burnTime = $burnTime;
	}

	/**
	 * Returns whether the fuel item will be consumed.
	 */
	public function isBurning() : bool{
		return $this->burning;
	}

	/**
	 * Sets whether the fuel will be consumed. If false, the furnace will smelt as if it consumed fuel, but no fuel
	 * will be deducted.
	 */
	public function setBurning(bool $burning) : void{
		$this->burning = $burning;
	}
}
