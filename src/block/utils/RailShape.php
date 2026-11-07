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

enum RailShape{
	case FLAT_AXIS_Z;
	case FLAT_AXIS_X;
	case ASCENDING_EAST;
	case ASCENDING_WEST;
	case ASCENDING_NORTH;
	case ASCENDING_SOUTH;

	case CURVED_SOUTHEAST;
	case CURVED_SOUTHWEST;
	case CURVED_NORTHWEST;
	case CURVED_NORTHEAST;

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function getConnections() : array{
		return match($this){
			self::FLAT_AXIS_Z => [Facing::NORTH, Facing::SOUTH],
			self::FLAT_AXIS_X => [Facing::EAST, Facing::WEST],
			self::ASCENDING_EAST => [Facing::WEST, Facing::EAST | RailConnectionInfo::FLAG_ASCEND],
			self::ASCENDING_WEST => [Facing::EAST, Facing::WEST | RailConnectionInfo::FLAG_ASCEND],
			self::ASCENDING_NORTH => [Facing::SOUTH, Facing::NORTH | RailConnectionInfo::FLAG_ASCEND],
			self::ASCENDING_SOUTH => [Facing::NORTH, Facing::SOUTH | RailConnectionInfo::FLAG_ASCEND],
			self::CURVED_SOUTHEAST => [Facing::SOUTH, Facing::EAST],
			self::CURVED_SOUTHWEST => [Facing::SOUTH, Facing::WEST],
			self::CURVED_NORTHWEST => [Facing::NORTH, Facing::WEST],
			self::CURVED_NORTHEAST => [Facing::NORTH, Facing::EAST]
		};
	}
}
