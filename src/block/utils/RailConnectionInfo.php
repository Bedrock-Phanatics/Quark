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

final class RailConnectionInfo{

	public const FLAG_ASCEND = 1 << 24; //used to indicate direction-up

	/**
	 * Keyed by shape case name, shared by {@link RailShape} and {@link StraightOnlyRailShape}.
	 * A constant so the arrays aren't rebuilt on every lookup.
	 */
	public const CONNECTIONS = [
		'FLAT_AXIS_Z' => [Facing::NORTH, Facing::SOUTH],
		'FLAT_AXIS_X' => [Facing::EAST, Facing::WEST],
		'ASCENDING_EAST' => [Facing::WEST, Facing::EAST | self::FLAG_ASCEND],
		'ASCENDING_WEST' => [Facing::EAST, Facing::WEST | self::FLAG_ASCEND],
		'ASCENDING_NORTH' => [Facing::SOUTH, Facing::NORTH | self::FLAG_ASCEND],
		'ASCENDING_SOUTH' => [Facing::NORTH, Facing::SOUTH | self::FLAG_ASCEND],
		'CURVED_SOUTHEAST' => [Facing::SOUTH, Facing::EAST],
		'CURVED_SOUTHWEST' => [Facing::SOUTH, Facing::WEST],
		'CURVED_NORTHWEST' => [Facing::NORTH, Facing::WEST],
		'CURVED_NORTHEAST' => [Facing::NORTH, Facing::EAST]
	];
}
