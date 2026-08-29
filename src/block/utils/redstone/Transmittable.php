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
	 */
	public function power(PowerSource $source) : void;
}
