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

use pocketmine\math\Facing;
use quark\utils\LegacyEnumShimTrait;

/**
 * TODO: These tags need to be removed once we get rid of LegacyEnumShimTrait (PM6)
 *  These are retained for backwards compatibility only.
 *
 * @method static LeverFacing DOWN_AXIS_X()
 * @method static LeverFacing DOWN_AXIS_Z()
 * @method static LeverFacing EAST()
 * @method static LeverFacing NORTH()
 * @method static LeverFacing SOUTH()
 * @method static LeverFacing UP_AXIS_X()
 * @method static LeverFacing UP_AXIS_Z()
 * @method static LeverFacing WEST()
 */
enum LeverFacing{
	use LegacyEnumShimTrait;

	case UP_AXIS_X;
	case UP_AXIS_Z;
	case DOWN_AXIS_X;
	case DOWN_AXIS_Z;
	case NORTH;
	case EAST;
	case SOUTH;
	case WEST;

	public function getFacing() : int{
		return match($this){
			self::UP_AXIS_X, self::UP_AXIS_Z => Facing::UP,
			self::DOWN_AXIS_X, self::DOWN_AXIS_Z => Facing::DOWN,
			self::NORTH => Facing::NORTH,
			self::EAST => Facing::EAST,
			self::SOUTH => Facing::SOUTH,
			self::WEST => Facing::WEST,
		};
	}
}
