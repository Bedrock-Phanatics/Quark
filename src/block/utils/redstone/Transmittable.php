<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

/**
 * Blocks that need to receive power to give out power or
 * fuck with input power to change the value of output
 * power.
 * These are almost always PowerSource as well.
 */
interface Transmittable{

	/**
	 * Called by a PowerSource when it's power level changes so
	 * this block can recalculate it's power state.
	 *
	 * @param PowerSource $source
	 */
	public function power(PowerSource $source) : void;
}