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

namespace quark\block\utils\redstone;

use quark\world\redstone\BlockData;

final class RedstoneRepeaterBlockData implements BlockData{

	public const int OPERATION_SWITCH_ON = 0;
	public const int OPERATION_DISTRIBUTE = 1;
	public const int OPERATION_SWITCH_RECALCULATE = 2;

	/**
	 * @param self::OPERATION_* $operation
	 */
	public function __construct(
		public int $operation
	){}
}
