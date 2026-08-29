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
 * @method static LeavesType ACACIA()
 * @method static LeavesType AZALEA()
 * @method static LeavesType BIRCH()
 * @method static LeavesType CHERRY()
 * @method static LeavesType DARK_OAK()
 * @method static LeavesType FLOWERING_AZALEA()
 * @method static LeavesType JUNGLE()
 * @method static LeavesType MANGROVE()
 * @method static LeavesType OAK()
 * @method static LeavesType SPRUCE()
 */
enum LeavesType{
	use LegacyEnumShimTrait;

	case OAK;
	case SPRUCE;
	case BIRCH;
	case JUNGLE;
	case ACACIA;
	case DARK_OAK;
	case MANGROVE;
	case AZALEA;
	case FLOWERING_AZALEA;
	case CHERRY;
	case PALE_OAK;

	public function getDisplayName() : string{
		return match($this){
			self::OAK => "Oak",
			self::SPRUCE => "Spruce",
			self::BIRCH => "Birch",
			self::JUNGLE => "Jungle",
			self::ACACIA => "Acacia",
			self::DARK_OAK => "Dark Oak",
			self::MANGROVE => "Mangrove",
			self::AZALEA => "Azalea",
			self::FLOWERING_AZALEA => "Flowering Azalea",
			self::CHERRY => "Cherry",
			self::PALE_OAK => "Pale Oak",
		};
	}
}
