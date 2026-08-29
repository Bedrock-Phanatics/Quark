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
 * @method static SupportType CENTER()
 * @method static SupportType EDGE()
 * @method static SupportType FULL()
 * @method static SupportType NONE()
 */
enum SupportType{
	use LegacyEnumShimTrait;

	case FULL;
	case CENTER;
	case EDGE;
	case NONE;

	public function hasEdgeSupport() : bool{
		return $this === self::EDGE || $this === self::FULL;
	}

	public function hasCenterSupport() : bool{
		return $this === self::CENTER || $this === self::FULL;
	}
}
