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
 * @method static WoodType ACACIA()
 * @method static WoodType BIRCH()
 * @method static WoodType CHERRY()
 * @method static WoodType CRIMSON()
 * @method static WoodType DARK_OAK()
 * @method static WoodType JUNGLE()
 * @method static WoodType MANGROVE()
 * @method static WoodType OAK()
 * @method static WoodType SPRUCE()
 * @method static WoodType WARPED()
 */
enum WoodType{
	use LegacyEnumShimTrait;

	case OAK;
	case SPRUCE;
	case BIRCH;
	case JUNGLE;
	case ACACIA;
	case DARK_OAK;
	case MANGROVE;
	case CRIMSON;
	case WARPED;
	case CHERRY;
	case PALE_OAK;
	case BAMBOO;

	public function getDisplayName() : string{
		return match($this){
			self::OAK => "Oak",
			self::SPRUCE => "Spruce",
			self::BIRCH => "Birch",
			self::JUNGLE => "Jungle",
			self::ACACIA => "Acacia",
			self::DARK_OAK => "Dark Oak",
			self::MANGROVE => "Mangrove",
			self::CRIMSON => "Crimson",
			self::WARPED => "Warped",
			self::CHERRY => "Cherry",
			self::PALE_OAK => "Pale Oak",
			self::BAMBOO => "Bamboo",
		};
	}

	public function isFlammable() : bool{
		return $this !== self::CRIMSON && $this !== self::WARPED;
	}

	public function getStandardLogSuffix() : ?string{
		return match($this){
			self::CRIMSON, self::WARPED => "Stem",
			self::BAMBOO => "Block",
			default => null,
		};
	}

	public function getAllSidedLogSuffix() : ?string{
		return $this === self::CRIMSON || $this === self::WARPED ? "Hyphae" : null;
	}
}
