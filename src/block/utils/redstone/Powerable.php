<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

/**
 * Blocks that change their state when powered.
 * RULE: Powerable blocks should NEVER recalculate power without receiving a
 * Powerable::power() call!
 */
interface Powerable extends Transmittable, Waitable{

	/**
	 * Delay in redstone ticks after which this block
	 * may activate or 0 to try activating instantly.
	 */
	public int $activation_delay{ get; }

	/**
	 * Delay in redstone ticks after which this block
	 * may deactivate or 0 to try deactivating instantly.
	 */
	public int $deactivation_delay{ get; }

	/** Returns whether this block ONLY accepts strong power. */
	public bool $requires_strong_power{ get; }

	/**
	 * Returns whether this block accepts power
	 * from a side.
	 */
	public function acceptsPowerFromSide(int $side) : bool;

	/**
	 * Returns whether this block is powered/activated.
	 */
	public function isPowered() : bool;

	/**
	 * Calculates new power state and does the visual changes if
	 * there needs to be any.
	 */
	public function recalculatePowerState() : void;
}
