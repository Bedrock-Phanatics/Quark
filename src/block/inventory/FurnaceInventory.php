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

namespace quark\block\inventory;

use quark\crafting\FurnaceType;
use quark\inventory\SimpleInventory;
use quark\item\Item;
use quark\world\Position;

class FurnaceInventory extends SimpleInventory implements BlockInventory{
	use BlockInventoryTrait;

	public const SLOT_INPUT = 0;
	public const SLOT_FUEL = 1;
	public const SLOT_RESULT = 2;

	public function __construct(
		Position $holder,
		private FurnaceType $furnaceType
	){
		$this->holder = $holder;
		parent::__construct(3);
	}

	public function getFurnaceType() : FurnaceType{ return $this->furnaceType; }

	public function getResult() : Item{
		return $this->getItem(self::SLOT_RESULT);
	}

	public function getFuel() : Item{
		return $this->getItem(self::SLOT_FUEL);
	}

	public function getSmelting() : Item{
		return $this->getItem(self::SLOT_INPUT);
	}

	public function setResult(Item $item) : void{
		$this->setItem(self::SLOT_RESULT, $item);
	}

	public function setFuel(Item $item) : void{
		$this->setItem(self::SLOT_FUEL, $item);
	}

	public function setSmelting(Item $item) : void{
		$this->setItem(self::SLOT_INPUT, $item);
	}
}
