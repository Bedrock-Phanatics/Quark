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

namespace quark\item;

/**
 * TODO: Implement placing boats on water, riding them and the chest boat inventory.
 */
class Boat extends Item{

	public function __construct(
		ItemIdentifier $identifier,
		string $name,
		private BoatType $boatType,
		private bool $withChest = false
	){
		parent::__construct($identifier, $name);
	}

	public function getType() : BoatType{
		return $this->boatType;
	}

	public function hasChest() : bool{
		return $this->withChest;
	}

	public function getFuelTime() : int{
		return 1200; //400 in PC
	}

	public function getMaxStackSize() : int{
		return 1;
	}
}
