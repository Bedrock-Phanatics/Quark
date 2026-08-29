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

namespace quark\block\utils\redstone;

interface PowerSource{

	/**
	 * Returns the power level of this block.
	 */
	public function getPowerLevel() : int;

	/**
	 * Returns the power this block can output to it's
	 * surroundings. It's usually the same as getPowerLevel()
	 * except for wires which "transmit" power, thereby losing
	 * 1 level per block.
	 */
	public function getOutputPowerLevel() : int;

	/**
	 * Returns whether this block can power a Powerable
	 * block located at it's side.
	 */
	public function canPower(int $side) : bool;

	/**
	 * Returns whether this block can strongly power an opaque
	 * block located at it's side.
	 */
	public function canStronglyPower(int $side) : bool;
}
