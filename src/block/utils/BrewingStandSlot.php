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

namespace quark\block\utils;

use quark\block\inventory\BrewingStandInventory;
use quark\utils\LegacyEnumShimTrait;

/**
 * TODO: These tags need to be removed once we get rid of LegacyEnumShimTrait (PM6)
 *  These are retained for backwards compatibility only.
 *
 * @method static BrewingStandSlot EAST()
 * @method static BrewingStandSlot NORTHWEST()
 * @method static BrewingStandSlot SOUTHWEST()
 */
enum BrewingStandSlot{
	use LegacyEnumShimTrait;

	case EAST;
	case NORTHWEST;
	case SOUTHWEST;

	/**
	 * Returns the brewing stand inventory slot number associated with this visual slot.
	 */
	public function getSlotNumber() : int{
		return match($this){
			self::EAST => BrewingStandInventory::SLOT_BOTTLE_LEFT,
			self::NORTHWEST => BrewingStandInventory::SLOT_BOTTLE_MIDDLE,
			self::SOUTHWEST => BrewingStandInventory::SLOT_BOTTLE_RIGHT
		};
	}
}
