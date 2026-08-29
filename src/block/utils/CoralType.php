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
 * @method static CoralType BRAIN()
 * @method static CoralType BUBBLE()
 * @method static CoralType FIRE()
 * @method static CoralType HORN()
 * @method static CoralType TUBE()
 */
enum CoralType{
	use LegacyEnumShimTrait;

	case TUBE;
	case BRAIN;
	case BUBBLE;
	case FIRE;
	case HORN;

	public function getDisplayName() : string{
		return match($this){
			self::TUBE => "Tube",
			self::BRAIN => "Brain",
			self::BUBBLE => "Bubble",
			self::FIRE => "Fire",
			self::HORN => "Horn",
		};
	}
}
