<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

interface PowerSource{

	/**
	 * Returns the power level of this block.
	 *
	 * @return int
	 */
	public function getPowerLevel() : int;

	/**
	 * Returns the power this block can output to it's
	 * surroundings. It's usually the same as getPowerLevel()
	 * except for wires which "transmit" power, thereby losing
	 * 1 level per block.
	 *
	 * @return int
	 */
	public function getOutputPowerLevel() : int;

	/**
	 * Returns whether this block can power a Powerable
	 * block located at it's side.
	 *
	 * @param int $side
	 * @return bool
	 */
	public function canPower(int $side) : bool;

	/**
	 * Returns whether this block can strongly power an opaque
	 * block located at it's side.
	 *
	 * @param int $side
	 * @return bool
	 */
	public function canStronglyPower(int $side) : bool;
}