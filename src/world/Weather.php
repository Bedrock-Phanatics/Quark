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

namespace quark\world;

use function mt_rand;

enum Weather{
	case CLEAR;
	case RAIN;
	case THUNDER;

	/**
	 * Returns a vanilla-like random duration in ticks for this weather.
	 */
	public function randomDuration() : int{
		return match($this){
			self::CLEAR => mt_rand(12000, 180000),
			self::RAIN => mt_rand(12000, 24000),
			self::THUNDER => mt_rand(3600, 15600),
		};
	}
}
