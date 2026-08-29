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

namespace quark\data\bedrock\block\convert\property;

use quark\block\utils\WallConnectionType;
use quark\data\bedrock\block\BlockStateStringValues;

/**
 * Internally we use null for no connections, but accepting this in the mapping code would require a fair amount of
 * extra complexity for this one case. This shim allows us to use the regular systems for handling walls.
 * TODO: get rid of this in PM6 and make the internal enum have a NONE case
 * @internal
 */
enum WallConnectionTypeShim : string{
	case NONE = BlockStateStringValues::WALL_CONNECTION_TYPE_EAST_NONE;
	case SHORT = BlockStateStringValues::WALL_CONNECTION_TYPE_EAST_SHORT;
	case TALL = BlockStateStringValues::WALL_CONNECTION_TYPE_EAST_TALL;

	public function deserialize() : ?WallConnectionType{
		return match($this){
			self::NONE => null,
			self::SHORT => WallConnectionType::SHORT,
			self::TALL => WallConnectionType::TALL,
		};
	}

	public static function serialize(?WallConnectionType $value) : self{
		return match($value){
			null => self::NONE,
			WallConnectionType::SHORT => self::SHORT,
			WallConnectionType::TALL => self::TALL,
		};
	}
}
