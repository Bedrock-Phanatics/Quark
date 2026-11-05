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

enum DripleafState{
	case STABLE;
	case UNSTABLE;
	case PARTIAL_TILT;
	case FULL_TILT;

	public function getScheduledUpdateDelayTicks() : ?int{
		return match($this){
			self::STABLE => null,
			self::UNSTABLE, self::PARTIAL_TILT => 10,
			self::FULL_TILT => 100,
		};
	}
}
