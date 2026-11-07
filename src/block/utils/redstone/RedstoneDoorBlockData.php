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

use quark\block\Block;
use quark\block\Door;
use quark\world\redstone\BlockData;

/**
 * Marks a powered door, stored at its bottom half. Bedrock doors have no powered state of their own,
 * and without it every update would close doors that players opened by hand.
 */
final class RedstoneDoorBlockData implements BlockData{

	public function isValidFor(Block $block) : bool{
		return $block instanceof Door;
	}
}
