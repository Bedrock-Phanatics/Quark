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

namespace quark\network\mcpe\handler;

enum PacketHandlerAction{
	/**
	 * The packet will be handled normally
	 */
	case HANDLED;
	/**
	 * The packet will be discarded and a debug message logged, usually because the packet wasn't expected
	 */
	case DISCARD_WITH_DEBUG;
	/**
	 * The packet will be discarded silently, usually because it was explicitly marked as discarded
	 */
	case DISCARD_SILENT;
}
