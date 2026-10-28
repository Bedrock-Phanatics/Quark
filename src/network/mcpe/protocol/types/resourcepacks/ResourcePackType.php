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

namespace quark\network\mcpe\protocol\types\resourcepacks;

use quark\network\mcpe\protocol\types\PacketIntEnumTrait;

enum ResourcePackType : int{
	use PacketIntEnumTrait;

	case INVALID = 0;
	case ADDON = 1;
	case CACHED = 2;
	case COPY_PROTECTED = 3;
	case BEHAVIORS = 4;
	case PERSONA_PIECE = 5;
	case RESOURCES = 6;
	case SKINS = 7;
	case WORLD_TEMPLATE = 8;
}
