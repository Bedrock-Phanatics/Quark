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

namespace quark\block;

use quark\block\utils\StaticSupportTrait;
use pocketmine\math\Facing;

final class NetherRoots extends Flowable{
	use StaticSupportTrait;

	private function canBeSupportedAt(Block $block) : bool{
		//TODO: moss
		$supportBlock = $block->getSide(Facing::DOWN);
		return
			$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
			$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
			$supportBlock->hasTypeTag(BlockTypeTags::NYLIUM) ||
			$supportBlock->getTypeId() === BlockTypeIds::SOUL_SOIL;
	}
}
