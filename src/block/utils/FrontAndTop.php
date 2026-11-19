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

use quark\math\Facing;

/**
 * Full orientation of a block that has both a front and a top face, such as the crafter.
 */
enum FrontAndTop{
	case DOWN_EAST;
	case DOWN_NORTH;
	case DOWN_SOUTH;
	case DOWN_WEST;
	case UP_EAST;
	case UP_NORTH;
	case UP_SOUTH;
	case UP_WEST;
	case WEST_UP;
	case EAST_UP;
	case NORTH_UP;
	case SOUTH_UP;

	public function getFront() : int{
		return match($this){
			self::DOWN_EAST, self::DOWN_NORTH, self::DOWN_SOUTH, self::DOWN_WEST => Facing::DOWN,
			self::UP_EAST, self::UP_NORTH, self::UP_SOUTH, self::UP_WEST => Facing::UP,
			self::WEST_UP => Facing::WEST,
			self::EAST_UP => Facing::EAST,
			self::NORTH_UP => Facing::NORTH,
			self::SOUTH_UP => Facing::SOUTH,
		};
	}

	public function getTop() : int{
		return match($this){
			self::DOWN_EAST, self::UP_EAST => Facing::EAST,
			self::DOWN_NORTH, self::UP_NORTH => Facing::NORTH,
			self::DOWN_SOUTH, self::UP_SOUTH => Facing::SOUTH,
			self::DOWN_WEST, self::UP_WEST => Facing::WEST,
			self::WEST_UP, self::EAST_UP, self::NORTH_UP, self::SOUTH_UP => Facing::UP,
		};
	}

	/**
	 * @param int $front any facing
	 * @param int $top   horizontal facing, only used when the front is vertical
	 */
	public static function fromFacing(int $front, int $top) : self{
		return match($front){
			Facing::DOWN, Facing::UP => match($top){
				Facing::EAST => $front === Facing::DOWN ? self::DOWN_EAST : self::UP_EAST,
				Facing::NORTH => $front === Facing::DOWN ? self::DOWN_NORTH : self::UP_NORTH,
				Facing::SOUTH => $front === Facing::DOWN ? self::DOWN_SOUTH : self::UP_SOUTH,
				Facing::WEST => $front === Facing::DOWN ? self::DOWN_WEST : self::UP_WEST,
				default => throw new \InvalidArgumentException("Top must be horizontal when the front is vertical")
			},
			Facing::WEST => self::WEST_UP,
			Facing::EAST => self::EAST_UP,
			Facing::NORTH => self::NORTH_UP,
			Facing::SOUTH => self::SOUTH_UP,
			default => throw new \InvalidArgumentException("Invalid facing $front")
		};
	}
}
