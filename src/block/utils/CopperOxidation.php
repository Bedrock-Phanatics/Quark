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

use quark\utils\LegacyEnumShimTrait;

/**
 * TODO: These tags need to be removed once we get rid of LegacyEnumShimTrait (PM6)
 *  These are retained for backwards compatibility only.
 *
 * @method static CopperOxidation EXPOSED()
 * @method static CopperOxidation NONE()
 * @method static CopperOxidation OXIDIZED()
 * @method static CopperOxidation WEATHERED()
 */
enum CopperOxidation : int{
	use LegacyEnumShimTrait;

	case NONE = 0;
	case EXPOSED = 1;
	case WEATHERED = 2;
	case OXIDIZED = 3;

	public function getPrevious() : ?self{
		return self::tryFrom($this->value - 1);
	}

	public function getNext() : ?self{
		return self::tryFrom($this->value + 1);
	}
}
